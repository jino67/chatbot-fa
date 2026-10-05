# Changer de nom de domaine (kouma.site vers kouma-ai.com) et bien envoyer ses e-mails

Ce document sert au moment où le nouveau domaine est acheté. Il dit, dans l'ordre, ce qu'il faut régler, ce qui continue de marcher tout seul, et comment vérifier. Rien de ce qui est déjà en ligne ne doit casser : des sites de clients affichent le widget, des affiches avec QR code sont imprimées, des téléphones ont installé l'application.

## 1. Ce qui est déjà prévu dans le code

- `PLATFORM_LEGACY_HOSTS=kouma.site` (voir `config/platform.php`) : les **pages** de l'ancien nom sont redirigées (301) vers `APP_URL`, chemin et paramètres conservés (`RedirectLegacyHost`). Les anciens liens, favoris, **liens de discussion et QR codes imprimés** continuent de marcher.
- Ce qui n'est **jamais** redirigé sur l'ancien nom : `/widget/*` et `/api/*` (le widget des sites de vos clients), `/webhooks/*` (Meta, Twilio), `/media/*`, `/a/e`, `sw.js`, `manifest.webmanifest`, les icônes, `/up`. Les sites de vos clients et les applications déjà installées continuent donc de fonctionner.
- Le widget accepte l'ancien nom parmi les origines autorisées tant qu'il est déclaré.
- Les adresses générées (liens des e-mails, plan du site, liens canoniques, lien de discussion, QR codes, affiches) suivent `APP_URL` : elles changent toutes seules.
- Le nom d'expéditeur des e-mails ne dépend plus de `${APP_NAME}` (voir la section 4).

## 2. Avant de changer (sans rien casser)

1. **Acheter le domaine** et le faire pointer vers LWS (serveurs de noms de LWS, ou DNS chez le registrar avec les enregistrements indiqués par LWS).
2. Dans le panneau LWS : ajouter `kouma-ai.com` (et `www`) comme **domaine supplémentaire pointant vers le même dossier** que kouma.site, et activer le **certificat SSL** pour les deux.
3. **Messagerie** : créer la boîte `contact@kouma-ai.com`. Activer **DKIM** pour ce domaine dans le panneau, puis ajouter dans la zone DNS : MX, **SPF** (`v=spf1 ... ~all`, valeur donnée par LWS), **DKIM** (le TXT affiché), **DMARC** (`_dmarc` : `v=DMARC1; p=none; rua=mailto:contact@kouma-ai.com`). Vérifier avec `php artisan mail:check contact@kouma-ai.com` ou la page Administration, E-mails.
4. **Fournisseurs de connexion** : AJOUTER (sans retirer les anciennes) les nouvelles adresses de retour :
   - Google : `https://kouma-ai.com/auth/google/callback` (« URI de redirection autorisés »)
   - Apple : le domaine `kouma-ai.com` et `https://kouma-ai.com/auth/apple/callback` dans le Services ID
   - Facebook (Meta) : « URI de redirection OAuth valides » et « Domaines de l'application »
   - Microsoft : l'URI de redirection de type Web
5. Préparer une **période de chauffe** pour les e-mails : les premières semaines, peu de messages et seulement utiles (reçus, alertes, réponses à des demandes).

## 3. Le jour du changement

1. Dans `kouma/.env` : `APP_URL=https://kouma-ai.com`, `PLATFORM_LEGACY_HOSTS=kouma.site`, `MAIL_HOST=mail.kouma-ai.com`, `MAIL_USERNAME` et `MAIL_FROM_ADDRESS` = la nouvelle boîte, `MAIL_FROM_NAME="Kouma"`. Puis `php artisan config:clear`.
2. Administration, Paramètres : adresse du site (`brand.url`), e-mail de contact public, mentions légales si elles citent l'ancien nom.
3. `php artisan platform:landing-bot` : l'assistant de l'accueil réapprend les adresses du site.
4. Régénérer les PDF avec les nouvelles coordonnées : `php artisan guides:build --url=https://kouma-ai.com --email=contact@kouma-ai.com --whatsapp=...` (sur un poste avec Edge ou Chrome).
5. Téléverser les fichiers et vider les caches de la mise à jour habituelle.

## 4. E-mails : pourquoi ils tombent en indésirables, et quoi faire

La page **Administration, E-mails** (et `php artisan mail:check`) lit SPF, DKIM et DMARC du domaine d'envoi. Sur kouma.site, ces trois preuves existent déjà : le problème vient d'ailleurs.

| Cause | Ce qu'on fait |
|---|---|
| Nom d'expéditeur « ${APP_NAME} » (le .env n'interprétait pas la variable) | Corrigé : le nom vient de la marque (`MailSender`) ; écrire `MAIL_FROM_NAME="Kouma"` dans `.env` |
| Extension `.site` : très utilisée pour le courrier non sollicité, donc suspecte pour Gmail | Un domaine en `.com` (kouma-ai.com) |
| Domaine et adresse d'envoi récents, sans historique | Chauffe progressive ; demander aux premiers destinataires de répondre ou de marquer « Pas un spam » |
| Messages à ton commercial dès le premier envoi | Réserver les premiers envois aux messages de service ; garder un ton humain et court |
| Adresse d'envoi qui n'est pas celle du site | Toujours une boîte du même domaine que le site (la page E-mails le vérifie) |

Pour trancher sur un message précis : l'ouvrir dans Gmail, « Afficher l'original », lire `SPF: PASS`, `DKIM: PASS`, `DMARC: PASS`. Un seul `FAIL` explique le classement en indésirables.

Quand SPF et DKIM sont verts depuis deux semaines, passer DMARC à `p=quarantine`.

## 5. Après le changement

- **Search Console** : ajouter la nouvelle propriété (type « Domaine »), envoyer `https://kouma-ai.com/sitemap.xml`, et utiliser l'outil « Changement d'adresse » depuis l'ancienne propriété (les redirections 301 doivent être actives).
- **Meta et Twilio** : l'adresse des webhooks (`/webhooks/whatsapp/meta`, `/webhooks/whatsapp/twilio/{canal}`) peut rester sur l'ancien nom, qui les sert toujours ; la migrer plus tard, sans urgence.
- **Clients** : leur ligne de code existante (`kouma.site/widget/widget.js`) continue de marcher. La page Canaux donne maintenant la nouvelle ligne ; invitez-les à la remplacer à leur rythme. **Gardez kouma.site renouvelé** tant que des sites l'utilisent (au moins un an).
- **Applications installées et notifications** : l'ancienne application continue de recevoir les notifications (son service worker reste servi) ; chacun peut réinstaller depuis le nouveau nom quand il le souhaite. Les connexions ouvertes sur l'ancien nom demandent de se reconnecter une fois sur le nouveau.
- Vérifier : une page de l'ancien nom redirige ; un lien `kouma.site/chat/pk_...` s'ouvre sur le nouveau ; la connexion avec Google, Apple, Facebook fonctionne ; un e-mail d'essai arrive en boîte de réception.

## 6. Retour arrière

Remettre `APP_URL` et la messagerie d'avant, vider `PLATFORM_LEGACY_HOSTS`, `php artisan config:clear`. Aucune donnée n'est touchée par le changement.
