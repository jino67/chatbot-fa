# Notifications et e-mails

Ce document décrit comment la plateforme prévient ses utilisateurs : notifications sur les téléphones (Web Push) avec le compteur sur l'icône de l'application, centre de notifications dans l'application, e-mails aux couleurs de la marque, et envois de l'équipe (promotions, nouveautés, messages importants).

## 1. Vue d'ensemble

```
événement (commande, paiement, échéance...)  ou  campagne de l'équipe
        │
        ▼
App\Notify\Events / CampaignSender ──> App\Notify\Notifier  (un seul point d'entrée)
        │                                   │ respecte : catégories refusées, heures calmes, un message promo par jour, doublons
        │                                   ├─> centre de notifications  (table app_notifications : cloche, compteur)
        │                                   ├─> Web Push  (PushGateway ──> WebPushGateway ──> Google / Apple / Mozilla)
        │                                   └─> e-mail de marque  (Notice)  quand l'événement l'exige
        ▼
téléphone : notification + compteur sur l'icône (Badging API) ; un clic ouvre /notifications/{id}/ouvrir
```

Un échec d'envoi sur un téléphone ne perd jamais le message : il reste dans le centre de notifications.

## 2. Ce que reçoit chaque personne

| Catégorie | Exemples | Peut être refusée ? | Urgente (part la nuit) ? |
|---|---|---|---|
| `leads` | commande, rendez-vous, devis à confirmer | oui | oui |
| `handoffs` | un client veut une personne, ou écrit pendant qu'une personne a la main | oui | oui |
| `account` | échéance, paiement reçu, WhatsApp activé, volume atteint | oui | non |
| `system` | information de service, messages importants de l'équipe, bienvenue | **non** | non |
| `promo` | nouveautés et offres | oui | non : respecte les heures calmes |

