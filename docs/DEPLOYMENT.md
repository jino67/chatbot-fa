# Déploiement en production

Liste de contrôle pour passer du prototype à un serveur. Elle suppose un serveur Linux avec PHP 8.2 ou plus, Nginx (ou Apache), PostgreSQL et un accès HTTPS.

## 1. Prérequis

- PHP 8.2 ou plus, extensions : `mbstring`, `openssl`, `pdo_pgsql` (ou `pdo_mysql`), `curl`, `dom`, `xml`, `zip`, `gd`, `fileinfo`, `intl`.
- Composer 2, Node 20 (pour compiler les assets, pas nécessaire à l'exécution).
- Un nom de domaine avec certificat HTTPS (obligatoire : WhatsApp et les navigateurs l'exigent).

## 2. Installation

```bash
git clone https://github.com/jino67/chatbot-fa.git kouma && cd kouma
composer install --no-dev --optimize-autoloader
npm ci && npm run build
cp .env.example .env && php artisan key:generate
# renseigner .env (voir section 3), puis :
php artisan migrate --force
php artisan storage:link   # inutile tant que les fichiers restent sur le disque privé
php artisan config:cache && php artisan route:cache && php artisan view:cache
php artisan platform:make-admin equipe@votre-domaine.com --name="Équipe technique"
```

**Ne lancez jamais `DemoSeeder` en production** (il refuse de tourner quand `APP_ENV=production`).

## 3. Variables d'environnement essentielles

| Variable | Valeur production |
|---|---|
| `APP_ENV` | `production` |
| `APP_DEBUG` | `false` |
| `APP_URL` | URL publique en HTTPS |
| `DB_CONNECTION` | `pgsql` (voir section 6) |
| `QUEUE_CONNECTION` | `database` ou `redis` (**pas** `sync`) |
| `CACHE_STORE`, `SESSION_DRIVER` | `redis` ou `database` |
| `MAIL_*` | Un vrai service d'envoi (les transferts humains passent par e-mail) |
| `ANTHROPIC_API_KEY`, `PLATFORM_MODEL` | Clé et modèle choisi |
| `VOYAGE_API_KEY` (ou `OPENAI_API_KEY`) | Embeddings réels ; puis `PLATFORM_MIN_SCORE` à environ 0,35 à recaler sur le jeu de questions |
| `META_APP_SECRET`, `META_VERIFY_TOKEN` | Voir [WHATSAPP.md](WHATSAPP.md) |
| `PLATFORM_ADMIN_EMAIL` | Adresse de l'équipe technique |

Changer de modèle d'embeddings impose de recalculer les vecteurs : `php artisan platform:reindex`.

## 4. Processus à faire tourner

| Processus | Rôle | Comment |
|---|---|---|
| Serveur web | Application | Nginx + PHP-FPM ; racine sur `public/` |
| **Worker de file** | Ingestion des sources, réponses WhatsApp | `php artisan queue:work --tries=2 --timeout=900` sous Supervisor ou systemd, redémarré au déploiement (`php artisan queue:restart`) |
| **Planificateur** | Mise à jour automatique des sites | Cron : `* * * * * cd /chemin && php artisan schedule:run >> /dev/null 2>&1` |

Sans worker, les sources restent « en attente » et WhatsApp ne répond pas : c'est la panne la plus fréquente.

Exemple Supervisor :

```ini
[program:kouma-worker]
command=php /chemin/kouma/artisan queue:work --sleep=2 --tries=2 --timeout=900
autostart=true
autorestart=true
numprocs=2
user=www-data
stopwaitsecs=920
redirect_stderr=true
stdout_logfile=/var/log/kouma-worker.log
```

## 5. Nginx : points d'attention

- Derrière un équilibreur ou un proxy, déclarer les proxys de confiance (`TrustProxies`) pour que l'application voie le bon schéma et la bonne adresse IP (la limitation de débit se fait par IP).
- Si vous ajoutez des réponses en flux continu (SSE), désactiver la mise en tampon sur cette route : `proxy_buffering off;` ou l'en-tête `X-Accel-Buffering: no`.
- `client_max_body_size 25M;` pour permettre les téléversements de 20 Mo.
- Ne servez **jamais** le dossier `storage/` : les fichiers des clients sont sur le disque privé.
- Cache long sur `public/build/` et `public/widget/widget.js` (versionner l'URL du widget si vous le modifiez : `widget.js?v=2`).

## 6. Passer de SQLite à PostgreSQL

1. Créer la base et l'utilisateur ; renseigner `DB_*` ; `php artisan migrate --force`.
2. Les vecteurs sont aujourd'hui stockés en texte (base64) : la recherche fonctionne sur PostgreSQL tel quel, mais reste calculée en PHP. Pour l'échelle, implémenter `HybridStore` avec **pgvector** (colonne `vector`, index HNSW) et `tsvector` pour la recherche par mots : voir [ARCHITECTURE.md](ARCHITECTURE.md), D3.
3. Ajouter la sécurité au niveau des lignes (RLS) avec `FORCE ROW LEVEL SECURITY` et `SET LOCAL app.tenant_id` par transaction ; le rôle de sauvegarde doit avoir `BYPASSRLS`. Cela ne remplace pas le filtrage applicatif : c'est un second verrou.

## 7. Sauvegardes et reprise

- Sauvegarde quotidienne de la base **et** du dossier `storage/app` (fichiers des clients), conservée hors du serveur.
- Sauvegarde de `APP_KEY` dans un coffre de secrets : **sans elle, les identifiants WhatsApp chiffrés sont perdus**.
- Essai de restauration avant la mise en production (critère de recette n° 9 du TDR).

## 8. Avant d'ouvrir à un client

- [ ] HTTPS actif, `APP_DEBUG=false`.
- [ ] Worker et planificateur en marche, vérifiés par l'ajout d'une source de test.
- [ ] Clé IA réelle, coût par réponse mesuré sur 20 questions.
- [ ] Liste blanche de domaines renseignée pour chaque assistant.
- [ ] Webhook Meta vérifié, signature acceptée, message de test répondu.
- [ ] E-mail de transfert humain reçu.
- [ ] Mentions d'information sur les données personnelles rédigées avec un juriste.
- [ ] Sauvegarde restaurée avec succès.
- [ ] Deux personnes savent activer un canal WhatsApp avec la procédure écrite.

## 9. Hébergement mutualisé (cPanel, DirectAdmin et équivalents)

Faisable pour démarrer (quelques dizaines de clients), avec ces particularités.

1. **PHP 8.2 ou plus** choisi dans le panneau de l'hébergeur, avec les extensions de la section 1 (surtout `mbstring`, `openssl`, `pdo_sqlite` ou `pdo_mysql`, `zip`, `gd`, `intl`) et `memory_limit` d'au moins 256 Mo.
2. **Racine du site sur `public/`** : déposez le projet hors du dossier web (par exemple `~/kouma`) et faites pointer le domaine sur `~/kouma/public` (option « racine du document »), ou remplacez `public_html` par un lien vers `kouma/public`. Ne jamais laisser `.env`, `vendor/` ou `storage/` accessibles par le web.
3. **Composer et Node en local** si l'hébergeur n'a pas de terminal : lancez `composer install --no-dev --optimize-autoloader` et `npm run build` sur votre poste, puis envoyez `vendor/` et `public/build/` avec le reste.
4. **`.env` de production** : `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://kouma.site`, `QUEUE_CONNECTION=database`, `SESSION_DRIVER=database`, `CACHE_STORE=database`. La base SQLite (un fichier `database/database.sqlite`, droits d'écriture) suffit pour démarrer ; passez à MySQL ou PostgreSQL quand le volume grandit.
5. **Tâches planifiées et file d'attente par cron** (pas de processus permanent sur un mutualisé). Deux lignes, à la minute :

   ```
   * * * * * cd ~/kouma && php artisan schedule:run >> /dev/null 2>&1
   * * * * * cd ~/kouma && php artisan queue:work --stop-when-empty --max-time=55 >> /dev/null 2>&1
   ```

   `schedule:run` déclenche les rappels de demandes (toutes les 10 minutes), les relances d'abonnement, la surveillance du portefeuille Twilio et les mises à jour de sites.
6. **HTTPS** : activez le certificat gratuit de l'hébergeur (AutoSSL ou Let's Encrypt). Le fichier `public/.htaccess` redirige déjà `http` et `www` vers `https://kouma.site` : c'est l'adresse unique du site, utile au référencement. Le service worker de l'application installable exige HTTPS.
7. **Droits** : `storage/` et `bootstrap/cache/` en écriture pour le serveur (775).
8. **Webhooks WhatsApp** : adresses publiques en HTTPS, `https://kouma.site/webhooks/whatsapp/meta` (Meta) et `https://kouma.site/webhooks/whatsapp/twilio/{id}` (Twilio). Si le site est derrière un proxy, renseignez `TWILIO_WEBHOOK_BASE_URL`.

### 9.1 E-mail du domaine (boîte `contac@kouma.site`)

C'est par cette boîte que partent les alertes de demandes, les rappels, les relances d'abonnement et les réinitialisations de mot de passe.

1. Dans le panneau de l'hébergeur : créer la boîte (type « Boîte mail classique »), noter l'adresse complète et le mot de passe. **Un mot de passe affiché à l'écran ou envoyé dans une conversation doit être changé** ; ne le placez que dans `.env`.
2. Renseigner `.env` : `MAIL_MAILER=smtp`, `MAIL_HOST=mail.kouma.site` (le serveur sortant indiqué par l'hébergeur), `MAIL_PORT=465` (SSL) ou `587` (TLS), `MAIL_USERNAME=contac@kouma.site`, `MAIL_PASSWORD=...`, `MAIL_FROM_ADDRESS="contac@kouma.site"`. L'expéditeur doit être la boîte elle-même : l'hébergeur refuse un autre expéditeur.
3. Essayer : `php artisan platform:mail-test vous@exemple.com`. La commande explique la panne probable (serveur ou port, identifiants, expéditeur refusé, chiffrement).
4. **Délivrabilité** (pour ne pas finir en courrier indésirable) : activez **SPF** et **DKIM** pour le domaine (la plupart des panneaux ont un bouton « Authentification e-mail » ou « Email Deliverability » qui ajoute les enregistrements DNS) et ajoutez un enregistrement **DMARC** simple : `_dmarc.kouma.site` en TXT, `v=DMARC1; p=none; rua=mailto:contac@kouma.site`. Testez l'envoi vers une adresse Gmail et une adresse Outlook.
5. Adresse de contact affichée sur le site (pied de page, bouton « Écrire par e-mail », pages juridiques) : `BRAND_EMAIL=contac@kouma.site` dans `.env`, ou Administration, Paramètres, « E-mail de contact » (prioritaire). La boîte créée chez LWS s'appelle `contac` (sans « t ») : pour afficher `contact@kouma.site`, créer cette adresse comme **redirection** vers `contac@` dans le panneau LWS, puis mettre `contact@` dans `BRAND_EMAIL`. L'expéditeur des e-mails (`MAIL_FROM_ADDRESS`) doit rester la vraie boîte.
6. **Limites d'envoi** : un mutualisé plafonne les e-mails par heure (souvent 100 à 500). Les alertes de demandes restent très en dessous ; en cas de forte croissance, passer à un service d'envoi transactionnel (MAIL_MAILER configuré sans changer le code).

### 9.2 Mise en ligne et référencement

Une fois le site en ligne : suivre la liste de [SEO.md](SEO.md) (propriété Search Console, sitemap, vérification des aperçus de partage).

## 10. Paquet prêt à téléverser chez LWS (`scripts/build-lws-package.php`)

Pour LWS (mutualisé, base MySQL fournie, pas de SSH nécessaire), un script construit le paquet complet, sur le modèle d'Amical Clinic :

```
KOUMA_DB_PASSWORD=... MAIL_PASSWORD_PRODUCTION=... php scripts/build-lws-package.php
```

Les mots de passe passent par variables d'environnement : aucun n'est écrit dans le dépôt. Le nom et l'identifiant de la base sont ceux de LWS (`KOUMA_DB_NAME`, `KOUMA_DB_USER`, `KOUMA_DB_HOST` pour les changer). Prérequis sur le poste qui construit : `npm run build` fait, MariaDB de WAMP (`MARIADB_BIN`), Composer (`COMPOSER_PHAR`), extensions PHP `zip`, `curl`, `pdo_mysql`.

Le script écrit, **hors du dépôt**, dans `../kouma-lws/` :

| Fichier | Rôle |
|---|---|
| `kouma.site-lws.zip` | À décompresser à la racine du dossier du domaine. Il contient `.htaccess`, `index.php`, `build/`, `widget/`, les icônes, et le dossier `kouma/` (application, `vendor` de production, `.env` de production). |
| `kouma-base.sql` | À importer une fois dans phpMyAdmin : tables, offres, administrateur, assistant de la page d'accueil. Il commence par `USE` suivi du nom de la base, donc il s'importe aussi depuis l'onglet du serveur (sans cela : « #1046 Aucune base n'a été sélectionnée »). |
| `identifiants-admin.txt` | Compte administrateur (`contac@kouma.site`) et son mot de passe généré. |
| `LISEZ-MOI.txt` | La marche à suivre, étape par étape. |

Avant de zipper, le script **essaie le montage exact du serveur** : il crée une base MySQL temporaire, y importe le `.sql`, sert le site avec le serveur intégré de PHP (le dossier du domaine est le dossier public, `kouma/` est interdit), puis vérifie une vingtaine de pages, la connexion de l'administrateur, les pages protégées et une vraie réponse de l'assistant. Un seul écart arrête la construction et rien n'est livré.

À savoir :

- `.htaccess` (de `deploiement/lws/`) force `https` et le domaine sans `www`, interdit `kouma/`, compresse et met en cache. `index.php` pointe vers `kouma/` et déclare la racine du domaine comme dossier public.
- La file d'attente est en `sync` : sans processus permanent, les réponses WhatsApp et la lecture des documents se font pendant la requête. Le seul travail de fond est la tâche planifiée `schedule:run` (une ligne dans « Tâches CRON »).
- Le `.env` du paquet reprend les clés d'IA du `.env` local. Le zip contient donc des secrets : ne jamais le déposer dans un dépôt ni l'envoyer. **Une fois extrait, le supprimer du serveur** : laissé dans le dossier du domaine, il est téléchargeable par n'importe qui (`.htaccess` refuse désormais `.zip`, mais ce n'est qu'une seconde barrière). Si cela est arrivé : supprimer le fichier, puis changer le mot de passe MySQL, la clé OpenAI et le mot de passe de la boîte e-mail, et reporter les valeurs dans `kouma/.env`.
- Après le premier déploiement, une mise à jour remplace `kouma/app`, `kouma/resources`, `kouma/routes`, `kouma/config`, `kouma/database` et `build/`, sans toucher à `kouma/.env` ni à `kouma/storage`. Les nouvelles tables se créent avec `php artisan migrate --force` (SSH) ou avec un nouveau `.sql`.
- **Mise à jour sans tout reconstruire** : `php scripts/build-lws-update.php` écrit `../kouma-lws/mise-a-jour.zip` avec les seuls fichiers modifiés depuis le dernier commit (et `build/`). À extraire à la racine du domaine en écrasant ; aucun secret dedans. Il refuse de partir si une migration est en cours, car elle ne passe pas par un zip.
- **Tâche planifiée** : `kouma/cron.php` (copié depuis `deploiement/lws/cron.php`) équivaut à `php artisan schedule:run` pour un panneau qui n'accepte qu'un fichier PHP sans arguments ; il refuse tout appel venant du web.
- Le contenu de l'assistant de la page d'accueil (prix, langues, contact) vient des offres : `php artisan platform:landing-bot` le met à jour. Enregistrer une offre dans l'administration le fait aussi.
