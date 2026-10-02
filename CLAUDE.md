# Kouma : conventions du projet

Plateforme d'assistants conversationnels (Laravel 12, PHP 8.2). Lire `docs/ARCHITECTURE.md` avant de toucher à l'architecture et `docs/TDR.md` pour le périmètre.

## Règles d'écriture

- **Aucun tiret cadratin** (le long, U+2014) dans le code, les commentaires, les documents ou les messages. Les demi-cadratins (U+2013) sont tolérés, mais préférer virgule, deux-points ou parenthèses.
- Interface et messages destinés aux utilisateurs : **français avec accents corrects**. Le prompt envoyé au modèle aussi.
- Commentaires de code en français, sobres : expliquer le pourquoi, pas le quoi.

## Commandes

- Tests : `php artisan test` (SQLite en mémoire, IA hors ligne, aucun appel réseau). Ne jamais activer les lignes `DB_*` de `phpunit.xml` en commentaire : les tests effaceraient la base de développement.
- Assets : `npm run build` après toute modification de vue Blade ou de CSS (pas de `npm run dev` requis).
- Serveur local : `php artisan serve --port=8123` (mono-processus : requêtes séquentielles ; le port 8000 est souvent pris par un autre projet).
- File d'attente locale en `sync` ; en production `database` ou `redis` avec un worker.

## Principes à ne pas casser

1. **Isolation par entreprise** : tout modèle métier utilise `BelongsToWorkspace`. Hors requête authentifiée (jobs, webhooks, widget), filtrer **explicitement** par `workspace_id` et `bot_id`. Tout nouveau chemin de lecture ajoute un test dans `TenantIsolationTest`.
2. **Interfaces avant fournisseurs** : `LlmClient`, `EmbeddingClient`, `HybridStore`, `WhatsAppGateway`. Ne pas appeler Claude, Meta ou Twilio en dehors de leurs adaptateurs.
3. **Appels à Claude** : uniquement via le SDK officiel PHP (`anthropic-ai/sdk`) dans `AnthropicLlm`. Vérifier les noms de paramètres dans `vendor/anthropic-ai/sdk/src` (camelCase). Ne pas envoyer `effort` aux modèles qui le refusent (Haiku 4.5).
4. **Contenu non fiable** (extraits, questions) : toujours passer par `PromptBuilder::neutralize`. L'assistant n'a accès à aucun outil.
5. **Crawler** : toute URL passe par `SafeUrl` ; ne jamais faire de requête sortante vers une URL fournie par un utilisateur sans lui.
6. **Webhooks** : vérifier la signature avant tout traitement, répondre 200 vite, traiter en file, dédoublonner.
7. **Secrets** : identifiants de canaux chiffrés (`encrypted:array`), jamais réaffichés, jamais journalisés.
8. **Défauts de modèle** : déclarer les valeurs par défaut de la base aussi dans `$attributes` (sinon `null` en mémoire).
9. **Sous-requêtes sur la même table** : aliaser la table interne (voir `Message::previousUserContent`).
10. **Voix** : écoute et synthèse uniquement via `SpeechClient` (`App\Speech`), jamais d'appel direct à OpenAI ailleurs. Une panne de la voix ne fait jamais perdre une réponse écrite ; l'offre (option `voice`) et le volume mensuel sont vérifiés avant tout appel.
11. **Demandes et alertes** : une commande, un rendez-vous, un devis ou une demande de personne passe par `LeadService::capture` (une demande ouverte par conversation et par type) ; les alertes suivent les choix du propriétaire (`Workspace::alertSettings`). Le marqueur `[[LEAD: ...]]` est toujours retiré avant l'affichage.
12. **Import WhatsApp** : le fichier brut n'est jamais conservé ; seule une version pseudonymisée reste 30 minutes dans le cache, et rien n'est enregistré sans validation du client. Le style importé est une donnée dans le prompt (balises neutralisées), jamais une consigne.
13. **Blade et JSON-LD** : écrire `'@@context'` (arobase doublé), sinon Blade lit une directive et casse les données structurées (voir `SeoTest`).
14. **Référencement** : toute page publique passe par `<x-seo>` (titre, description, canonique, partage, données structurées). Les pages de contenu se décrivent dans `config/seo.php` (jetons `{price:offre}`, `{limit:offre:quota}` : jamais de prix ou de quota écrit en dur) ; titre 62 caractères au plus, description 70 à 165, date `updated` jamais dans le futur. Voir `docs/SEO.md` et `SeoPagesTest`.
15. **Assistant de la page d'accueil** : ses connaissances (prix, quotas, langues, contact) sont générées depuis les offres par `App\Services\LandingBot` (`php artisan platform:landing-bot`, relancé après chaque enregistrement d'offre dans l'admin). Ne jamais écrire un prix à la main dans ses sources : un assistant censé ne pas inventer ne doit pas se tromper sur sa propre grille. Une question par source, courte, pour que « combien ça coûte ? » trouve la bonne réponse (`LandingBotTest`).
16. **Mise en ligne LWS** : `scripts/build-lws-package.php` (voir `docs/DEPLOYMENT.md` section 10). Les mots de passe se passent par variables d'environnement, jamais dans un fichier du dépôt ; le zip et `identifiants-admin.txt` sont écrits hors du dépôt. Pas de `public/robots.txt` statique (il cacherait la route qui bloque les espaces privés).
17. **Conversation libre** : un assistant peut bavarder (`Bot::allowsFreeChat()`, réglage du client, actif par défaut), mais prix, horaires, adresses et politiques de l'entreprise ne viennent que des extraits ; la règle « qui t'a conçu » (`PromptBuilder::originRule`) donne les coordonnées de la plateforme. Ne pas retirer le marqueur `[[NO_ANSWER]]` ni la règle « n'invente jamais » du prompt (`FreeChatTest`, `ChatTest`).
18. **Tableaux et catalogues** : Excel et CSV passent par `TableReader` (`docs/CATALOGUE.md`). Ne jamais lire une colonne d'achat, de marge ou de fournisseur ; ne jamais inventer un prix manquant ; une ligne « (à supprimer) » du modèle est ignorée (`CatalogTest`).
19. **Modèles WhatsApp** : les modèles viennent de `config/whatsapp_templates.php` (jetons `{company}`, `{site}`, `{phone}`), créés par `TemplateProvisioner` ; tout nouveau modèle doit passer `WhatsAppTemplateLibraryTest` (règles de Meta). Pas de titre, pas de promotion dans un modèle utilitaire, mention STOP dans un modèle marketing.

