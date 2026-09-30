# Recherche : ce que le web enseigne pour organiser le projet

Recherche menée le 29 septembre 2026. Ce document rassemble les constats, les idées d'organisation qui en découlent, et les sources. Les affirmations chiffrées sont celles des sources citées ; là où elles n'ont pas pu être vérifiées à la source primaire, c'est indiqué.

## 1. Ce que fait Botpress, et ce que Kouma en garde

Botpress combine trois choses : une **base de connaissances** (documents, sites, texte, tables, intégrations comme Notion ou Google Drive), un **éditeur visuel de flux**, et des **intégrations de canaux**. Détails relevés dans sa documentation :

- Formats de documents : PDF, HTML, TXT, DOC, DOCX, MD, jusqu'à 50 Mo par fichier.
- Sites : exploration depuis un domaine racine (sous-chemins découverts automatiquement) ou pages précises ; indexation unique par défaut, réexploration planifiable (par exemple quotidienne).
- Recherche web en temps réel et sources de type table.

| Botpress | Kouma V1 |
|---|---|
| Base de connaissances multi-sources | **Gardé** : fichiers, photos, sites, texte, Q/R |
| Réexploration planifiée des sites | **Gardé** : quotidienne ou hebdomadaire |
| Éditeur visuel de flux | **Volontairement écarté** (voir ARCHITECTURE, D11) |
| Tables et actions (réservations, API) | Écarté en V1 |
| Intégrations de canaux | Web et WhatsApp seulement |
| Facturation en dollars, interface anglophone, WhatsApp à la charge du client | **Différenciation** : interface française, WhatsApp activé par notre équipe |

## 2. Le paysage

| Produit | Nature | Enseignement pour Kouma |
|---|---|---|
| **Chatbase** | « Entraînez un assistant sur vos fichiers, sites, textes et Q/R ; intégrez-le à votre site, WhatsApp, Messenger… » | C'est le **modèle le plus proche** du besoin exprimé. Formules relevées : Hobby 40 USD, Standard 150 USD, Pro 500 USD par mois ; offre gratuite très limitée. Repère de prix pour la cible |
| **Botpress** | Constructeur d'agents, code ouvert en partie | Trop large pour une PME ; bonne référence pour la base de connaissances |
| **Typebot** | Flux conversationnels sans code, pas d'IA native | Utile si un besoin de formulaires guidés apparaît |
| **Flowise, Dify** | Constructeurs visuels de chaînes IA, code ouvert | Pour équipes techniques, pas pour le client final |
| **Chatwoot** | Boîte de réception de support, code ouvert | Référence pour la **boîte de réception et le transfert humain** ; envisageable à l'avenir comme brique plutôt que de la reconstruire |

Position de Kouma : **« Chatbase allégé, en français, avec WhatsApp géré »**.

## 3. Idées pour bien organiser le projet

