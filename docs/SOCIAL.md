# Connexion avec Google, Apple, Microsoft et Facebook

Les personnes s'inscrivent et se connectent en un clic, puis donnent leur entreprise et leur numéro WhatsApp. Ce document dit comment c'est fait, les règles de sécurité, et comment régler chaque fournisseur. Le pas à pas destiné à l'équipe est dans le guide du super admin (section 12).

## 1. Parcours

1. `GET /auth/{fournisseur}/redirect` : `SocialLogin::begin` crée un **état à usage unique** (cache, 10 minutes), un nonce, une clé PKCE (S256) et un cookie `kouma_oauth` qui lie la demande au navigateur, puis redirige vers le fournisseur.
2. Retour sur `/auth/{fournisseur}/callback` (GET, ou POST pour Apple) : `SocialLogin::consume` refuse un état inconnu, déjà utilisé, d'un autre fournisseur ou venu d'un autre navigateur. Le code est échangé **par le serveur** contre un jeton d'identité (`OidcProvider::identity`).
3. `SocialSignIn::handle` décide : connecter, relier, créer, demander le mot de passe, demander l'adresse.
4. Un compte nouveau passe par `/compte/terminer` (`AccountCompletionController`) : entreprise, numéro WhatsApp, pays, acceptation des conditions. `EnsureWorkspaceContext` garde l'espace fermé tant que `users.needs_profile` est vrai.

## 2. Règles de sécurité (ne pas assouplir)

| Règle | Où | Pourquoi |
|---|---|---|
| État à usage unique + cookie de liaison | `SocialLogin` | Empêche de faire terminer une connexion par un autre navigateur (« login CSRF »), et le rejeu |
| PKCE (Google, Microsoft) | `OidcProvider` | Un code volé ne sert à rien sans le secret du départ |
| Jeton d'identité reçu du fournisseur, signature non relue | `Jwt` | La norme OpenID Connect (3.1.3.7) l'autorise quand le jeton vient directement du point d'échange en HTTPS ; destinataire, émetteur, expiration et nonce sont vérifiés |
| Aucune liaison automatique à un compte de l'équipe | `SocialSignIn` | L'équipe relie depuis son profil, une fois connectée |
| Liaison par adresse seulement si le fournisseur la certifie ET que le compte n'a pas de mot de passe | `SocialSignIn` | Sinon, quelqu'un qui s'est inscrit avec l'adresse d'autrui hériterait de son compte : on exige le mot de passe, limité à 5 essais par minute |
| Microsoft et Facebook ne certifient jamais l'adresse | providers | Leur adresse peut ne pas être vérifiée |
| Aucun jeton conservé | `SocialAccount` | Seuls l'identifiant chez le fournisseur, l'e-mail, le nom et la photo sont gardés |
| Dernier moyen de connexion non retirable | `SocialAccountController` | Éviter un compte sans accès |
| Retour d'Apple hors CSRF | `bootstrap/app.php` | Apple renvoie par un POST venu d'un autre site ; la route est protégée par l'état et le cookie |

## 3. Données (migration `2026_10_05_000010_create_social_login`, additive)

- `social_accounts` : `user_id`, `provider`, `provider_user_id` (unique ensemble), `email`, `name`, `avatar_url`, `last_login_at`.
- `users.has_password` (faux tant qu'aucun mot de passe n'est choisi), `users.needs_profile`, `users.signup_source` (vide pour les comptes d'avant : on lit « e-mail »).

Les inscrits existants ne sont jamais modifiés.

## 4. Réglage

Administration, Paramètres, « Connexion avec Google, Apple, Microsoft, Facebook ». Les valeurs sont dans `PlatformSettings` (`social.google.client_id`, `social.google.client_secret`, ...), les secrets chiffrés et jamais réaffichés ; `config/social.php` donne des valeurs de secours par variables d'environnement. Facebook réutilise l'application Meta des pages (`facebook.app_id`, `facebook.app_secret`) et exige la case `social.facebook.login`.

Adresses de retour à déclarer chez chaque fournisseur : `{APP_URL}/auth/google/callback`, `/auth/apple/callback`, `/auth/microsoft/callback`, `/auth/facebook/callback`. **Après un changement de domaine, ajoutez les nouvelles adresses AVANT de changer APP_URL** (voir `docs/DOMAINE.md`).

## 5. Particularités

- **Apple** : le secret client est un jeton ES256 signé avec la clé `.p8` (`AppleProvider::clientSecret`, valable 5 minutes). Le nom n'arrive que la première fois, dans le formulaire de retour. L'adresse peut être un relais `@privaterelay.appleid.com` : la page « terminer mon profil » propose l'adresse habituelle, et la fiche d'administration prévient.
- **Microsoft** : locataire `common` par défaut (comptes personnels et professionnels). L'émetteur contient l'identifiant du locataire.
- **Facebook** : pas OpenID Connect : jeton puis lecture du profil avec `appsecret_proof`. L'application Meta doit être en mode Production.
- **Google** : l'écran de consentement doit être publié, sinon seuls les testeurs se connectent.

## 6. Tests

`SocialLoginTest` (29 cas) : boutons, départ, création, profil à compléter, retour d'un autre navigateur, état rejoué ou expiré, revendications fausses, comptes existants, équipe, compte désactivé, Apple (POST sans CSRF, secret signé), Microsoft, Facebook sans adresse, liaisons du profil, mot de passe, réglages chiffrés.

## 7. Limites

- Aucun essai avec de vrais comptes n'est fait par les tests (aucun appel réseau) : à chaque nouveau fournisseur réglé, essayer en navigation privée.
- Le secret client Microsoft expire (24 mois au plus) ; la clé Apple n'expire pas mais ne se télécharge qu'une fois.
- Pas de GitHub, LinkedIn ni X : `SocialLogin::PROVIDERS` et une classe dérivée de `OidcProvider` suffisent à en ajouter un.