Réglages personnels (page **Préférences de notifications**, `/notifications/preferences`) : une case par catégorie, le téléphone en bloc, les heures calmes (21 h à 7 h par défaut, heure de la plateforme), la liste des appareils (retrait d'un appareil).

Règles :
- une catégorie refusée n'écrit rien, ni dans le centre ni sur le téléphone ;
- **un seul message « promo » par personne et par 20 heures** (`notifications.promo_cap_hours`) ;
- un message promo qui arrive pendant les heures calmes est **retardé** jusqu'à la fin de celles-ci (`app_notifications.push_after`) : il est dans le centre tout de suite, et part sur le téléphone le matin ;
- une notification de même `tag` dans la fenêtre `dedupe_minutes` n'est pas répétée (un client qui écrit trois phrases de suite ne déclenche qu'une alerte toutes les cinq minutes) ; sur le téléphone, un même tag **remplace** la notification précédente (le rappel d'une demande remplace son alerte).

## 3. Web Push : comment ça marche ici

- **Sans bibliothèque** : `app/Push/WebPushCrypto.php` implémente le chiffrement du message (RFC 8291, `aes128gcm`) et la signature VAPID (RFC 8292, JWT ES256) avec OpenSSL. Il est validé contre l'exemple publié dans l'annexe A de la RFC 8291 (`PushCryptoTest`). Raison : sur un hébergement sans accès SSH, ajouter un paquet à `vendor/` oblige à téléverser des milliers de fichiers.
- **Clés VAPID** : créées automatiquement à la première utilisation (`Vapid::ensure`), gardées dans les paramètres de la plateforme (clé privée chiffrée avec APP_KEY, jamais affichée, jamais dans un fichier). `php artisan push:keys` affiche leur état ; `--renew` les remplace (tous les appareils devront se réabonner : à ne faire qu'en cas de fuite).
- **Service worker** (`public/sw.js`) : à chaque message reçu il affiche une notification (exigence des navigateurs), met à jour le **compteur de l'icône** (`navigator.setAppBadge`) avec le nombre de non lues envoyé par le serveur, et prévient les fenêtres ouvertes. Un clic mène à `/notifications/{id}/ouvrir`, qui marque la notification comme lue puis ouvre sa page.
- **Abonnement** (`resources/js/push.js`) : jamais sans un geste de la personne (bouton « Activer les notifications »). Ensuite l'abonnement se maintient seul : à chaque ouverture, si la permission est donnée, il est recréé ou rattaché à la personne connectée. À la **déconnexion**, l'appareil est détaché du compte (un téléphone partagé ne reçoit plus les alertes de la personne partie).
- **Sécurité des abonnements** : l'adresse d'un abonnement vient du navigateur. `PushEndpoint::isAllowed` n'accepte que les vrais services de notification (liste `notifications.push.hosts`), en HTTPS, port 443, sans identifiants : sinon n'importe qui pourrait faire envoyer des requêtes depuis le serveur (comme `SafeUrl` pour le crawler). Dix appareils au plus par personne.
- **Réponses des services** : 404 et 410 (appareil disparu) retirent l'abonnement ; 429 et 5xx comptent un échec ; cinq échecs de suite retirent l'appareil.
- **Compatibilité** : Android (Chrome, Edge, Firefox, Samsung Internet), ordinateur (Chrome, Edge, Firefox), iPhone et iPad **seulement depuis l'application installée** (iOS 16.4 et plus). Le compteur sur l'icône est pris en charge par les applications installées sur Android (Chrome), ordinateur et iOS 16.4 et plus ; sinon, le compteur de la cloche dans l'application fait foi.
- **HTTPS obligatoire** (sauf `localhost`) : c'est une exigence des navigateurs.

## 4. Rappels d'activation

La carte `x-push-card` (tableau de bord, page Notifications, profil) adapte son message à l'appareil :

| État | Message |
|---|---|
| jamais demandé | présentation et bouton **Activer les notifications** |
| iPhone sans l'application installée | étapes d'installation (Partager, Sur l'écran d'accueil) |
| **bloquées** | comment les débloquer, selon Android, iPhone ou ordinateur, et bouton « J'ai débloqué, vérifier » |
| permission donnée sans abonnement | bouton « Terminer l'activation » |
| actives | essai, désactivation, lien vers les préférences |
| navigateur sans notifications | conseil de navigateur ; les alertes restent par e-mail |

Sur le tableau de bord, la variante « rappel » ne s'affiche que s'il y a quelque chose à faire, avec **« Plus tard »** : 7 jours (14 jours quand elles sont bloquées), et les rappels s'arrêtent après 4 refus (`notifications.reminder`). L'état est gardé dans le navigateur ; la personne peut toujours tout régler depuis son profil.

## 5. Notifications automatiques

Toutes passent par `Notifier` ; les textes sont écrits une seule fois dans `App\Notify\Events` (e-mail compris).

| Événement | Destinataires | Canaux |
|---|---|---|
| commande, rendez-vous, devis, demande d'une personne | l'espace (équipe du client) | téléphone, centre, e-mail, WhatsApp (page Alertes) |
| un client écrit pendant qu'une personne a la main | l'espace | téléphone, centre (une alerte toutes les 5 minutes par conversation) |
| abonnement bientôt échu, échu, terminé ; essai bientôt fini, fini | l'espace ; e-mail au propriétaire | centre, téléphone, e-mail |
| paiement enregistré (reçu) | l'espace ; e-mail au propriétaire | centre, téléphone, e-mail |
| WhatsApp activé | l'espace ; e-mail au propriétaire | centre, téléphone, e-mail |
| volume WhatsApp à 90 %, atteint | l'espace ; e-mail au propriétaire | centre, téléphone, e-mail |
| bienvenue | la personne qui s'inscrit | centre, e-mail |
| demande d'offre, d'option, d'activation WhatsApp ; solde Twilio bas | l'équipe de la plateforme | téléphone, centre, e-mail à l'adresse privée de réception des alertes (Paramètres, `team.alert_email`, sinon `PLATFORM_ADMIN_EMAIL`, sinon l'e-mail de contact) |

La page **Alertes** du client choisit les canaux des demandes ; la case « Notification sur le téléphone » est cochée par défaut (elle ne fait rien tant qu'aucun appareil n'est abonné).

## 6. Envois de l'équipe (Administration, Notifications)

Réservé à l'équipe (administrateurs et super administrateurs) ; chaque envoi est consigné dans le journal d'audit.

1. **Écrire** : titre (65 caractères), message (178), lien (une page du site, ou un lien `https://`), type : *promotion ou nouveauté* (respecte les choix des clients) ou *message important* (reçu par tous, pour une vraie urgence), copie par e-mail facultative.
2. **Choisir l'audience** : tous les clients, une ou plusieurs offres, des entreprises, ou les clients absents depuis N jours (d'après la mesure d'audience). Le nombre de personnes et d'appareils touchés s'affiche en direct. Seuls les clients actifs d'espaces non suspendus sont visés ; l'équipe ne l'est jamais.
3. **Vérifier** : aperçu en direct de la notification sur un téléphone ; **« M'envoyer un essai »** l'envoie sur ses propres appareils (sans compter comme une campagne).
4. **Envoyer maintenant** ou **programmer** (date et heure, heure de la plateforme), ou enregistrer en brouillon. La touche Entrée ne déclenche jamais un envoi.
5. **Suivre** : personnes visées, reçues (centre), envoyées sur téléphone, échecs, écartées (refus, plafond), retardées (heures calmes), **ouvertes** (le message a été lu ou touché).

**Envoi par morceaux** : un hébergement mutualisé limite la durée d'une requête. `CampaignSender` envoie quelques dizaines de personnes à la fois et retient où il s'est arrêté (`cursor_user_id`) ; la commande planifiée `notifications:dispatch` (chaque minute, ou à chaque passage du cron de l'hébergeur) reprend, programme les campagnes dont l'heure est venue et pousse les messages retardés par les heures calmes. Rejouable sans doublon.

## 7. E-mails

Tous les e-mails passent par un seul gabarit (`components/mail/*`, `emails/notice`) : bandeau marine avec la marque, filet safran, carte blanche, bouton, petit tableau de faits, pied de page avec la signature « Tout droit de Kouma », la raison pour laquelle on reçoit le message et un lien vers les préférences. Chaque e-mail a une **version texte**. `Mail::raw` est interdit (test `EmailsTest`).

- `App\Mail\Notice` : le message générique (titre, paragraphes, faits, bouton, ton `info|success|warning|danger`).
- `LeadAlert`, `HandoffRequested`, `AnalyticsDigest`, réinitialisation du mot de passe : leurs vues utilisent le même gabarit.
- **Administration, E-mails** (super administrateur) : tous les modèles avec des données d'exemple, aperçu, et **« M'envoyer cet e-mail »** pour le voir dans une vraie boîte. `php artisan platform:mail-test` envoie l'e-mail d'essai et explique les pannes courantes (identifiants, port, certificat).
- Pour que les e-mails arrivent en boîte de réception et pas en courrier indésirable : SPF et DKIM chez l'hébergeur (voir `docs/DEPLOYMENT.md`).

## 8. Limites connues

- Un téléphone éteint ou hors réseau reçoit la notification à son retour, pendant 24 heures au plus (`notifications.push.ttl`).
- Les navigateurs peuvent limiter les notifications d'un site peu utilisé ou couper celles d'une application que la personne a désactivée dans les réglages de son téléphone : le centre de notifications reste la référence.
- Le compteur sur l'icône dépend du système (voir 3) ; il se recale à chaque ouverture de l'application.
- Les heures calmes suivent le fuseau de la plateforme, pas celui de chaque personne (une seule zone horaire pour l'Afrique de l'Ouest francophone).
- Pas encore de notifications pour : fin de traitement d'une source, nouvelle réponse d'un conseiller à un autre membre de l'équipe.
