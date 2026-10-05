# Suivi des inscrits : la page Utilisateurs

La page `/admin/utilisateurs` (super administrateur seulement) montre toutes les personnes inscrites, où elles en sont, et permet de les contacter en gardant la trace. Elle va plus loin que « Espaces clients », qui gère l'entreprise : ici on suit la personne. Guide d'usage : guide du super admin, section 11.

## 1. Où est le code

| Fichier | Rôle |
|---|---|
| `Admin\PeopleController` | liste, fiche, contact, suivi, export, journal |
| `App\Services\Users\UserFilters` | segments, filtres et tri validés une seule fois (tout est dans l'adresse) |
| `App\Services\Users\UserQuery` | requêtes (SQL portable MySQL et SQLite), compteurs de segments, export |
| `App\Services\Users\UserStage` | le stade d'une personne et le message conseillé |
| `App\Support\ContactTemplates` | les modèles de message (e-mail et WhatsApp) |
| `App\Models\CustomerContact` | le journal des contacts |
| colonnes `users.crm_*` | statut, prochaine relance, responsable, dernier contact |

## 2. Le stade se déduit de ce qui existe

Un seul stade principal, le premier qui manque : profil à compléter, sans assistant, assistant vide, à tester, pas encore en ligne, en ligne (vraies conversations ou WhatsApp actif), payant (offre dont un prix est supérieur à zéro). Des signaux s'y ajoutent : essai qui finit (moins de 7 jours), essai terminé, dormant (14 jours sans activité sur un compte de plus de 14 jours), espace suspendu, compte désactivé. Le modèle de message conseillé suit : essai terminé ou qui finit, puis dormant, puis le stade.

L'activité vient de la mesure d'audience (`analytics_sessions.last_seen_at`) puis de `users.last_login_at`. La provenance d'une personne est la première visite de son navigateur (`visitor_key`), avant l'inscription comprise.

## 3. Contacter

| Moyen | Ce qui se passe |
|---|---|
| E-mail | gabarit de la marque (`Notice`), « Bonjour Prénom, » automatique, réponses à l'adresse de contact public (ou à la personne de l'équipe si elle n'est pas réglée) |
| WhatsApp | le contact est consigné puis la page redirige vers `wa.me/{numéro}?text=...` (le numéro doit porter l'indicatif) |
| Notification | `Notifier::toUser(... 'system' ...)` : dans la cloche, et sur les appareils activés |
| Appel, rendez-vous, note | seulement consignés |

Un envoi qui échoue n'est pas consigné. Après un contact : résultat (a répondu, intéressé, sans réponse, pas intéressé, a pris une offre), statut, prochaine relance. Le résultat fait avancer le statut (intéressé, client, perdu, en discussion) sauf si un statut est choisi à la main. Un contact fait disparaître une relance échue, sauf si une nouvelle date est donnée.

## 4. Règles

- Super administrateur seulement ; lecture par `DB::table` (les modèles sont filtrés par entreprise).
- Journal : `user.viewed` (une fois par heure et par personne), `user.contacted` (le canal et le résultat, jamais le texte), `user.crm_updated`, `user.exported`.
- « Ne plus contacter » (`crm_status = stop`) bloque e-mail, WhatsApp, notification et appel : seule une note reste possible.
- Les modèles n'écrivent jamais de prix (un test le vérifie) : ils renvoient vers la page Abonnement.
- `CustomerContact` et les colonnes `crm_*` ne s'affichent dans aucune page cliente (`PeopleTest`).
- Pas d'envoi de masse ici : pour une annonce, la page Notifications respecte les choix de chaque personne.
- Page Confidentialité : le texte indique que l'équipe peut écrire aux inscrits et qu'on cesse à leur demande.

## 5. Migration (`2026_10_05_000020_create_customer_followups`, additive)

`customer_contacts` (utilisateur, auteur, canal, objet, texte, résultat, modèle, date) et quatre colonnes nullables sur `users`. Aucune donnée existante n'est touchée.

## 6. Limites

- Pas de rappel automatique des relances (la liste « À relancer » se consulte) : une alerte e-mail du matin est une suite possible.
- Pas d'envoi groupé par segment.
- Le numéro WhatsApp est pris tel quel : sans indicatif, le lien `wa.me` ne s'ouvre pas sur la bonne personne.
