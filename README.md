# Kouma

Plateforme d'assistants conversationnels pour entreprises, à la manière d'un Botpress allégé : chaque entreprise dépose ses **documents, photos, liens et textes**, obtient un assistant qui répond à ses clients sur son **site web**, et l'équipe technique active **WhatsApp à la demande** (Meta Cloud API par défaut, Twilio en secours).

> « Kouma » est un nom de travail.

## Ce que fait le prototype

- Espaces clients isolés, offres et quotas.
- Sources : PDF, Word, Excel et CSV (catalogues de produits, cartes, listes de prix comprises), texte, Markdown, HTML, **photos** (lues par IA), **site web** (exploration sécurisée), texte, questions/réponses, contenu Facebook collé.
- Recherche hybride (vecteurs + BM25) avec seuil de pertinence : l'assistant avoue quand il ne sait pas.
- Widget web en une ligne de code (Shadow DOM, liste blanche de domaines).
- WhatsApp : adaptateurs Meta et Twilio derrière une interface unique, signatures vérifiées, doublons ignorés.
- Transfert vers un humain et boîte de réception.
- **Demandes à traiter** (commandes à confirmer, rendez-vous, devis, personne demandée) et **alertes** au choix du propriétaire : e-mail, WhatsApp, tableau de bord, rappels.
- **Langues** multiples par assistant, dont des langues locales avec leur niveau de fiabilité ([docs/LANGUES.md](docs/LANGUES.md)).
- **Messages vocaux** : écoute et réponses en audio (WhatsApp Meta et Twilio, widget), selon l'offre.
- **Import des discussions WhatsApp** : l'assistant apprend le style et les réponses habituelles du client (option).
- **API pour développeurs** (`/developpeurs`) et widget riche (micro, écoute, copie, avis, langue, nouvelle conversation).
- Analytique et liste des « questions sans réponse », heures d'affluence des clients de chaque assistant.
- **Notifications** sur les téléphones (application installée, compteur sur l'icône, rappels si bloquées), centre de notifications, préférences par catégorie et heures calmes ; l'équipe envoie promotions, nouveautés et messages importants (audience, programmation, résultats).
- **E-mails** de marque (gabarit unique, version texte, aperçu et essai d'envoi dans l'administration).
- **Statistiques** maison (visiteurs, clics, heures d'affluence, provenance, parcours, santé des clients), sans service tiers ni adresse IP.
- **Lien de discussion à partager** (QR code, affiche A4, partage WhatsApp, Facebook, e-mail), **connexion avec Google, Apple, Microsoft et Facebook**, **suivi des inscrits** (segments, contacts, relances), diagnostic de délivrabilité des e-mails.
- **Supervision des conversations** (super administrateur) : toutes les conversations, visiteurs de Kouma et clients de chaque entreprise, avec signaux à surveiller, diagnostic, questions sans réponse, prospects, notes, exports, et journal de chaque consultation.
- **Aide et guides** : pages publiques (client, développeur) avec PDF, guides internes de l'équipe, boutons « Besoin d'aide ».
- Back-office de l'équipe technique : canaux, **consommation en temps réel** de chaque client, portefeuille Twilio, offres en plusieurs devises.

## Démarrage local (Windows, PHP 8.2, Node 20)

```powershell
composer install
copy .env.example .env
php artisan key:generate
php artisan migrate --seed --seeder=DemoSeeder   # base SQLite + entreprise de démonstration (fictive)
npm install
npm run build                                    # compile les assets une fois : pas besoin de "npm run dev"
php artisan serve --port=8123
```

Ouvrir http://localhost:8123. Les comptes de démonstration (équipe technique et cliente fictive) et leur mot de passe de développement sont définis dans [database/seeders/DemoSeeder.php](database/seeders/DemoSeeder.php).

La page de démonstration d'un assistant est publique : `/demo/{clé publique}` (la clé est affichée dans l'onglet « Canaux »).

### Sans clé d'API

Sans `ANTHROPIC_API_KEY`, l'application tourne en **mode hors ligne** : les réponses sont extraites des passages trouvés (pas de rédaction) et les vecteurs sont calculés localement (sac de mots, sans sémantique). C'est suffisant pour voir toute la chaîne fonctionner, **pas pour juger la qualité**. Pour de vraies réponses :

```
ANTHROPIC_API_KEY=...
VOYAGE_API_KEY=...        # ou OPENAI_API_KEY, pour les embeddings
PLATFORM_MODEL=claude-opus-5-5   # à choisir : voir docs/COUTS.md
PLATFORM_MIN_SCORE=0.35
```

Puis `php artisan platform:reindex` si des sources existent déjà.

### Sur `php artisan serve`

Le serveur intégré est **mono-processus** : une requête à la fois. Une réponse IA de quelques secondes bloque les autres pages. C'est normal en développement ; en production on utilise PHP-FPM ([docs/DEPLOYMENT.md](docs/DEPLOYMENT.md)).

## Commandes utiles

| Commande | Rôle |
|---|---|
| `php artisan test` | Lance la suite complète (aucun appel réseau réel ; les tests du dialogue de la démonstration tournent sous Node) |
| `php artisan platform:ask 1 "Vous livrez à Bobo ?"` | Pose une question à l'assistant n° 1 et affiche extraits, score, latence |
| `php artisan platform:reindex` | Recalcule les vecteurs après un changement de modèle d'embeddings |
| `php artisan platform:make-admin email@exemple.com` | Crée un compte de l'équipe technique |
| `php artisan platform:resync-due` | Relance les sites à mise à jour automatique (planifié toutes les heures) |
| `php artisan leads:remind` | Relance le propriétaire pour les demandes sans réponse (planifié toutes les 10 minutes) |
| `php artisan platform:check-wallets` | Alerte quand le solde Twilio est bas ou qu'un client approche son volume (planifié toutes les heures) |
| `php artisan queue:work` | Worker de la file (obligatoire si `QUEUE_CONNECTION` n'est pas `sync`) |

## Installer le widget sur un site

```html
<script src="https://VOTRE-DOMAINE/widget/widget.js" data-bot="pk_xxxxxxxx" async></script>
```

Renseigner les domaines autorisés dans *Réglages* de l'assistant avant la mise en ligne.

## Documentation

| Document | Contenu |
|---|---|
| [docs/TDR.md](docs/TDR.md) | **Termes de référence** : objectifs, périmètre, fonctions, planning, organisation, risques, recette |
| [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) | Schémas, modèle de données, décisions, sécurité, évolution |
| [docs/WHATSAPP.md](docs/WHATSAPP.md) | Meta ou Twilio, procédure d'activation par l'équipe technique, dépannage |
| [docs/COUTS.md](docs/COUTS.md) | Modèle de coûts et fixation des offres |
| [docs/LANGUES.md](docs/LANGUES.md) | Langues, niveaux de fiabilité, voix, modèles libres pour les langues locales |
| [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md) | Mise en production (hébergement mutualisé, e-mail, tâches planifiées) |
| [docs/SEO.md](docs/SEO.md) | Référencement : Search Console, pages de contenu, plan du site, ajout d'une page |
| [docs/ANALYTICS.md](docs/ANALYTICS.md) | Statistiques maison : ce qui est mesuré, vie privée, onglets, entretien |
| [docs/SOCIAL.md](docs/SOCIAL.md) | Connexion avec Google, Apple, Microsoft, Facebook : parcours, sécurité, réglage |
| [docs/PEOPLE.md](docs/PEOPLE.md) | Suivi des inscrits : stades, contacts, relances, règles |
| [docs/DOMAINE.md](docs/DOMAINE.md) | Changer de nom de domaine sans rien casser, et délivrabilité des e-mails |
| [docs/SUPERVISION.md](docs/SUPERVISION.md) | Supervision des conversations : vues, signaux, note de qualité, vie privée, limites |
| [docs/NOTIFICATIONS.md](docs/NOTIFICATIONS.md) | Notifications sur les téléphones (Web Push, compteur sur l'icône), centre de notifications, e-mails de marque, envois de l'équipe |
| [docs/CATALOGUE.md](docs/CATALOGUE.md) | Catalogues Excel et CSV, étude des catalogues WhatsApp Business |
| [docs/RECHERCHE.md](docs/RECHERCHE.md) | Recherche web, idées d'organisation, sources |

## Structure

```
app/Ai/          fournisseurs IA derrière des interfaces (Claude, Voyage, OpenAI, mode hors ligne)
app/Ingestion/   extracteurs, crawler sécurisé, découpage, pipeline
app/Retrieval/   recherche hybride
app/Chat/        prompt et service de conversation
app/Channels/    WhatsApp : interface, adaptateurs Meta et Twilio, traitement entrant
app/Speech/      voix : écoute et synthèse derrière une interface (OpenAI, serveur libre, mode hors ligne)
app/Leads/       demandes à traiter et alertes du propriétaire
app/Import/      import des discussions WhatsApp (lecture, anonymisation, style)
app/Support/SeoPages.php  pages de contenu du site public (config/seo.php)
resources/js/playground/  démonstration interactive de la page d'accueil (dialogue local)
public/widget/   widget embarquable
tests/           tests unitaires et fonctionnels
```

## Limites connues

Voir la section 10 de [docs/TDR.md](docs/TDR.md) : appels réels à Claude, aux embeddings, à Meta et à Twilio non éprouvés ; recherche calculée en PHP (jusqu'à quelques milliers d'extraits par assistant) ; pas de réponses en flux continu ; un seul type de compte par espace.
