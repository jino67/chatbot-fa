# Guide du super admin Kouma

## À qui s'adresse ce guide

Ce guide est destiné au **propriétaire de la plateforme**, qui a tous les droits : offres et prix, fournisseurs d'IA, coûts, paramètres, équipe, statistiques, mise en ligne et exploitation. Il complète le **guide de l'équipe** (accompagnement des clients, paiements, activation de WhatsApp) que vous pouvez aussi lire dans la console.

> **Important :** ce document contient des procédures sensibles. Il est réservé au super admin. Ne le partagez pas, et ne copiez jamais de mot de passe, de clé d'API ou de jeton dans un message, un e-mail ou un fichier du dépôt.

### Vos responsabilités

1. **La santé du service** : les assistants répondent, WhatsApp fonctionne, le site est accessible.
2. **L'équilibre économique** : offres, prix, coûts de l'IA et de WhatsApp, marges.
3. **La sécurité** : secrets, accès, sauvegardes, conduite à tenir en cas d'incident.
4. **La conformité** : mentions légales, politique de confidentialité, mesure d'audience.
5. **La qualité** : réponses justes, clients satisfaits, équipe formée.
6. **La croissance** : référencement, acquisition, conversion, rétention (module de statistiques).

## 1. La console du super admin

Le menu de gauche, partie « Super admin », contient :