1. **Commencer par la connaissance et le test, pas par l'éditeur de flux.** La valeur perçue vient de « voici mes documents, ça répond juste ». Un écran de test qui montre les extraits utilisés vaut plus qu'un canevas de flux.
2. **Isoler par entreprise dès le premier jour.** Une colonne d'appartenance sur chaque table, un filtre global, et des tests qui tentent d'accéder aux données d'un autre client. Rattraper cela plus tard coûte cher.
3. **Séparer ce qui change de ce qui dure.** Le fournisseur IA, le moteur d'embeddings, le stockage vectoriel et le fournisseur WhatsApp changent ; on les met derrière des interfaces. C'est ce qui rend « Meta ou Twilio en fonction du client » réalisable sans réécriture.
4. **Ingestion asynchrone, rejouable, avec statut visible.** Une exploration de site ou la lecture d'une photo prend du temps et échoue souvent ; l'utilisateur doit voir « échec : ce site interdit l'exploration » plutôt qu'un silence.
5. **Une boucle d'amélioration intégrée : les questions sans réponse.** Les guides de développement de chatbots insistent sur l'analytique dès le premier jour (échecs, abandons). La liste des questions sans réponse, avec un champ pour répondre, transforme chaque échec en connaissance.
6. **Le transfert humain n'est pas optionnel.** Sans passage fluide à une personne, les utilisateurs abandonnent et ne reviennent pas. Définir précisément les déclencheurs avant le lancement : demande explicite, réclamation, échecs répétés. Passer la conversation et son historique au conseiller.
7. **Sécurité en profondeur, pas en surface.** Protection SSRF du crawler, signatures de webhooks, secrets chiffrés, contenu non fiable isolé dans le prompt. OWASP classe l'injection de consignes en premier risque des applications à base de modèles et précise que le RAG **n'y met pas fin** : il ancre le modèle, il ne le protège pas.
8. **Écrire les décisions.** Une demi-page par décision d'architecture (contexte, choix, alternatives, conséquences). Le TDR reste vivant, versionné avec le code.
9. **Piloter avec de vrais clients et un jeu de questions de référence.** Trois à cinq entreprises, cinquante questions réelles chacune, rejouées à chaque changement de prompt ou de modèle. Les guides de MVP recommandent de cibler d'abord le cas d'usage à plus fort volume et retour mesurable (ici : prix, horaires, livraison), plutôt que le plus intéressant techniquement.
10. **Suivre le coût par réponse dès le départ.** Enregistrer les jetons avec chaque réponse ; la marge se calcule alors, elle ne se devine pas.
11. **Intégrer la conformité WhatsApp aux règles produit.** Fenêtre de 24 h, politique sur l'IA, changement tarifaire du 1er octobre 2026 : ce sont des contraintes de conception, pas des détails d'exploitation.
12. **Réserver la sophistication au moment où elle se justifie.** Reranking, contextualisation des extraits, PostgreSQL : à introduire quand la mesure le demande.

## 4. Enseignements techniques retenus

### 4.1 Récupération (RAG)

- **Recherche hybride** (vecteurs et mots exacts) : Anthropic rapporte, dans son étude sur la « récupération contextuelle », que combiner embeddings contextualisés et BM25 réduit de 49 % les échecs de récupération, et de 67 % avec un rerankeur (mesure sur le top 20 des extraits).
- **Contextualisation** : ajouter à chaque extrait une courte phrase qui le situe dans son document avant de l'indexer. Kouma applique une version sans appel IA (le fil d'Ariane « Titre › Section » est préfixé) ; la version par LLM est une évolution.
- **Découpage selon la structure** du document plutôt qu'en tailles fixes : c'est ce que fait le Chunker.
- **Petites bases** : moins de 200 000 jetons (environ 500 pages) peuvent être passées entièrement dans le prompt avec mise en cache, sans recherche.
- **Seuil de distance** : sans plafond, « les cinq meilleurs » et « aucun ne convient » se confondent, et le bot ne peut pas refuser.

### 4.2 Multi-entreprises

- Une base avec `tenant_id` partout et sécurité au niveau des lignes comme filet est un compromis reconnu ; le filtrage doit se faire au niveau de la base, avant que le contexte du modèle soit constitué.
- Pièges de pgvector avec filtre par entreprise : un index approximatif peut renvoyer trop peu de résultats ; activer le parcours itératif ou partitionner.
- Le travail lourd (découpage, embeddings) se fait hors des routes web, avec des quotas par entreprise contre l'effet « voisin bruyant ».

### 4.3 Exploration de sites

- Des services (Firecrawl) ou bibliothèques (Crawl4AI, sous licence Apache 2.0) convertissent des sites en Markdown, gèrent JavaScript, plans de site et robots.txt. Kouma utilise un explorateur maison sobre ; ces outils sont la piste pour les sites très dynamiques.
- Des sites restreignent de plus en plus les robots d'IA : respecter robots.txt n'est pas optionnel.

### 4.4 Widget

- Modèles courants : script de chargement puis élément personnalisé en Shadow DOM, ou iframe sur une autre origine. Clé publique liée à des origines autorisées, jetons de session courts, limitation de débit.
- Avec un serveur Nginx, désactiver la mise en tampon pour les flux SSE.

### 4.5 Facebook

