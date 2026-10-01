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

## Tests : particularités

- Le dialogue de la démonstration de la page d'accueil (`resources/js/playground/nlu.js`) se teste sous Node : `node tests/Js/playground-nlu.mjs` (aussi lancé par `PlaygroundDialogueTest`).
- Les questions de test doivent partager du vocabulaire avec les sources : les embeddings hors ligne n'ont pas de sémantique.
- `Http::fake` : le premier motif qui correspond gagne ; utiliser `Http::sequence()` pour des réponses successives.
- Un changement de prompt ou de modèle doit être rejoué sur le jeu de questions de référence (à constituer avec un pilote).
