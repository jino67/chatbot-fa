# WhatsApp : choix du fournisseur et procédure d'activation

Ce document est destiné à l'**équipe technique** qui active WhatsApp pour un client, à sa demande. Il répond à deux questions : quel fournisseur choisir (Meta ou Twilio) et comment activer un canal de bout en bout.

> Les écrans et les règles de Meta évoluent souvent. Les faits ci-dessous viennent des documentations consultées le 29 septembre 2026 (liste dans [RECHERCHE.md](RECHERCHE.md)). Vérifiez toujours l'écran ou la page officielle avant une activation.

## 0. Qui paie les messages ?

**La plateforme, pas le client.** Les messages WhatsApp passent par les comptes de la plateforme (compte Meta Cloud API, ou compte Twilio approvisionné par un portefeuille) : le client n'a ni carte bancaire à saisir, ni compte à ouvrir chez Meta ou Twilio. Chaque offre inclut un **volume de messages par mois** (`whatsapp_messages_per_month`) ; au-delà, le client recharge par Mobile Money (lots de 1 000 messages, enregistrés par l'équipe) et le crédit acheté (`workspaces.wa_credit`) est consommé.

- Chaque message est enregistré dans `usage_events` avec son coût estimé (frais de l'intermédiaire Twilio + tarif Meta selon la catégorie). La page **Consommation** du super admin montre en temps réel ce que consomme chaque client, sa marge et le solde du portefeuille Twilio.
- Les identifiants de la plateforme se règlent dans **Administration, Paramètres, WhatsApp** (jeton Meta et identifiant du compte WhatsApp Business, ou SID et jeton Twilio). Un canal sans identifiants propres utilise ceux de la plateforme ; un canal qui en a (cas d'un client qui impose son propre compte) les utilise et supporte lui-même ses frais.
- **Meta direct est le moins cher** (aucun frais d'intermédiaire) ; Twilio ajoute environ 0,005 $ par message entrant et sortant, mais démarre plus vite. La décision par cas est plus bas.

## 1. Comment c'est branché dans le code

```
Client WhatsApp  <->  Meta  ou  Twilio  <->  /webhooks/whatsapp/...  ->  file d'attente  ->  ChatService
                                                                                     |
                                       WhatsAppGateway (MetaCloudGateway | TwilioGateway)  <-  réponse
```

- Un **canal** (`channels`) relie un assistant à un numéro. Son `type` vaut `whatsapp_meta` ou `whatsapp_twilio`.
- Changer de fournisseur pour un client = changer le type du canal et ses identifiants dans le back-office. Aucune modification de code.
- Un **seul webhook Meta** sert tous les clients : `POST /webhooks/whatsapp/meta`. Le champ `phone_number_id` du message désigne le canal.
- Twilio a **un webhook par canal** : `POST /webhooks/whatsapp/twilio/{id du canal}`.
- Les identifiants sont chiffrés en base et ne sont jamais réaffichés.

## 2. Meta ou Twilio ?

### 2.1 Comparatif

| | **Meta Cloud API** | **Twilio** |
|---|---|---|
| Surcoût par message (supporté par la plateforme) | Aucun | 0,005 USD par message reçu et par message envoyé, en plus des tarifs Meta |
| Webhooks | Un seul pour tous les clients | Un par client (à créer côté Twilio) |
| Délai de démarrage | Dépend de la vérification Meta | Court avec le bac à sable ; sinon même vérification Meta |
| Dépendances | Meta | Meta **et** Twilio |
| Numéros fournis | Non (le client apporte son numéro) | Oui, dans les pays où Twilio en propose |
| Bac à sable pour démonstration | Numéro d'essai fourni par Meta | Oui, immédiat |
| Propriété du numéro et du compte | Le client (son compte WhatsApp Business) | Le sous-compte Twilio que nous gérons |
| Facturation Meta | Sur le compte du client | Répercutée par Twilio |

### 2.2 Règle de décision

| Situation | Choix |
|---|---|
| Cas général : le client a un numéro, une entreprise identifiable | **Meta direct** |
| Volume élevé | **Meta direct** (les messages étant payés par la plateforme, le surcoût Twilio se retire de la marge : 1 000 conversations de 10 messages coûtent environ 50 USD par mois de plus) |
| Démonstration ou essai le jour même | **Twilio, bac à sable** |
| Le client a déjà un compte Twilio ou impose Twilio | **Twilio** |
| Le numéro ne peut pas être enregistré chez Meta en direct (problème de vérification, pays) | **Twilio** en secours, à valider cas par cas |
| Le client veut garder l'application WhatsApp Business sur son téléphone en parallèle du bot | Mode **coexistence** de Meta : exige le statut Tech Provider ou Solution Partner, donc **hors V1** |

### 2.3 Ce que ce choix évite en V1

Le libre-service (le client connecte lui-même son numéro) demande le statut **Tech Provider** de Meta : revue d'application pour l'accès avancé, vérification de l'entreprise, puis intégration d'**Embedded Signup**. Par défaut, on ne peut intégrer que 10 nouvelles entreprises par période glissante de 7 jours, portée à 200 après vérification. Chez Twilio, rejoindre ce programme demande d'abord d'obtenir l'approbation d'une application Meta liée comme « Partner Solution » (compter plusieurs semaines) et de connecter chaque WABA client à un sous-compte Twilio dédié.

Comme l'activation est faite par nous, à la demande, **rien de cela n'est nécessaire en V1**. C'est un chantier de V2 (et la version 2 d'Embedded Signup est retirée le 15 octobre 2026 : viser la version 4).

## 3. Règles WhatsApp qui contraignent le produit

| Règle | Conséquence dans Kouma |
|---|---|
| **Fenêtre de service de 24 h** : texte libre autorisé seulement dans les 24 h après le dernier message du client | `Conversation::isWithinServiceWindow()` ; la boîte de réception refuse l'envoi hors fenêtre et l'explique |
| **Messages modèles** approuvés par Meta pour écrire hors fenêtre (relances, notifications) | Non implémenté (V1.1) |
| **Politique sur l'IA** : les assistants généralistes sont interdits depuis le 15 janvier 2026 (dès le 15 octobre 2025 pour les nouveaux comptes) ; les assistants de support, de vente ou de notification propres à une entreprise restent autorisés | L'assistant ne répond que d'après les sources du client et refuse le hors-sujet. **Avis juridique à obtenir.** Ne jamais présenter Kouma comme un « ChatGPT sur WhatsApp » |
| **Tarification par message délivré** (depuis le 1er juillet 2025) | Suivi du coût par client ([COUTS.md](COUTS.md)) |
| **Changement du 1er octobre 2026** : les messages de service ne sont plus gratuits au-delà de 1 000 par numéro et par mois ; les réponses en modèle « utilitaire » dans la fenêtre deviennent payantes | À intégrer aux offres |
| **Limites d'envoi et note de qualité** du numéro | Surveiller dans WhatsApp Manager ; trop de blocages fait baisser le plafond |
| **Consentement** pour tout message initié par l'entreprise | Hors V1 (pas d'envoi initié) |

Tarif de référence rapporté pour le « reste de l'Afrique » (dont le Burkina Faso et les Comores selon le découpage de Meta) : environ 0,0225 USD par message marketing. Les tarifs « utilitaire » et « service » de cette zone n'ont pas été relevés : **consulter la grille officielle de Meta** avant de fixer un prix.

## 4. Procédure Meta Cloud API (cas général)

Durée : de quelques jours à quelques semaines selon la vérification.

### 4.1 Prérequis à demander au client

- [ ] Un portfolio Meta Business (ancien « Business Manager »), idéalement **vérifié** (documents d'enregistrement de l'entreprise) : sans vérification, plafonds d'envoi plus bas et nom d'affichage limité.
- [ ] Un **numéro** qui peut recevoir un SMS ou un appel vocal pour la vérification, et **qui n'est pas déjà enregistré** sur WhatsApp (application personnelle ou Business). Sinon : supprimer le compte WhatsApp de ce numéro (perte de l'historique) ou étudier la coexistence (hors V1).
- [ ] Un **nom d'affichage** conforme aux règles de Meta (lié au nom de l'entreprise).
- [ ] Rien à payer pour le client : le **moyen de paiement** est celui du compte WhatsApp Business de la plateforme. (Exception : un client qui impose son propre compte Meta y ajoute son moyen de paiement et renseigne ses propres identifiants sur le canal ; il supporte alors ses frais.)