- Les conditions d'utilisation de Meta interdisent la collecte automatisée sans autorisation ; les voies légitimes sont l'API Graph (jeton de page, revue d'application) ou la saisie manuelle. D'où l'import assisté en V1.

### 4.6 WhatsApp

- Voir [WHATSAPP.md](WHATSAPP.md) : comparatif Meta et Twilio, coexistence, Embedded Signup, politique sur l'IA, tarification.

## 5. Ce qui n'a pas pu être vérifié

- Les tarifs WhatsApp « utilitaire » et « service » pour le Burkina Faso et les Comores (seul le tarif marketing du « reste de l'Afrique » a été relevé).
- La disponibilité de la coexistence WhatsApp Business dans chaque pays cible (la liste des pays exclus relevée ne mentionne ni le Burkina Faso ni les Comores, mais cela doit être confirmé).
- La qualité des modèles sur le mooré, le dioula et le shikomori.
- Les chiffres de coût de l'IA reposent sur un relevé de tarifs du 25 septembre 2026 et sur des hypothèses de jetons, pas sur des mesures.
- Le cadre juridique de protection des données au Burkina Faso et aux Comores : à confirmer avec un juriste.

## 6. Sources

**Botpress et concurrents**
- Botpress, ajout de sources à la base de connaissances : https://botpress.com/docs/studio/concepts/knowledge-base/add-sources/
- Botpress, construire un chatbot RAG : https://botpress.com/blog/build-rag-chatbot
- Comparatif des plateformes ouvertes de chatbot : https://www.featurebase.app/blog/open-source-chatbot et https://www.chatbase.co/blog/open-source-chatbot-platforms
- Alternatives à Botpress : https://www.ringly.io/blog/botpress-alternatives
- Chatbase, fonctions et tarifs : https://www.eesel.ai/blog/chatbase-pricing

**WhatsApp**
- Tarification WhatsApp 2026 et changements du 1er octobre : https://respond.io/blog/whatsapp-business-api-pricing et https://www.engagelab.com/blog/whatsapp-business-api-pricing
- Tarifs Meta (page officielle) : https://developers.facebook.com/documentation/business-messaging/whatsapp/pricing
- Tarifs Twilio pour WhatsApp : https://www.twilio.com/en-us/whatsapp/pricing et https://chatarmin.com/en/blog/twilio-whats-app-api
- Embedded Signup : https://developers.facebook.com/documentation/business-messaging/whatsapp/embedded-signup/overview/
- Programme Tech Provider de Twilio : https://www.twilio.com/docs/whatsapp/isv/tech-provider-program et https://www.twilio.com/docs/whatsapp/isv/register-senders
- Coexistence WhatsApp Business : https://www.ycloud.com/blog/whatsapp-business-app-coexistence-meta-update
- Politique de Meta sur les assistants IA généralistes : https://respond.io/blog/whatsapp-general-purpose-chatbots-ban et https://www.alibabacloud.com/help/en/chatapp/use-cases/whatsapp-ai-policy-2026-guide

**RAG et multi-entreprises**
- Récupération contextuelle (Anthropic) : https://www.anthropic.com/engineering/contextual-retrieval
- RAG multi-entreprises avec PostgreSQL et pgvector : https://render.com/articles/multi-tenant-rag-postgresql-pgvector
- Architecture RAG multi-entreprises : https://www.thenile.dev/blog/multi-tenant-rag

**Exploration de sites, Facebook, widget, sécurité**
- Firecrawl : https://www.firecrawl.dev/crawl ; Crawl4AI : https://www.scrapingbee.com/blog/crawl4ai/
- Conditions de collecte automatisée de Meta : https://www.facebook.com/legal/automated_data_collection_terms
- API Pages de Meta : https://developers.facebook.com/docs/pages-api/
- Widget de chat embarquable (exemple d'implémentation) : https://github.com/VitrinaDev/vitrina-embed
- Isolation d'un widget par iframe : https://dev.to/dhinesh_ks_9db13f15d64f7/building-a-new-gen-chat-widget-css-and-javascript-isolation-with-cross-origin-iframes-4ag6
- OWASP, injection de consignes (LLM01:2025) : https://genai.owasp.org/llmrisk/llm01-prompt-injection/

**Organisation d'un projet de chatbot**
- Coût, délais et priorités d'un MVP de chatbot : https://www.raftlabs.com/blog/how-to-build-ai-chatbot
- Liste de contrôle d'un MVP de chatbot : https://launchtry.com/resources/mvp-checklist/chatbot