| Entrée | Rôle |
|---|---|
| Vue d'ensemble | Pilotage du jour : chiffres, demandes, échéances, alertes IA |
| Espaces clients | Les entreprises clientes (voir le guide de l'équipe) |
| Demandes WhatsApp | Les activations à réaliser |
| Demandes d'offre | Les changements d'offre et options à traiter |
| Notifications | Les promotions, nouveautés et messages importants envoyés aux clients (aussi ouvert à l'équipe) |
| Consommation | Coûts réels et estimés, marges, solde Twilio |
| Statistiques | Audience, comportements, entonnoirs, santé des clients |
| Offres et tarifs | Les offres, leurs prix par devise, quotas et options |
| IA et fournisseurs | La chaîne de fournisseurs d'IA et la recherche dans les documents |
| Équipe | Les membres de l'équipe et leurs rôles |
| Paramètres | Marque, vitrine, paiement, mentions légales, WhatsApp, voix, statistiques |
| E-mails | Tous les e-mails automatiques avec des données d'exemple, et un essai d'envoi à vous-même |
| Journal | L'historique de toutes les actions sensibles |

La partie « Ressources » donne accès aux guides, dont celui-ci et le guide de l'équipe, avec leur PDF.

## 2. La vue d'ensemble

Les chiffres du jour : espaces clients (et payants), assistants, conversations sur 7 jours, réponses de l'IA du mois. Puis l'**encaissé du mois** (une ligne par devise, jamais additionnées), les **demandes d'offre** et d'**activation WhatsApp** à traiter, les **abonnements qui arrivent à échéance**, et un **bandeau rouge** quand un fournisseur d'IA est en difficulté, avec un lien vers la chaîne.

## 3. La consommation et les coûts

La page **Consommation** répond à trois questions : *combien me coûte chaque client ?*, *à combien s'élèvent mes marges ?*, *mon portefeuille Twilio tiendra-t-il ?*

- **Solde Twilio (portefeuille)** : lu en direct auprès de Twilio (bouton **Relire le solde**). Un e-mail vous prévient quand il passe sous le seuil réglé dans les Paramètres (par défaut quelques dollars).
- **Clients, ce mois-ci** : pour chacun, ses réponses d'IA, ses messages WhatsApp (par catégorie), ses messages vocaux, le coût estimé et la marge par rapport à son offre.
- **Répartition WhatsApp** : service, utilitaire, marketing, entrants et sortants.
- **Économie possible** : un bandeau suggère de faire passer certains canaux de Twilio à Meta direct quand l'économie est significative.
- **Derniers événements** : le flux détaillé en temps réel.

> **À savoir :** les coûts sont des **estimations** calculées avec les tarifs unitaires de la rubrique « WhatsApp : comptes de la plateforme et coûts » des Paramètres. Les tarifs de Meta changent : vérifiez-les régulièrement dans la grille officielle, puis mettez-les à jour.

## 4. Les offres et les prix

**Offres et tarifs** liste les offres. Vous pouvez en **créer**, **modifier** ou **supprimer**.

### Les champs d'une offre

| Champ | Rôle |
|---|---|
| Nom affiché, accroche | Ce que voient les clients sur le site et dans l'Abonnement |
| Identifiant (définitif) | Minuscules, chiffres, tirets : il ne pourra **plus être changé** |
| Prix par période, dans chaque devise | Le FCFA est la référence. Une devise laissée vide n'est pas proposée : le client voit alors le prix en FCFA. Repères : 1 euro = 655,957 FCFA = 491,968 KMF (parités fixes) ; le dollar varie, à revoir de temps en temps |
| Période (mois) | Durée couverte par le prix |
| Durée de l'essai gratuit (jours) | Pour l'offre gratuite : après ce délai, l'assistant se met en pause jusqu'au choix d'une offre payante. Laissez vide pour une offre payante |
| Quotas | Réponses par mois, assistants, sources, pages par site, utilisateurs, messages WhatsApp par mois, messages vocaux par mois |
| Options incluses | WhatsApp, modèles de messages, mention « Propulsé par » retirée, assistance prioritaire, messages vocaux, import de discussions WhatsApp, API pour développeurs |
| Ordre d'affichage | La position sur le site |

### Ce qui se passe quand vous enregistrez une offre

- Les **prix** de la page d'accueil, des pages de guides, de l'Abonnement et du **chat web de Kouma** se mettent à jour : aucun prix n'est écrit en dur dans le site. L'assistant de la page d'accueil relit automatiquement la nouvelle grille.
- Les clients **déjà abonnés** gardent leur offre : seuls les nouveaux paiements suivent le nouveau prix.

> **Attention :** avant de baisser un quota ou de retirer une option d'une offre existante, vérifiez qui l'utilise : les clients concernés verraient leurs limites changer à leur prochain renouvellement.

### Vérifier la rentabilité

Pour chaque offre, comparez le prix au **coût au plein quota** (IA, WhatsApp, voix) sur la page Consommation. Une offre doit rester rentable à l'usage normal **et** au quota complet. Le dépôt contient un test qui le vérifie pour les offres livrées : refaites ce calcul après toute modification de prix ou de quota.

## 5. L'IA et les fournisseurs

La page **IA et fournisseurs** règle quelle IA répond aux clients de vos clients, et comment la plateforme retrouve l'information dans les documents.

### La chaîne de fournisseurs

Chaque fournisseur (Anthropic, OpenAI, OpenRouter, DeepInfra, Together, ou un serveur compatible) a une **clé**, un **modèle**, une **adresse d'API**, un **modèle pour les images** facultatif, et une **priorité** (1 = en premier). Les clés sont chiffrées en base et ne sont jamais réaffichées.

- **Mode automatique** : la plateforme essaie le fournisseur de plus haute priorité ; s'il échoue (clé expirée, quota épuisé, panne), elle **bascule** sur le suivant et réessaie plus tard.
- **Bascule manuelle** : choisissez « Fournisseur à utiliser en premier » puis **Appliquer** pour forcer un fournisseur.
- **Tester** : envoie une requête d'essai et affiche le résultat.
- **Remettre en service** : après correction d'une panne, remet un fournisseur dans la chaîne.
- **Ajouter un fournisseur** : type, nom, modèle, adresse d'API, clé, puis « Ajouter en fin de chaîne ».

> **Attention :** sans aucune clé valide, l'assistant ne rédige plus : il renvoie seulement des extraits de ses sources. Gardez **au moins deux fournisseurs** dans la chaîne pour la continuité de service, et surveillez le bandeau rouge de la vue d'ensemble.

### La recherche dans les documents (embeddings)

Le moteur transforme les documents et les questions en vecteurs pour retrouver les bons extraits. Options : `hashing` (hors ligne, sans sémantique, pour les essais), `voyage` ou `openai` (en production). **Changer de moteur** impose de **Recalculer les vecteurs** : sans cela, la recherche perd en précision. Faites-le en dehors des heures de pointe.

### Choisir ses fournisseurs

| Critère | Conseil |
|---|---|
| Qualité des réponses | Un modèle récent de premier plan en tête de chaîne |
| Coût | Un modèle économique pour le gros du volume ; l'IA qui rédige est le premier poste de coût |
| Continuité | Au moins deux fournisseurs différents, de sociétés différentes |
| Images et PDF scannés | Un fournisseur qui lit les images, renseigné dans « Modèle pour les images » |

## 6. L'équipe

La page **Équipe** liste les comptes du personnel. **Ajouter un membre** : nom, e-mail, rôle (**Admin** ou **Super admin**). Un mot de passe provisoire est affiché **une seule fois**. Vous pouvez **changer le rôle**, **réinitialiser le mot de passe** et **désactiver** un compte.

- Donnez le rôle **Admin** par défaut : il suffit à accompagner les clients. Réservez **Super admin** aux personnes qui doivent toucher aux prix, aux clés et aux paramètres.
- **Désactivez immédiatement** le compte d'une personne qui quitte l'équipe.
- Chaque membre travaille **avec son propre compte** : jamais de compte partagé.

## 7. Les paramètres de la plateforme

### Marque

Le **nom du produit** (il s'affiche partout : site, e-mails, « Propulsé par »), l'**adresse du site**, l'**accroche**, l'**e-mail de contact et de notification** (il reçoit les demandes d'offre et d'activation WhatsApp) et le **numéro WhatsApp commercial**. Avec l'indicatif du pays. Tant qu'il est vide, le bouton « Écrivez-nous sur WhatsApp » n'apparaît ni sur le site, ni dans les cartes « Besoin d'aide ? » de l'application.

### Vitrine

L'**assistant de démonstration de la page d'accueil** : c'est le widget que voient tous vos visiteurs, votre meilleure démonstration, et le **chat web de Kouma** proposé dans les guides. Choisissez un assistant bien rempli. Il se met à jour tout seul avec vos offres, vos langues et vos pages de contenu (commande `platform:landing-bot`).

### Paiement

Le texte « **Comment vos clients vous paient** », affiché sur la page Abonnement de chaque client (numéros Mobile Money, coordonnées bancaires, consigne de référence). Le paiement en ligne automatique n'est pas branché : vous enregistrez les paiements reçus depuis la fiche du client.

### Mentions légales

Raison sociale, immatriculation (RCCM, IFU…), adresse, e-mail légal : ces informations remplissent les pages **Conditions d'utilisation** et **Confidentialité**. Faites relire ces textes par un juriste avant la mise en production.

### WhatsApp : comptes de la plateforme et coûts

- **Fournisseur conseillé** pour les nouveaux canaux : automatique (Meta direct si disponible, sinon Twilio), toujours Meta ou toujours Twilio.
- **Meta** : l'identifiant du compte WhatsApp Business (WABA) et le **jeton d'utilisateur système**.
- **Twilio** : l'**Account SID** et l'**Auth Token**. Pour un essai avec un sous-compte, saisissez ceux du **sous-compte**.
- **Seuil d'alerte** du solde Twilio (en dollars).
- **Coûts unitaires** (en dollars) : Twilio par message, Meta service, utilitaire, marketing ; taux dollar par euro et dirham par euro. Laissez vide pour reprendre les valeurs par défaut.

### Voix : messages vocaux et langues locales

Une **clé pour la voix** (facultatif, sinon celle de l'IA), le **modèle d'écoute** et le **modèle de voix**. Le bouton de test envoie un échantillon pour vérifier la chaîne.

### Statistiques

Trois réglages (voir la section 9) : **mesurer les visites** (case à décocher pour tout arrêter), **recevoir le résumé du lundi par e-mail**, et la **durée de conservation** des données (400 jours par défaut, de 30 à 1 095 jours).

## 8. La mise en ligne et l'exploitation

### Architecture chez LWS (hébergement mutualisé)

- Le **dossier du domaine** (`htdocs/kouma.site`) sert de dossier public : on y trouve `index.php`, `.htaccess`, `build/`, `widget/`, `documents/`, les icônes et le dossier `kouma/`.
- Le **projet complet** vit dans le sous-dossier `kouma/` (application, bibliothèques, `.env`, journaux). Le `.htaccess` **interdit** l'accès web à ce dossier, et refuse aussi les fichiers `.env`, `.log`, `.sql`, `.zip`, `.bak`, `.tar` et `.gz`.
- La base de données est **MySQL**, gérée par phpMyAdmin.
- Il n'y a **pas d'accès SSH** : les mises à jour se font par envoi de fichiers et, pour la base, par import SQL.

### Vérifications après chaque mise en ligne

Ces adresses doivent répondre :

| Adresse | Attendu |
|---|---|
| `/`, `/aide`, `/ressources`, `/login`, `/register` | 200 |
| `/sitemap.xml`, `/robots.txt`, `/llms.txt` | 200 |
| `/dashboard`, `/admin` | redirection vers la connexion |
| `/kouma/.env`, `/kouma/storage/logs/` | **403** (jamais le contenu) |
| Un fichier `.zip` laissé dans le dossier public | **403 ou 404** |

Puis, connecté en super admin :

1. **E-mails** : ouvrez un modèle et cliquez sur « M'envoyer cet e-mail » : il doit arriver dans votre boîte (pas dans les courriers indésirables), avec le bandeau de la marque.
2. **Notifications** : sur votre téléphone, ouvrez le site, installez l'application, touchez « Activer les notifications » puis « Envoyer un essai » : une notification doit apparaître. Ensuite, dans le menu Notifications, envoyez-vous un essai depuis le formulaire.
3. **Statistiques** : ouvrez une page du site, puis la page Statistiques : « En ce moment » doit compter votre visite.
4. **Base de données** : si la mise à jour contenait un fichier `…-base-de-donnees.sql`, il doit avoir été importé **avant** d'ouvrir le site (sinon, Statistiques et Notifications affichent une erreur).

### Les variables de configuration (fichier `kouma/.env`)

Seuls les **noms** sont donnés ici ; les valeurs sont des secrets.

| Variable | Rôle |
|---|---|
| `APP_URL`, `APP_ENV`, `APP_DEBUG` | Adresse du site, `production`, `false` |
| `APP_KEY` | Clé de chiffrement de l'application. Ne la changez pas sans plan : les données chiffrées deviendraient illisibles |
| `DB_HOST`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | La base MySQL |
| `MAIL_MAILER`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_FROM_ADDRESS` | L'envoi d'e-mails (SMTP) |
| `BRAND_EMAIL`, `BRAND_WHATSAPP` | Contacts de secours de la marque |
| `OPENAI_API_KEY`, `ANTHROPIC_API_KEY`, `OPENROUTER_API_KEY`, `DEEPINFRA_API_KEY`, `TOGETHER_API_KEY`, `VOYAGE_API_KEY` | Clés des fournisseurs d'IA (peuvent aussi se saisir dans la console) |
| `META_APP_SECRET`, `META_VERIFY_TOKEN`, `META_GRAPH_VERSION` | WhatsApp Meta : **sans `META_APP_SECRET`, tous les appels Meta sont refusés** |
| `QUEUE_CONNECTION` | `sync` sur un mutualisé (pas de processus permanent) |
| `SESSION_SECURE_COOKIE` | `true` une fois le HTTPS vérifié |
| `WHATSAPP_AUTO_TEMPLATES` | `false` pour couper la création automatique des modèles à l'activation d'un canal |

### La tâche planifiée (cron)

Une seule ligne dans le panneau LWS, « Tâches CRON », toutes les 5 minutes :

```
php /htdocs/kouma.site/kouma/artisan schedule:run
```

Si le panneau n'accepte qu'un fichier PHP, utilisez `kouma/cron.php`. Elle pilote :

| Tâche | Fréquence | Rôle |
|---|---|---|
| `platform:resync-due` | Toutes les heures | Relit les sites web et les pages dont la mise à jour automatique est due |
| `platform:subscriptions` | Chaque jour à 8 h | Rappels d'échéance, période de grâce, fin des essais, retour à l'offre gratuite |
| `leads:remind` | Toutes les 10 minutes | Relance les demandes restées sans réponse |
| `platform:check-wallets` | Toutes les heures | Alerte quand le solde Twilio est bas ou qu'un client approche de son volume WhatsApp |
| `notifications:dispatch` | Chaque minute | Envoie les campagnes de l'équipe et les notifications retardées par les heures calmes |
| `analytics:prune` | Chaque jour | Supprime les statistiques au-delà de la durée de conservation |
| `analytics:digest` | Chaque lundi | Envoie le résumé hebdomadaire des statistiques |

Pour vérifier qu'elle tourne, redirigez temporairement la sortie vers `kouma/storage/logs/cron.log` ; une ligne « No scheduled commands are ready to run. » prouve qu'elle s'exécute.

### Mettre à jour le site

1. Dans le dépôt, fabriquez le zip de mise à jour : `php scripts/build-lws-update.php mise-a-jour.zip` (seulement les fichiers modifiés depuis le dernier commit, sans secret).
2. Envoyez-le dans le dossier du domaine, **extrayez-le en écrasant**, puis **supprimez-le immédiatement** : un zip laissé dans le dossier public est téléchargeable.
3. Si la mise à jour contient un **changement de base de données**, le script écrit à côté du zip un fichier `…-base-de-donnees.sql` et le dit : **avant d'ouvrir le site mis à jour**, ouvrez phpMyAdmin, cliquez sur votre base dans la colonne de gauche, onglet « Importer », choisissez ce fichier. Il ne contient que de la structure (jamais de mot de passe) et ne s'importe qu'une fois.
4. Refaites les **vérifications** ci-dessus, puis essayez une conversation avec le chat web.

Pour un premier déploiement complet : `scripts/build-lws-package.php` (voir `docs/DEPLOYMENT.md`).

### Sauvegardes

- **Base de données** : export SQL depuis phpMyAdmin (onglet « Exporter »), au minimum une fois par semaine et avant chaque mise à jour importante. Gardez les exports **hors du dossier public**, de préférence chiffrés.
- **Fichiers** : le dossier `kouma/storage/app` (documents envoyés par les clients) et le fichier `.env` (à conserver en lieu sûr).
- **Testez la restauration** au moins une fois : une sauvegarde jamais restaurée n'est pas une sauvegarde.

### Les journaux

Les erreurs s'écrivent dans `kouma/storage/logs/` (un fichier par jour). Pour une panne qui renvoie une page blanche : ouvrez le journal du jour dans le gestionnaire de fichiers LWS, et regardez les dernières lignes. Vérifiez aussi la version de PHP du domaine (8.2 ou plus), le fichier `.env` (une ligne mal écrite suffit à tout bloquer) et le dossier de quarantaine de l'hébergeur.

### HTTPS et sécurité du site

Le HTTPS est géré par l'hébergeur. L'en-tête `Strict-Transport-Security` est actif dans le `.htaccess`, et `SESSION_SECURE_COOKIE=true` dans le `.env`. Ne désactivez jamais le HTTPS une fois activé.

### Le courrier électronique

Les e-mails (réinitialisation de mot de passe, alertes, rappels, résumé hebdomadaire) partent par SMTP depuis la boîte de la plateforme. Pour qu'ils arrivent en boîte de réception et non en courrier indésirable, configurez chez l'hébergeur les enregistrements **SPF**, **DKIM** et **DMARC** du domaine. Testez avec « Mot de passe oublié » sur la page de connexion.

### Le référencement

Soumettez `https://kouma.site/sitemap.xml` à **Google Search Console** (propriété de type « Domaine ») et à **Bing Webmaster Tools**. Les pages de contenu, les guides, la page d'aide et la page développeurs figurent dans le plan du site. Chaque page publique a son titre, sa description, son adresse canonique et ses données structurées.

## 9. Les statistiques : comprendre l'audience et les comportements

La page **Statistiques** mesure, de façon **anonyme et sur votre propre serveur**, ce que font les **visiteurs** du site et les **clients** dans l'application. Aucun outil tiers : les données ne quittent jamais votre base.

### Où la trouver

**Statistiques** dans le menu de gauche (super admin seulement). En haut, choisissez la **période** (7 jours, 30 jours, 90 jours, 12 mois) et le **public** : visiteurs, clients connectés, ou tous. Le bouton **Exporter en CSV** donne le tableau de la rubrique ouverte (il s'ouvre directement dans Excel). Les heures sont celles de la plateforme.

### Les neuf rubriques

| Rubrique | Ce qu'elle montre |
|---|---|
| **Aperçu** | Visiteurs, visites, pages vues, pages par visite, temps passé, visites sans action, visiteurs qui reviennent, visites qui aboutissent, chacun comparé à la période d'avant ; l'évolution jour après jour ; **En ce moment** (qui est sur le site, mis à jour toutes les 20 secondes) ; des phrases « à retenir » |
| **Affluence** | Une carte **jour de la semaine par heure** : plus la case est foncée, plus il y a de visites. Le créneau record, l'heure et le jour les plus chargés |
| **Pages** | Pour chaque page : vues, visiteurs, entrées, rebond, sorties, temps actif, part des visiteurs qui lisent la moitié |
| **Clics** | Boutons d'action les plus cliqués, contacts (WhatsApp, e-mail, téléphone), liens vers d'autres sites, PDF téléchargés, **clics répétés** (signe qu'un élément ne répond pas), sections de la page d'accueil réellement lues |
| **Provenance** | Accès direct, moteurs, réseaux sociaux, assistants d'IA, e-mails, campagnes (`utm_campaign`), autres sites ; appareils, pays estimés, navigateurs, systèmes, langues ; pour chaque canal, la part des visites qui **aboutissent** (un contact ou une inscription) |
| **Parcours** | L'entonnoir de la visite à l'inscription, les pages d'entrée, les passages d'une page à l'autre, les pages lues et le temps par visite |
| **Clients** | Espaces actifs (jour, semaine, mois), régularité, nouveaux comptes qui avancent (assistant, connaissances, test, mise en ligne, offre payante), fonctions utilisées, **santé de chaque client**, clients qui ont décroché, cohortes par semaine d'inscription, heures de connexion |
| **Expérience** | Vitesse ressentie (affichage, réactivité, stabilité, réponse du serveur), jusqu'où l'on lit, formulaires abandonnés, erreurs rencontrées dans le navigateur |
| **Assistant du site** | Les questions que les visiteurs posent à l'assistant de la page d'accueil, et à quelle heure |

La **fiche d'un espace client** montre aussi son activité des 30 derniers jours (visites, jours actifs, pages visitées, actions faites, santé). Côté client, la page **Analytique** de chaque assistant montre **quand ses propres clients écrivent**.

### Comment lire les chiffres

| Question | Où regarder | Action possible |
|---|---|---|
| Combien de monde vient, et d'où ? | Aperçu, Provenance | Renforcer ce qui marche (référencement, campagnes, réseaux) |
| À quelle heure publier ou répondre ? | Affluence | Publier juste avant le pic ; renforcer la présence de l'équipe |
| Quelles pages convainquent ? | Pages, Clics | Améliorer les pages d'entrée qui perdent leurs visiteurs |
| Où perd-on les gens ? | Parcours | Simplifier l'étape où la chute est la plus forte |
| Quels clients risquent de partir ? | Clients | Appeler ceux qui ont décroché avant l'échéance |
| Quelles fonctions servent ? | Clients, « Fonctions utilisées » | Mieux montrer, ou retirer, celles que personne n'utilise |
| Le site est-il rapide, sans bug ? | Expérience | Corriger les erreurs les plus fréquentes |

**La note de santé** d'un client va de 0 à 100 : visites récentes (40 points), régularité sur 30 jours (30), fonctions utilisées (20), vrais clients qui écrivent à son assistant (10). Au-dessus de 70 : très bonne ; sous 45 : à surveiller ; sous 20 : en danger. C'est une aide, pas un verdict : elle ne sait pas qu'un client est en vacances.

Les phrases « **à retenir** » résument ce qui change (tendance, créneau record, canal qui domine, page qui perd ses visiteurs, clics répétés, journée inhabituelle). Elles se taisent tant qu'il y a moins d'une dizaine de visites. Un **résumé hebdomadaire** part par e-mail chaque lundi matin à l'adresse d'administration.

> **À savoir :** les chiffres sont des **minimums**. Les visiteurs qui bloquent les scripts ou activent « Ne pas suivre » ne sont pas comptés, et le pays est une estimation d'après le fuseau horaire du navigateur (aucune adresse IP n'est lue).
### Respect de la vie privée

La mesure est conçue pour respecter la vie privée :

- **Aucune adresse IP n'est conservée.** Aucun texte saisi n'est lu. Les libellés de boutons et les messages d'erreur sont nettoyés : une adresse e-mail ou un numéro qui s'y glisserait est effacé avant l'enregistrement.
- Trois petits cookies : `_kv` (identifiant aléatoire du navigateur, 13 mois, pour compter les visiteurs qui reviennent), `_ks` (identifiant de la visite, effacé à la fermeture) et `_ko` (le refus du visiteur). Ils ne permettent pas de reconnaître une personne et ne servent à aucun autre site.
- Le **signal « Ne pas suivre »** et le contrôle « Global Privacy Control » du navigateur sont respectés. La page **Confidentialité** explique la mesure et permet de la **refuser en un clic**.
- Les visites de l'équipe sont **séparées** et exclues des chiffres par défaut. La console d'administration n'est pas mesurée.
- Les données brutes sont **supprimées** au-delà de la durée de conservation réglée dans les Paramètres (400 jours par défaut).
- Vous pouvez **désactiver** la mesure dans les Paramètres : plus aucun événement n'est enregistré.

> **Important :** selon les pays de vos visiteurs, une mesure d'audience peut demander une information, voire un consentement. Faites relire la page Confidentialité et la politique de conservation par un juriste de votre pays, comme pour les mentions légales.

## 10. Les e-mails et les notifications

### Les e-mails

Tous les e-mails automatiques (bienvenue, alertes de demandes, échéances, reçus de paiement, WhatsApp activé, réinitialisation du mot de passe, demandes à traiter, solde bas, résumé du lundi) partent dans le **même gabarit aux couleurs de la marque**, avec une version texte et la signature « Tout droit de Kouma ».

Le menu **E-mails** les montre tous avec des données d'exemple. Cliquez sur **M'envoyer cet e-mail** : vous le recevez dans votre vraie boîte, tel que le verra le destinataire. C'est le moyen le plus simple de vérifier que l'envoi fonctionne et que les messages n'arrivent pas dans les courriers indésirables.

> **À savoir :** tant que `MAIL_MAILER` vaut `log` ou `array`, rien ne part : la page E-mails l'indique en haut. En production, utilisez `smtp` avec la boîte de votre domaine (voir « Le courrier électronique » plus haut) et lancez `php artisan platform:mail-test` si un essai n'arrive pas : la commande explique les pannes courantes.

Si un e-mail n'arrive pas : vérifiez les courriers indésirables, le mot de passe de la boîte, puis les enregistrements **SPF** et **DKIM** de votre domaine chez l'hébergeur (ils prouvent que le message vient bien de vous).

### Les notifications sur les téléphones

Les clients et l'équipe reçoivent des **notifications sur leur téléphone** (même application fermée), avec le **nombre de messages à lire sur l'icône** de l'application installée, et une **cloche** dans l'application. Détails techniques : `docs/NOTIFICATIONS.md`.

- **Rien à configurer.** Les clés qui authentifient vos messages auprès de Google, Apple et Mozilla se créent toutes seules à la première utilisation, restent **chiffrées** dans les paramètres, et ne s'affichent jamais. `php artisan push:keys` vérifie leur état.
- **HTTPS est indispensable** (les navigateurs l'exigent). Le site en HTTPS suffit.
- **La tâche planifiée** envoie les campagnes et les messages retardés par les heures calmes (voir « La tâche planifiée (cron) »). Avec le cron de LWS toutes les 5 minutes, un envoi massif part par tranches dans les minutes qui suivent.
- **Les clients choisissent** ce qu'ils reçoivent (commandes, clients qui attendent, abonnement, nouveautés), ainsi que leurs heures calmes. Les « messages importants » sont toujours reçus : réservez-les aux vraies urgences.
- **Les alertes de demandes** (commande, rendez-vous, devis, client qui attend) partent aussi sur les téléphones de l'équipe du client, avec le compteur sur l'icône.
- **Vous êtes prévenu vous aussi** : demande d'offre, demande d'option, demande d'activation WhatsApp, solde Twilio bas.

### Envoyer une promotion ou une annonce

Le menu **Notifications** (ouvert à l'équipe) : écrire le message, choisir l'audience (tous, par offre, entreprises choisies, clients absents depuis N jours), s'envoyer un essai, puis envoyer maintenant ou programmer. Le détail pas à pas est dans le guide de l'équipe. Quelques règles à retenir :

- une promotion respecte les choix des clients : **un message par jour au plus**, jamais la nuit, jamais pour ceux qui l'ont refusée ;
- un **message important** passe toujours : n'en abusez pas, c'est ce qui pousse à bloquer toutes les notifications ;
- chaque envoi est consigné dans le **journal**, et les résultats (reçus, envoyés sur téléphone, ouverts) se lisent sur la campagne.

> **Attention :** un client doit avoir **activé** les notifications sur son appareil pour les recevoir sur l'écran. Les autres voient votre message dans la cloche à leur prochaine visite. La portée est affichée en haut de la page Notifications.

## 11. Le journal d'audit

Le **Journal** liste les actions sensibles : connexions du personnel, entrées dans un espace, paiements, changements d'offre, de devise, de rôle, création et révocation de clés d'API, activation de canaux, modèles créés. Pour chacune : qui, quoi, dans quel espace, quand. Filtrez par type d'action.

Consultez-le après tout incident, et régulièrement pour repérer une action inhabituelle (connexion à une heure inattendue, nombreux changements d'offre).

## 12. WhatsApp : mise en place complète

### Meta direct (cas général)

1. **Compte Meta Business** de la plateforme, avec une **application** et le produit WhatsApp.
2. **Utilisateur système** et **jeton permanent** avec les permissions WhatsApp : saisissez-le dans « Meta : jeton d'utilisateur système » (Paramètres).
3. **Webhook** : un seul pour tous les clients, `https://kouma.site/webhooks/whatsapp/meta`, avec le **jeton de vérification** (`META_VERIFY_TOKEN`) et le secret de l'application (`META_APP_SECRET` dans le `.env`).
4. **Pour chaque client** : ajouter son numéro, valider le **nom d'affichage** auprès de Meta, puis saisir `phone_number_id` et `WABA ID` dans la demande WhatsApp du client.

### Twilio (secours ou démarrage rapide)

1. Créez un **sous-compte** dédié (par exemple « Kouma essai », et un par client si vous voulez isoler la facturation).
2. Dans la console du sous-compte : **Messaging, Senders, WhatsApp senders, Create new sender** : numéro, compte Meta Business, nom d'affichage à faire approuver. Le bac à sable de Twilio, quand il est disponible, sert aux essais de conversation mais **pas** aux modèles.
3. Dans les **Paramètres de Kouma**, saisissez l'Account SID et l'Auth Token du sous-compte.
4. Activez le canal dans **Demandes WhatsApp** (fournisseur Twilio, numéro expéditeur), cliquez sur **Tester la connexion**, puis collez l'**adresse du webhook** affichée dans le champ « When a message comes in » de l'expéditeur (POST).
5. Ajoutez du **crédit** sur le compte Twilio : un solde nul bloque l'envoi.

### Les modèles de messages

À l'activation d'un canal, le paquet de base et celui du métier de l'assistant sont envoyés à l'approbation de WhatsApp (Meta par l'API Graph, Twilio par l'API Content). Un modèle approuvé apparaît dans la liste du client. En cas de refus, le motif s'affiche : corrigez le texte et recréez sous un autre nom. Les modèles « marketing » coûtent plus cher que les « utilitaires » : surveillez la répartition dans Consommation.

### Les règles à ne pas oublier

- Un message libre n'est possible que dans les **24 heures** après le dernier message du client ; au-delà, seul un modèle approuvé.
- Le **numéro** ne peut pas servir à la fois dans l'application WhatsApp et pour l'assistant.
- Le **nom d'affichage** doit correspondre à l'entreprise : un nom générique est refusé.
- Les tarifs de Meta dépendent de la catégorie du message et du pays : mettez à jour les coûts unitaires des Paramètres quand ils changent.

## 13. La sécurité

### Les règles d'or

1. **Aucun secret dans un message, un e-mail, une capture d'écran ou le dépôt de code.** Les mots de passe et les clés se saisissent directement dans la console ou dans le fichier `.env`.
2. **Un compte par personne**, mot de passe long et unique, jamais partagé.
3. **Privilège minimal** : rôle Admin par défaut.
4. **Rien dans le dossier public** qui ne doive être public : jamais d'archive, d'export de base ou de sauvegarde.
5. **Supprimez** les fichiers de mise à jour dès qu'ils sont extraits.

### Si un secret a pu fuiter (conduite à tenir)

Par exemple un zip de déploiement resté accessible, ou une clé collée dans un message :

1. **Supprimez** le fichier ou le message **tout de suite**, puis vérifiez que l'adresse répond 404.
2. **Changez** les secrets concernés : la **clé du fournisseur d'IA** (créez-en une nouvelle et révoquez l'ancienne chez le fournisseur), le **mot de passe de la base MySQL** (panneau LWS), le **mot de passe de la boîte e-mail**, les **jetons WhatsApp** (Meta, Twilio). Reportez les nouvelles valeurs dans `kouma/.env` et dans les Paramètres.
3. **Faites tourner** les clés d'API de vos clients si elles ont pu être exposées, et prévenez-les.
4. **Consultez** le journal d'audit et les journaux du serveur pour repérer un usage inhabituel.
5. **Documentez** l'incident (quoi, quand, durée d'exposition, mesures prises) et, si des données personnelles sont concernées, appliquez la procédure de notification prévue par la loi de votre pays.

La clé de chiffrement `APP_KEY` protège les identifiants chiffrés en base : ne la changez que dans le cadre d'un plan précis (les valeurs chiffrées devraient être ressaisies).

### Les accès de l'équipe

- Réinitialisez le mot de passe d'un membre dès qu'un doute existe.
- Retirez les accès des anciens collaborateurs le jour de leur départ.
- Relisez la liste de l'équipe à chaque trimestre.

## 14. La qualité du service

- **Relisez des conversations réelles** chaque semaine : sur quelques clients, regardez si l'assistant répond juste, s'il cite des prix à jour, s'il passe la main quand il le faut.
- **Constituez un jeu de questions de référence** (une vingtaine de questions typiques avec la réponse attendue) pour chaque grande catégorie de client, et rejouez-le **après tout changement de modèle d'IA ou de consigne**.
- **Surveillez** le taux de réponses appuyées sur les sources, les transferts vers un humain et les pouces baissés (Analytique de chaque client).
- **Conversation libre** : surveillez les premiers jours que l'assistant n'invente pas d'information sur l'entreprise. Un client peut la désactiver dans ses réglages.
- **Langues locales** : faites relire par des locuteurs avant de les promettre à grande échelle.

## 15. Conformité et légal

- **Mentions légales** : raison sociale, immatriculation, adresse, e-mail légal (Paramètres). Faites relire les pages Conditions d'utilisation et Confidentialité par un juriste.
- **Données personnelles** : les clients de vos clients écrivent à des assistants et leurs messages sont enregistrés dans l'espace du client. Précisez dans les conditions les rôles de chacun (le client est responsable de ses données, la plateforme est son prestataire).
- **Mesure d'audience** : voir la section 9.
- **Messages marketing sur WhatsApp** : réservés aux personnes qui ont accepté d'en recevoir.
- **Durées de conservation** : définissez et appliquez des durées pour les conversations et les statistiques ; informez-en les clients.
- **Suppression** : un client peut supprimer ses assistants et son compte ; documentez ce qui est effacé et ce qui est conservé pour des raisons légales (paiements).

## 16. Les listes de contrôle

### Avant le lancement

- [ ] HTTPS actif, en-tête HSTS actif, cookies sécurisés.
- [ ] Secrets changés après toute exposition ; `APP_DEBUG=false`.
- [ ] Tâche planifiée en place (journal cron vérifié).
- [ ] Courrier : « Mot de passe oublié » reçoit bien l'e-mail ; SPF, DKIM et DMARC configurés.
- [ ] Mentions légales et Confidentialité renseignées et relues.
- [ ] Numéro WhatsApp commercial renseigné ; contacts de marque vérifiés.
- [ ] Au moins deux fournisseurs d'IA dans la chaîne, testés.
- [ ] Assistant de la vitrine rempli et testé ; chat web fonctionnel.
- [ ] Sitemap soumis à Google et à Bing.
- [ ] Sauvegarde de la base faite et restauration testée.

### Chaque semaine

- [ ] Vue d'ensemble : demandes, échéances, bandeaux.
- [ ] Solde Twilio et consommation.
- [ ] Statistiques : audience, entonnoirs, comptes à risque.
- [ ] Quelques conversations réelles relues.
- [ ] Sauvegarde de la base.

### Chaque mois

- [ ] Marges par offre et coûts unitaires à jour.
- [ ] Revue de l'équipe et des accès.
- [ ] Revue du journal d'audit.
- [ ] Révision des textes du site et des guides.

## 17. Aide-mémoire des commandes

Ces commandes se lancent avec `php artisan` dans un environnement avec accès au terminal (poste de développement). Chez LWS, sans SSH, utilisez la console et les procédures ci-dessus.

| Commande | Rôle |
|---|---|
| `platform:landing-bot` | Crée ou met à jour l'assistant de la page d'accueil d'après les offres |
| `platform:ask {id} "question"` | Pose une question à un assistant et montre les extraits utilisés |
| `platform:make-admin` | Crée ou promeut un compte du personnel |
| `platform:mail-test` | Envoie un e-mail d'essai et explique la panne |
| `platform:reindex` | Recalcule les vecteurs après un changement de moteur |
| `whatsapp:templates --liste` | Montre la bibliothèque de modèles et les paquets |
| `whatsapp:templates {id} --pack=essentiel` | Crée un paquet de modèles sur le canal d'un assistant |
| `guides:build --url=https://kouma.site` | Fabrique les guides en PDF (A4, couverture, sommaire, signature). L'adresse publique est obligatoire : la commande refuse une adresse locale. À relancer après chaque changement d'un guide, puis envoyer les PDF de `public/documents/` avec la mise à jour |
| `analytics:prune`, `analytics:digest` | Nettoyage et résumé hebdomadaire des statistiques |
| `push:keys` | État des clés des notifications (jamais la clé privée) |
| `notifications:dispatch` | Envoie les campagnes et les notifications différées |

## Aller plus loin

La documentation technique du dépôt complète ce guide : `docs/ARCHITECTURE.md` (décisions d'architecture), `docs/DEPLOYMENT.md` (mise en ligne), `docs/WHATSAPP.md` (WhatsApp), `docs/SEO.md` (référencement), `docs/COUTS.md` (coûts), `docs/CATALOGUE.md` (catalogues) et `docs/LANGUES.md` (langues locales).
