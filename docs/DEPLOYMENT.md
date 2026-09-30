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