### 4.2 Étapes

1. **Application Meta de Kouma** (une seule pour tous les clients). Type « Business », produit « WhatsApp » ajouté. Noter `META_APP_SECRET` (Paramètres, Général) dans le `.env` du serveur.
2. **Compte WhatsApp Business (WABA) et numéro** : dans WhatsApp Manager du client, ajouter le numéro et le vérifier (SMS ou appel). Soumettre le nom d'affichage. Si le numéro est ajouté par l'API plutôt que par l'interface, l'enregistrer avec `POST /{phone_number_id}/register` et un code PIN à 6 chiffres.
3. **Jeton permanent** : créer un **utilisateur système** dans le portfolio du client, lui attribuer le WABA avec le contrôle total, générer un jeton **sans expiration** avec les autorisations `whatsapp_business_messaging` et `whatsapp_business_management`. Un jeton d'utilisateur ordinaire expire et coupera le canal.
4. **Abonner l'application au WABA** : `POST https://graph.facebook.com/{version}/{waba_id}/subscribed_apps` avec ce jeton.
5. **Webhook de l'application** (une seule fois, pour tous les clients) : dans la configuration WhatsApp de l'application, URL de rappel `https://<votre-domaine>/webhooks/whatsapp/meta`, jeton de vérification identique à `META_VERIFY_TOKEN`, puis abonner le champ **`messages`**. Meta appelle alors l'URL en GET : Kouma renvoie le défi si le jeton correspond.
6. **Back-office Kouma** : *Demandes WhatsApp*, ouvrir la demande, fournisseur « Meta Cloud API », saisir le `phone_number_id` (Meta, Configuration de l'API) et le jeton. **Enregistrer**, puis **Tester la connexion** : la réponse doit afficher le numéro et le nom vérifié.
7. Passer l'état du canal à **Actif** et le statut de la demande à **Activée** (le client voit le changement).
8. **Essai réel** : écrire depuis un téléphone au numéro, vérifier la réponse et la conversation dans la boîte de réception.

### 4.3 Tant que le numéro final n'est pas prêt

Meta fournit un numéro d'essai et une liste de destinataires autorisés : suffisant pour valider toute la chaîne (webhook, signature, réponse) avant d'engager le client.

## 5. Procédure Twilio (secours ou démonstration)

1. Compte Twilio (un **sous-compte par client** est recommandé pour l'isolement et la facturation).
2. **Démonstration immédiate** : activer le bac à sable WhatsApp de Twilio, rejoindre le bac à sable depuis un téléphone avec le mot-code indiqué.
3. **Numéro réel** : enregistrer un expéditeur WhatsApp dans la console Twilio (connexion Facebook, WABA du client). Pour une intégration à grande échelle, voir le programme Tech Provider et l'API Senders (hors V1).
4. Back-office Kouma : fournisseur « Twilio », saisir Account SID, Auth Token et le numéro expéditeur (ou un Messaging Service SID). **Enregistrer** : l'URL du webhook s'affiche.
5. Console Twilio : dans « When a message comes in », coller cette URL en **POST** (`https://<domaine>/webhooks/whatsapp/twilio/{id}`).
6. **Tester la connexion**, activer, essayer.
7. Derrière un proxy ou un équilibreur qui change l'URL vue par l'application, renseigner `TWILIO_WEBHOOK_BASE_URL` : la signature Twilio est calculée sur l'URL exacte que Twilio a appelée.

## 6. Variables d'environnement

| Variable | Rôle |
|---|---|
| `META_APP_SECRET` | Vérifier la signature des webhooks Meta. **Sans elle, tous les appels Meta sont refusés** |
| `META_VERIFY_TOKEN` | Jeton choisi par nous, saisi chez Meta pour l'abonnement |
| `META_GRAPH_VERSION` | Version de l'API Graph (par défaut `v23.0`). Meta retire les anciennes versions : mettre à jour périodiquement |
| `TWILIO_WEBHOOK_BASE_URL` | URL publique, seulement derrière un proxy |
| `QUEUE_CONNECTION` | **Ne pas laisser `sync` en production** : préférer `database` ou `redis` avec un worker lancé |
| `PLATFORM_ADMIN_EMAIL` | Adresse prévenue à chaque demande d'activation |

## 7. Dépannage

| Symptôme | Cause probable | Action |
|---|---|---|
| Meta : « impossible de valider l'URL de rappel » | `META_VERIFY_TOKEN` absent ou différent, URL non publique | Vérifier le `.env` ; `php artisan config:clear` ; essayer l'URL en GET avec les paramètres `hub.*` |
| Webhook Meta reçu, aucun message dans Kouma : réponse 401 | `META_APP_SECRET` absent ou incorrect ; un proxy modifie le corps | Corriger le secret ; le corps doit arriver intact |
| Webhook reçu, aucune réponse envoyée | **Aucun worker** avec `QUEUE_CONNECTION=database` | Lancer `php artisan queue:work` (voir [DEPLOYMENT.md](DEPLOYMENT.md)) |
| Réponse jamais reçue par le client, message marqué « non livré » | Jeton expiré ou refusé | Test de connexion ; regénérer un jeton système sans expiration |
| Erreur Meta 131047 | Message libre hors de la fenêtre de 24 h | Attendre un message du client, ou utiliser un modèle (V1.1) |
| Erreur Meta 131030 | Destinataire absent de la liste autorisée du numéro d'essai | Ajouter le numéro ou passer en production |
| Twilio : 401 sur le webhook | URL vue par l'application différente de celle appelée par Twilio (http/https, proxy) | Renseigner `TWILIO_WEBHOOK_BASE_URL` |
| Le bot répond deux fois | Doublon de livraison sans identifiant stable | Vérifier l'index unique ; consulter les journaux |
| Le bot ne répond jamais à un client | La conversation est confiée à un humain (`human` ou `needs_human`) | La rendre au bot depuis la boîte de réception |

## 8. Sécurité : ce qui est fait et ce qui reste à votre charge

**Fait dans le code** : signatures vérifiées (HMAC-SHA256 pour Meta, HMAC-SHA1 pour Twilio, refus si le secret est absent) ; comparaison en temps constant ; identifiants chiffrés ; doublons ignorés ; un webhook Twilio ne peut pas viser un canal Meta ; réponse rapide au fournisseur.

**À votre charge** : servir l'application en HTTPS ; limiter l'accès au back-office ; sauvegarder `APP_KEY` (sans elle, les identifiants chiffrés sont illisibles) ; faire tourner les jetons en cas de départ d'un membre de l'équipe ; suivre la note de qualité des numéros.


## 9. Modèles de messages : bibliothèque et création automatique

Un modèle est un message approuvé par WhatsApp, indispensable pour écrire à un client au-delà de 24 heures sans réponse de sa part (rappel de rendez-vous, suivi de commande, relance). Kouma les crée **sans passer par la console du fournisseur**.

**Ce qui existe**
- Une **bibliothèque de 28 modèles** (`config/whatsapp_templates.php`), écrits pour des petites entreprises : relation client, commandes et livraisons, paiements, rendez-vous et réservations, devis et interventions, promotions. 12 existent aussi en anglais. Le nom de l'entreprise y est déjà inscrit ; un bouton « appeler » ou « site » disparaît si l'entreprise n'a pas de numéro ou de site.
- Des **paquets par métier** (essentiel, boutique, restaurant, rendez-vous, hôtel, services). Le paquet conseillé suit le secteur de l'assistant (`pack_by_sector`).
- L'écran **Canaux, Modèles** : aperçu en bulle WhatsApp, ajout d'un modèle, de plusieurs, ou d'un paquet entier ; statut de chacun (en attente, approuvé, refusé). Réservé aux offres qui incluent les modèles (Pro, Business).
- **Création automatique à l'activation d'un canal** : le paquet « essentiel » et celui du métier partent à l'approbation, sans rien demander au client (`WHATSAPP_AUTO_TEMPLATES=false` pour couper).
- Meta : création par l'API Graph. **Twilio : création par l'API Content** (le contenu, puis la demande d'approbation WhatsApp). Si l'approbation échoue, le contenu créé est supprimé.
- En ligne de commande (essai de bout en bout) : `php artisan whatsapp:templates --liste`, puis `php artisan whatsapp:templates {id de l'assistant} --pack=essentiel` ou `--cle=rdv_rappel`.

**Règles de Meta respectées par chaque modèle** (vérifiées par `WhatsAppTemplateLibraryTest`) : variables numérotées, jamais en première ni en dernière position ; un exemple par variable ; corps de 1024 caractères, pied de 60, bouton de 25 ; pas de titre ; un modèle « utilitaire » parle d'une commande, d'un rendez-vous ou d'un paiement et jamais d'une promotion ; un modèle « marketing » rappelle comment ne plus recevoir d'offres.

**Ce qu'il faut savoir**
- L'approbation prend de quelques minutes à quelques heures. Meta peut reclasser un modèle « utilitaire » en « marketing » (plus cher) ou le refuser : le motif s'affiche après « Actualiser les statuts ». Corriger le texte, puis recréer sous un autre nom.
- Un modèle « marketing » ne s'envoie qu'à un client qui a accepté de recevoir des offres.
- Twilio ne permet pas de mélanger boutons de réponse rapide et boutons lien ou appel dans un même modèle. Il n'a ni titre ni pied de message : Kouma écrit le pied en italique à la fin du texte.
- Les envois **entièrement automatiques** (par exemple « commande confirmée » qui part sans personne) ne sont pas branchés, et c'est voulu : le modèle demande un numéro de commande, un montant, une heure, que seul le commerçant connaît ; un envoi sans lui risquerait d'annoncer une information fausse. À la place, la page Demandes propose un bouton **Confirmer la commande / Confirmer le rendez-vous / Envoyer le devis** (`Lead::CONFIRMATIONS`) : la conversation s'ouvre avec le bon modèle déjà choisi (`?modele=`) et le prénom rempli ; il reste à compléter les variables et à envoyer. Le bouton n'apparaît que si le modèle est approuvé sur le canal de l'assistant.

### 9.1 Essai de l'assistant Kouma avec un sous-compte Twilio

1. Console Twilio : créer un **sous-compte** (par exemple « Kouma essai »). Noter son Account SID et son Auth Token.
2. Enregistrer l'expéditeur WhatsApp du sous-compte (menu Messaging, Senders, WhatsApp senders) : numéro, compte Meta Business, **nom d'affichage** (par exemple « Kouma ») que Meta doit approuver. Le bac à sable suffit pour tester les conversations, mais pas pour faire approuver des modèles : il faut un vrai expéditeur.
3. Administration Kouma, Paramètres, WhatsApp : saisir le SID et le jeton **du sous-compte**.
4. Entrer dans l'espace « Kouma (vitrine) », Canaux de l'assistant, demander WhatsApp, puis dans Administration, Demandes WhatsApp : fournisseur Twilio, numéro expéditeur, **Tester la connexion**, activer. Les modèles de base partent alors à l'approbation d'eux-mêmes.
5. Copier l'adresse du webhook affichée (`https://kouma.site/webhooks/whatsapp/twilio/{id}`) dans le champ « When a message comes in » de l'expéditeur, en POST.
6. Quelques minutes plus tard, **Actualiser les statuts** sur l'écran des modèles, puis écrire au numéro pour essayer l'assistant.