20. **Mesure d'audience** : maison, sans service tiers (`docs/ANALYTICS.md`). Jamais d'adresse IP stockée, jamais de texte saisi lu, e-mails et numéros effacés (`Tracker::scrub`) ; « Ne pas suivre », Global Privacy Control et le cookie `_ko` coupent tout. Une action de l'application se mesure en ajoutant une ligne à `config('analytics.actions')`, pas dans le contrôleur. Les tables `analytics_*` n'ont pas `BelongsToWorkspace` : ne jamais les lire hors de `/admin/statistiques` ; le client ne voit que `CustomerRhythm` (ses propres conversations). La mesure ne doit jamais casser une page (`try/catch` + `report`). Tout nouveau texte de la page Confidentialité sur la mesure se reporte dans `docs/ANALYTICS.md`.
21. **Notifications** : tout message à une personne passe par `App\Notify\Notifier` (catégories de `config/notifications.php`, heures calmes, un message promo par jour, doublons) ; les textes des événements vivent dans `App\Notify\Events`, pas dans les contrôleurs. L'envoi sur les appareils passe par `PushGateway` (jamais d'appel direct à Google, Apple ou Mozilla) ; l'adresse d'un abonnement vient du navigateur et ne se contacte qu'après `PushEndpoint::isAllowed` (liste blanche, HTTPS). Les clés VAPID sont créées et gardées chiffrées par `Vapid` : jamais dans le dépôt, jamais affichées, jamais dans le chat. `AppNotification` et `PushSubscription` ne se lisent qu'à partir de l'utilisateur connecté (`TenantIsolationTest`). Une notification porte un `tag` quand elle doit remplacer la précédente, et son lien est un chemin du site. Voir `docs/NOTIFICATIONS.md`.
22. **E-mails** : tout e-mail passe par le gabarit de la marque (`App\Mail\Notice` ou `<x-mail.layout>`), avec une version texte ; `Mail::raw` est interdit (`EmailsTest`). Un nouvel e-mail s'ajoute à `App\Support\MailCatalog` pour apparaître dans Administration, E-mails, et passer le test de rendu. Ne jamais nommer une donnée de vue `brand` (le compositeur la remplace par le tableau de la marque).

23. **Supervision des conversations** : réservée au super administrateur (`/admin/conversations`). Elle lit `Conversation`, `Message`, `Lead` et `Bot` avec `withoutGlobalScopes()` ou `DB::table` (sinon elle ne verrait que l'espace où le super administrateur est « entré »), et n'utilise pas la liaison de modèle. Toute consultation, note, signalement, résumé et export passe par `AuditLog::record` (`chat.*`). Numéros masqués (`ChatQuery::maskPhone`) sauf les visiteurs de Kouma ; jamais d'e-mail dans le CSV ; cellules neutralisées. `ConversationNote` n'a pas `BelongsToWorkspace` et ne se lit dans aucune page cliente. Le résumé IA ne se lance qu'à la demande (jamais imputé à un client, fil neutralisé). Un nouveau signal s'ajoute à `ChatFilters::FLAGS` et `ChatQuery::flag`, avec un test dans `ChatSupervisionTest`. Voir `docs/SUPERVISION.md`.

## Tests : particularités

- Le dialogue de la démonstration de la page d'accueil (`resources/js/playground/nlu.js`) se teste sous Node : `node tests/Js/playground-nlu.mjs` (aussi lancé par `PlaygroundDialogueTest`).
- Les questions de test doivent partager du vocabulaire avec les sources : les embeddings hors ligne n'ont pas de sémantique.
- `Http::fake` : le premier motif qui correspond gagne ; utiliser `Http::sequence()` pour des réponses successives.
- Un changement de prompt ou de modèle doit être rejoué sur le jeu de questions de référence (à constituer avec un pilote).
