# Statistiques : mesure d'audience maison

Kouma compte lui-même ses visiteurs et l'activité de ses clients. Aucun service tiers, aucune adresse IP gardée, aucun cookie publicitaire. Ce document dit ce qui est mesuré, comment, et où se trouvent les chiffres.

## 1. Ce qui est mesuré

| Public | Ce qu'on sait | D'où ça vient |
|---|---|---|
| **Visiteurs** (non connectés) | pages vues, provenance (accès direct, moteur, réseau social, assistant d'IA, e-mail, campagne, autre site), appareil, navigateur, langue, pays estimé, clics, défilement, sections lues, temps actif, formulaires, erreurs, vitesse | script `resources/js/analytics.js` |
| **Clients connectés** | les mêmes gestes dans l'application, plus les **actions** (créer un assistant, ajouter une source, répondre à un client, choisir ses alertes...) | script + `RecordActions` |
| **L'équipe** (admin, super admin) | rien n'est compté dans les chiffres (public « staff » exclu) ; la console `/admin` n'est pas mesurée | |

Les clients de nos clients (ceux qui discutent avec un assistant) ne sont **pas** mesurés par ce module : leurs conversations restent régies par la page Confidentialité et par le périmètre de chaque entreprise.

## 2. Ce qui n'est jamais enregistré

- L'adresse IP (elle ne sert qu'à limiter le débit du collecteur, 120 lots par minute, et n'est pas stockée).
- Ce qu'une personne tape dans un champ : le script ne lit que le libellé d'un bouton ou d'un lien.
- Le contenu des conversations avec un assistant.
- Les adresses e-mail et les numéros de téléphone qui apparaîtraient dans un libellé ou une erreur : `Tracker::scrub` les remplace par `[e-mail]` et `[numéro]` avant l'écriture.
- Les paramètres des adresses (`?token=...`) : seul le chemin est gardé, et les identifiants sont remplacés (`/bots/12/sources` devient `/bots/:id/sources`).

## 3. Vie privée : ce qui coupe la mesure

Le script ne démarre pas, et le collecteur ignore tout lot, quand :

- le navigateur envoie **« Ne pas suivre »** (DNT) ou **Global Privacy Control** (`Sec-GPC`) ;
- le visiteur a refusé sur la page Confidentialité (cookie `_ko`, bouton « Ne plus me mesurer ») ;
- l'agent est un robot (liste `bots` de `config/analytics.php`) ;
- le lot vient d'une autre origine que le site ;
- la plateforme a désactivé la mesure (Paramètres, Statistiques) : la balise `<meta name="kouma-analytics">` n'est alors plus écrite dans les pages.

Cookies posés (lisibles par le serveur, exclus du chiffrement) :

| Cookie | Rôle | Durée |
|---|---|---|
| `_kv` | identifiant aléatoire du navigateur, pour compter les visiteurs qui reviennent | 13 mois |
| `_ks` | identifiant de la visite | fermeture du navigateur (30 minutes sans geste ouvrent une nouvelle visite) |
| `_ko` | refus de la mesure | 13 mois |

> **À faire valider** : l'exemption de consentement dont bénéficient les mesures d'audience dépend de la juridiction (en France, conditions de la CNIL : finalité statistique, durée limitée, pas de croisement entre sites, information et refus possibles). Le module est conçu pour les respecter, mais un avis juridique local reste nécessaire, surtout pour les cartes de clics et les clics répétés. La page Confidentialité (section 10) doit être relue.

## 4. Architecture

```
navigateur ──sendBeacon──> POST /a/e ──> Tracker::collect ──> analytics_sessions / analytics_events
application ─────────────> RecordActions (middleware) ──> Analytics::action ──> analytics_events (type « action »)

Stats        : visites, affluence, pages, clics, provenance, parcours, entonnoir, vitesse, erreurs, en direct
ClientStats  : actifs jour/semaine/mois, santé, clients qui décrochent, fonctions utilisées, mise en route, cohortes
Insights     : phrases en clair (tendance, créneau record, source qui domine, rebond, clics répétés, anomalie...)
ChatStats    : questions posées à l'assistant de la page d'accueil
CustomerRhythm : heures d'affluence des clients d'UN assistant (page Analytique du client)
```

- **Tables** : `analytics_sessions` (une ligne par visite) et `analytics_events` (une par geste). Le jour, l'heure et le jour de la semaine sont rangés dans des colonnes à l'écriture (fuseau `ANALYTICS_TIMEZONE`, sinon celui de l'application) : les cartes d'affluence se calculent avec du SQL portable (MySQL en production, SQLite en test).
- **Pas de `BelongsToWorkspace`** sur ces deux modèles : ce sont des données de plateforme, jamais lues par un client. `TenantIsolationTest` et `AnalyticsTest` vérifient que les pages de statistiques refusent un client et que `CustomerRhythm` ne voit que l'entreprise connectée.
- **Types d'événements du navigateur** (liste fermée, `Tracker::BROWSER_TYPES`) : `pageview`, `click`, `outbound`, `contact`, `scroll`, `engage`, `view`, `form_start`, `form_submit`, `rage`, `error`, `vital`. Tout autre type est ignoré.
- **Actions du serveur** : table `actions` de `config/analytics.php` (nom de route ou « MÉTHODE chemin » vers nom d'action). Pour mesurer une nouvelle action, ajouter une ligne ; ne rien écrire dans le contrôleur. Une action n'est enregistrée que si la requête a réussi (pas d'erreur de saisie, pas de message d'échec).
- **Fonctionnalités** : `config/analytics.php`, `features` relie des actions à une fonction (Connaissances, Zone de test, Alertes...) ; c'est ce qui alimente « Fonctions utilisées ».
- **Entonnoir des visiteurs** : visite, a lu ou cliqué, a regardé les offres (section `data-track-view="tarifs"`), a cliqué sur un bouton d'action, a ouvert l'inscription, a créé un compte.
- **Entonnoir des nouveaux comptes** : calculé à partir des tables de l'application (assistant créé, source prête, test fait, client réel ou canal actif, offre payante) : exact même pour les comptes d'avant la mesure.
- **Sections lues** : ajouter `data-track-view="nom"` à un élément ; compté quand la moitié est visible pendant une seconde.
- **Clics à ne pas mesurer** : `data-no-track` sur un élément ou un parent.
- **Depuis la page** : `window.koumaTrack('nom', { cle: 'valeur' })` enregistre un clic nommé ; `window.koumaOptOut(true|false)` active le refus.

## 5. Où lire les chiffres

**Administration, Statistiques** (super administrateur) : neuf onglets, période 7 jours à 12 mois, public visiteurs / clients / tous, export CSV.

| Onglet | Réponse à |
|---|---|
| Aperçu | l'essentiel, les phrases « à retenir », les visites du moment (rafraîchi toutes les 20 secondes) |
| Affluence | à quelle heure et quel jour ils viennent (carte jour × heure) |
| Pages | quelles pages, combien de temps, où ils entrent, d'où ils repartent, jusqu'où ils lisent |
| Clics | boutons d'action, contacts (WhatsApp, e-mail, téléphone), liens externes, PDF téléchargés, clics répétés, sections lues |
| Provenance | canaux, sites référents, campagnes (`utm_campaign`), appareils, pays, navigateurs, langues |
| Parcours | entonnoir, pages d'entrée, passages d'une page à l'autre, profondeur, durée |
| Clients | actifs, santé, ceux qui décrochent, fonctions utilisées, mise en route, cohortes, heures de connexion |
| Expérience | vitesse ressentie (LCP, FCP, INP, CLS, TTFB au 75e centile), défilement, formulaires, erreurs |
| Assistant du site | ce que les visiteurs demandent à l'assistant de la page d'accueil |

Autres endroits : fiche d'un espace client (Activité dans l'application, santé), page **Analytique** de chaque assistant côté client (heures d'affluence de ses propres clients), e-mail du lundi (`analytics:digest`).

Une **note de santé** (0 à 100) résume chaque client : visites récentes (40), régularité sur 30 jours (30), fonctions utilisées (20), vrais clients qui écrivent (10). « À surveiller » sous 45, « En danger » sous 20.

## 6. Entretien

- `php artisan analytics:prune` (chaque nuit à 03 h 30) efface au-delà de la durée de conservation (400 jours par défaut, réglable de 30 à 1095 jours dans Paramètres, Statistiques).
- `php artisan analytics:digest` (lundi, 07 h 00) envoie le résumé à l'adresse de réception des alertes (`PlatformSettings::alertEmail` : Paramètres, puis `PLATFORM_ADMIN_EMAIL`, puis l'e-mail de contact) ; `--force` l'envoie même s'il est désactivé ou si la semaine est vide.
- Ces deux commandes passent par le planificateur : la tâche CRON de LWS (`cron.php`) suffit.

## 7. Mise en production (LWS, sans SSH)

Sans accès SSH, `php artisan migrate` n'est pas possible. `scripts/build-lws-update.php` écrit donc, à côté du zip, un fichier `<nom>-base-de-donnees.sql` (via `scripts/migration-sql.php`, qui traduit les migrations en SQL MySQL sans serveur). Dans phpMyAdmin : cliquer sur la base de Kouma dans la colonne de gauche, onglet Importer, choisir ce fichier. Il crée les deux tables et enregistre la migration dans la table `migrations`, pour qu'elle ne soit pas rejouée. À faire **avant** d'ouvrir le site mis à jour : tant que les tables n'existent pas, la mesure est sans effet (le collecteur ne casse rien : `Analytics::action` attrape toute erreur), mais la page Statistiques affichera une erreur.

## 8. Limites connues

- Le pays est une **estimation** d'après le fuseau horaire du navigateur (un voyageur compte dans son fuseau d'origine) : c'est le prix de ne lire aucune adresse IP.
- Les visiteurs qui bloquent les scripts ou activent « Ne pas suivre » ne sont pas comptés : les chiffres sont des minimums, pas des totaux.
- Sur un serveur mutualisé, la mesure ajoute une écriture par lot d'événements. Au-delà de quelques dizaines de milliers de visites par jour, passer la file en `database` ou `redis` et mettre l'écriture en tâche.
- Les passages d'une page à l'autre sont calculés sur les 20 000 pages vues les plus récentes de la période.
- La santé des clients est une aide à la décision, pas un verdict : elle ne sait pas qu'un client est en vacances.
