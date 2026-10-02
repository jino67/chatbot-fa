# Termes de référence : plateforme d'assistants conversationnels « Kouma »

| | |
|---|---|
| **Version** | 0.1, proposition pour validation |
| **Date** | 30 septembre 2026 |
| **Nom de travail** | Kouma (nom provisoire) |
| **Porteur** | Équipe technique (à préciser) |
| **Statut du code** | Prototype fonctionnel livré avec ce document (voir section 10) |

---

## 1. Contexte et justification

Les petites et moyennes entreprises d'Afrique francophone reçoivent l'essentiel de leurs demandes clients par WhatsApp, Facebook et leur site web : prix, horaires, livraison, disponibilité. Les mêmes questions reviennent chaque jour, à toute heure, et la réponse existe déjà dans un tarif PDF, une affiche, une page du site ou une publication Facebook.

Des plateformes comme Botpress ou Chatbase permettent de construire un assistant à partir de ces contenus, mais elles sont pensées pour des équipes techniques anglophones, facturées en dollars, et l'activation de WhatsApp y reste à la charge du client, ce qui est un obstacle majeur pour une PME.

**Kouma** est une version volontairement allégée de ce type de plateforme : une entreprise dépose ses documents, ses photos, ses liens, et obtient en quelques minutes un assistant qui répond à ses clients sur son site web. L'activation de WhatsApp est réalisée **à la demande, par notre équipe technique**, ce qui transforme une contrainte technique en service à valeur ajoutée.

## 2. Objectifs

### 2.1 Objectif général

Mettre en place une plateforme multi-entreprises permettant à chaque client de créer, tester et publier un assistant conversationnel fiable, alimenté par ses propres contenus, sur son site web puis sur WhatsApp.

### 2.2 Objectifs spécifiques

| # | Objectif | Indicateur | Cible V1 |
|---|---|---|---|
| O1 | Réduire le temps de mise en route d'un assistant | Temps entre l'inscription et la première réponse correcte | moins de 15 minutes |
| O2 | Garantir des réponses fondées sur les sources du client | Réponses correctes et sourcées sur le jeu de test (50 questions) | 85 % ou plus |
| O3 | Ne jamais inventer | Réponses inventées (prix, horaire, politique) sur le jeu de test | moins de 2 % |
| O4 | Isoler les données de chaque client | Fuites inter-clients détectées par les tests automatisés | zéro |
| O5 | Offrir WhatsApp sans complexité pour le client | Délai d'activation après dossier complet (hors délais Meta) | 5 jours ouvrés ou moins |
| O6 | Garder l'humain dans la boucle | Conversations transférées et prises en charge dans la boîte de réception | 100 % des demandes explicites |
| O7 | Maîtriser le coût d'exploitation | Coût IA par réponse suivi et publié | mesuré dès le pilote |

## 3. Périmètre

### 3.1 Inclus dans la V1

- Espaces clients isolés (workspaces), comptes, offres avec quotas.
- Base de connaissances : documents (PDF, Word, Excel, texte, Markdown, CSV, HTML ; catalogues de produits compris), **photos** (lecture par IA), **site web** (exploration), texte libre, questions/réponses, contenu de page Facebook collé.
- Assistant configurable : consignes, ton, messages, langue, apparence.
- Test intégré avec affichage des extraits utilisés.
- Widget web à installer par une ligne de code, avec liste blanche de domaines.
- **WhatsApp activé par l'équipe technique**, via Meta Cloud API (par défaut) ou Twilio (secours).
- Boîte de réception avec reprise en main humaine.
- Analytique et liste des questions sans réponse.
- Back-office de l'équipe technique : demandes WhatsApp, configuration des canaux, offres.
- Français, anglais et arabe pour l'assistant ; interface en français.

### 3.2 Exclu de la V1 (prévu en V2 ou plus tard)

| Fonction | Raison |
|---|---|
| Éditeur visuel de flux (glisser-déposer à la Botpress) | Complexité élevée, faible valeur pour une PME ; l'assistant guidé par consignes suffit |
| Connexion Facebook par l'API officielle (Graph API) | Nécessite la revue d'application Meta ; import assisté en V1 |
| Inscription WhatsApp en libre-service (Embedded Signup) | Nécessite le statut Tech Provider et la revue d'application ; l'activation manuelle est le choix voulu en V1 |
| Messages modèles WhatsApp (relances, notifications hors fenêtre de 24 h) | Approbation des modèles par Meta ; V1.1 |
| Messagerie vocale, appels, Instagram, Messenger | Hors besoin initial |
| Paiement en ligne de l'abonnement | Facturation manuelle au pilote |
| Langues locales (mooré, dioula, shikomori) | Qualité non garantie par les modèles actuels ; test en pilote |

## 4. Utilisateurs et rôles

| Rôle | Description | Accès |
|---|---|---|
| **Propriétaire / gérant** | Dirigeant de la PME, non technique | Tout son espace : assistants, sources, réglages, conversations |
| **Conseiller** (V1.1) | Employé qui répond aux clients transférés | Boîte de réception uniquement |
| **Client final** | Visiteur du site ou correspondant WhatsApp | Discute avec l'assistant, peut demander un humain |
| **Équipe technique** | Nous | Back-office : demandes WhatsApp, identifiants des canaux, offres, supervision |

Aujourd'hui, un espace n'a qu'un type de compte (propriétaire). Les rôles fins sont prévus en V1.1.

## 5. Description fonctionnelle

Priorités selon la méthode MoSCoW : **M** indispensable, **S** important, **C** souhaitable.

### 5.1 Base de connaissances

| Réf. | Fonction | Prio | Critère d'acceptation |
|---|---|---|---|
| F1.1 | Import de documents PDF, Word, Excel, texte, Markdown, CSV, HTML (20 Mo max) | M | Le contenu est interrogeable en moins de 2 minutes ; un fichier non pris en charge est refusé avec un message clair |
| F1.2 | PDF scannés | S | Un PDF image est lu automatiquement ; sinon message explicite |
| F1.3 | **Photos** (affiches, menus, tarifs) | M | Le texte et les prix visibles sont retrouvés par l'assistant |
| F1.4 | Site web : page seule ou tout le site (limite selon l'offre) | M | Respect de robots.txt, aucune adresse interne atteignable, pages dupliquées (menus, pieds de page) filtrées |
| F1.5 | Mise à jour automatique d'un site (quotidienne, hebdomadaire) | S | L'ancien contenu n'est remplacé qu'après succès de la nouvelle lecture |
| F1.6 | Texte libre et fiches Question/Réponse | M | Prise en compte immédiate |
| F1.7 | Contenu Facebook par copier-coller | M | Aucune lecture automatique de Facebook |
| F1.8 | État de chaque source (en attente, en cours, prête, échec) et motif d'échec lisible | M | Le client sait quoi corriger |

### 5.2 Assistant et conversation

| Réf. | Fonction | Prio | Critère d'acceptation |
|---|---|---|---|
| F2.1 | Réponses uniquement d'après les sources, avec aveu quand l'information manque | M | Score O2 et O3 atteints sur le jeu de test |
| F2.2 | Réponse dans la langue du client | M | Français, anglais, arabe vérifiés |
| F2.3 | Consignes spécifiques, message d'accueil, message de repli, questions suggérées | M | Modifiables sans intervention technique |
| F2.4 | Résistance à l'injection de consignes (dans les documents ou par le client) | M | Jeu d'attaques de référence sans effet |
| F2.5 | Transfert humain : demande explicite, réclamation, deux échecs consécutifs | M | Un client qui demande une personne est toujours transféré, sans dépendre des sources ni du quota |
| F2.6 | Notification e-mail du transfert | M | Reçue en moins d'une minute |
| F2.7 | Collecte du nom et du téléphone avant discussion (option) | S | Enregistrés dans la conversation |
| F2.8 | Réponses en flux continu (streaming) | C | Premier mot en moins de 2 secondes |

### 5.3 Canaux

| Réf. | Fonction | Prio | Critère d'acceptation |
|---|---|---|---|
| F3.1 | Widget web : une ligne de code, isolé du site hôte, accessible, adapté au mobile | M | Fonctionne sur WordPress, Shopify, Wix et HTML simple |
| F3.2 | Liste blanche de domaines et limitation de débit | M | Un site non autorisé reçoit un refus |
| F3.3 | Page de démonstration partageable par lien | S | Utilisable en rendez-vous commercial |
| F3.4 | **WhatsApp via Meta Cloud API** | M | Message reçu, réponse envoyée, signature vérifiée, doublons ignorés |
| F3.5 | **WhatsApp via Twilio** (secours) | M | Mêmes garanties, sans changer le reste de l'application |
| F3.6 | Demande d'activation par le client, traitement par l'équipe technique | M | Suivi de statut visible côté client |
| F3.7 | Respect de la fenêtre de service de 24 h | M | Aucun texte libre envoyé hors fenêtre |
| F3.8 | Messages modèles hors fenêtre | C | V1.1 |

### 5.4 Pilotage et exploitation

| Réf. | Fonction | Prio | Critère d'acceptation |
|---|---|---|---|
| F4.1 | Boîte de réception : prendre la main, répondre, rendre au bot, clôturer | M | Réponse humaine reçue par le client sur le canal d'origine |
| F4.2 | Analytique : conversations, taux de réponses fondées, transferts, délai moyen | M | Chiffres cohérents avec la base |
| F4.3 | **Questions sans réponse** regroupées, avec ajout de la réponse en un clic | M | La question ne réapparaît plus une fois traitée |
| F4.4 | Quotas par offre (assistants, sources, réponses par mois) | M | Dépassement : service dégradé proprement, jamais d'erreur brute |
| F4.5 | Back-office : demandes, canaux, test de connexion, offres | M | Identifiants chiffrés et jamais réaffichés |
| F4.6 | Suivi du coût IA par réponse | S | Jetons stockés avec chaque réponse |
| F4.7 | **Notifications** : sur les téléphones (application installée, compteur sur l'icône), centre de notifications, préférences par catégorie et heures calmes, rappels d'activation | S | Une commande ou un client qui attend arrive sur le téléphone en quelques secondes ; un client peut tout refuser sauf les messages importants |
| F4.8 | **Envois de l'équipe** : promotions, nouveautés, messages importants (audience, aperçu, essai, programmation, résultats) | S | Un client qui refuse les promotions n'en reçoit pas ; un message par jour au plus ; journal d'audit |
| F4.9 | **E-mails de marque** : un gabarit, une version texte, aperçu et essai d'envoi dans l'administration | S | Aucun e-mail en texte brut ; chacun se voit avant d'être envoyé |
| F4.10 | **Statistiques** maison (visiteurs, clics, affluence, provenance, parcours, santé des clients) | S | Aucune adresse IP gardée ; « Ne pas suivre » respecté ; refus possible en un clic |
| F4.11 | **Supervision des conversations** : toutes les conversations (visiteurs de Kouma et clients de chaque entreprise), signaux à surveiller, diagnostic, questions sans réponse, prospects, notes, exports | S | Super administrateur seulement ; chaque consultation journalisée ; numéros masqués ; résumé IA à la demande |
| F4.12 | **Aide et guides** (public : client, développeur avec PDF ; interne : équipe, super administrateur) | S | Chaque guide est à jour de l'application ; aucune adresse locale dans les PDF |

## 6. Exigences non fonctionnelles

| Domaine | Exigence |
|---|---|
| **Sécurité** | Isolation stricte par entreprise (filtrage automatique + tests) ; secrets des canaux chiffrés au repos ; signatures des webhooks vérifiées ; protection SSRF du crawler ; téléversements validés et stockés sous nom aléatoire ; aucun secret dans le widget ; limitation de débit |
| **Confidentialité** | Les documents d'un client ne servent qu'à son assistant ; aucune utilisation croisée. Conformité aux lois de protection des données applicables (Burkina Faso, Comores, RGPD si clients européens) : **à valider avec un juriste** avant la mise en production |
| **Performance** | Réponse complète en moins de 8 secondes au 95e centile (hors panne du fournisseur IA) ; ingestion d'un site de 50 pages en moins de 5 minutes |
| **Disponibilité** | 99,5 % mensuel visé en production ; dégradation contrôlée quand le fournisseur IA est indisponible (message de repli, conversation conservée) |
| **Fiabilité WhatsApp** | Réponse 200 au webhook en moins de 5 secondes ; traitement asynchrone ; aucun message traité deux fois |
| **Langues** | Assistant : français, anglais, arabe (interface arabe du widget avec écriture de droite à gauche). Interface client : français |
| **Accessibilité** | Widget navigable au clavier, rôles ARIA, respect de la préférence de réduction des animations |
| **Observabilité** | Journaux applicatifs, échecs d'ingestion et de livraison tracés, coût et latence par réponse |
| **Maintenabilité** | Architecture par modules et adaptateurs ; tests automatisés ; décisions documentées |
| **Portabilité** | Fournisseur IA, moteur d'embeddings, stockage des vecteurs et canaux remplaçables par configuration |

## 7. Architecture retenue (résumé)

Le détail est dans [ARCHITECTURE.md](ARCHITECTURE.md). Les choix structurants :

| Sujet | Choix | Raison |
|---|---|---|
| Application | Monolithe Laravel 12 modulaire | Compétence de l'équipe, rapidité de livraison, un seul déploiement |
| Multi-entreprises | Une base, colonne `workspace_id` sur chaque table, filtre global automatique | Simple, testable, évolutif vers PostgreSQL avec sécurité au niveau des lignes |
| Recherche | **Hybride** : vecteurs (sens) + BM25 (mots exacts) avec seuil de pertinence | Les prix et références demandent les mots exacts ; le sens gère les reformulations |
| IA de réponse | Claude via le SDK officiel, derrière une interface | Modèle configurable par variable d'environnement ; mode hors ligne pour tests |
| Embeddings | Voyage (recommandé, multilingue) ou OpenAI, derrière une interface | Anthropic ne propose pas de modèle d'embeddings |
| Stockage vectoriel | Base relationnelle en V1 (jusqu'à quelques milliers d'extraits par assistant) ; PostgreSQL + pgvector ensuite | Zéro service supplémentaire pour démarrer |
| Traitements longs | File d'attente (ingestion, réponses WhatsApp) | Le webhook répond immédiatement |
| Éditeur de flux | **Non** | Hors du positionnement « allégé » |

### 7.1 Décision WhatsApp : Meta en direct par défaut, Twilio en secours

L'application ne connaît qu'une interface « WhatsApp » ; le fournisseur est un attribut du canal de chaque client, modifiable par l'équipe technique sans toucher au code.

| Critère | Meta Cloud API (direct) | Twilio |
|---|---|---|
| Marge par message | **Aucune** | 0,005 USD par message reçu **et** envoyé, en plus des tarifs Meta |
| Webhook | Un seul pour tous les clients (identifie le canal par `phone_number_id`) | Un par client (sous-compte) |
| Démarrage | Vérification d'entreprise et numéro requis, délais Meta | Plus rapide (bac à sable, expéditeur déjà approuvé) |
| Dépendance | Directement à Meta | Meta **et** Twilio |
| Usage recommandé | **Cas général** | Démo immédiate, client déjà chez Twilio, besoin de numéro fourni, dépannage |

Illustration : 1 000 conversations par mois de 5 messages reçus et 5 envoyés représentent 10 000 messages, soit environ **50 USD** de marge Twilio mensuelle, sans aucun avantage fonctionnel une fois le canal en régime.

Le mode d'activation (par notre équipe, pas en libre-service) évite en V1 la revue d'application Meta et le statut Tech Provider, et permet de s'appuyer sur le numéro et le compte WhatsApp Business **du client**, qui reste propriétaire de son numéro et payeur de ses messages. Détail et procédure : [WHATSAPP.md](WHATSAPP.md).

### 7.2 Points de conformité WhatsApp à intégrer au produit

1. **Politique IA de Meta** : les assistants généralistes sont interdits sur la plateforme ; les assistants de support propres à une entreprise restent autorisés. Kouma s'y conforme par conception : l'assistant ne répond que sur les sources du client et refuse le hors-sujet. **Point à faire valider juridiquement** car le texte vise les fournisseurs d'IA dont l'IA est « la fonction principale ».
2. **Fenêtre de service de 24 h** : texte libre autorisé seulement dans les 24 h suivant le dernier message du client (appliqué par le produit).
3. **Tarification** : à partir du **1er octobre 2026**, les messages de service ne sont plus gratuits au-delà de 1 000 par numéro et par mois. Impact à répercuter dans les offres ([COUTS.md](COUTS.md)).
4. **Inscription intégrée** : la version 2 d'Embedded Signup est retirée le 15 octobre 2026 ; toute évolution vers le libre-service devra viser la version 4.

## 8. Livrables

| # | Livrable | Format |
|---|---|---|
| L1 | Code source de la plateforme, dépôt versionné | Git |
| L2 | Documentation technique : architecture, décisions, déploiement | Markdown dans `docs/` |
| L3 | Procédure d'activation WhatsApp (Meta et Twilio) | `docs/WHATSAPP.md` |
| L4 | Modèle de coûts d'exploitation | `docs/COUTS.md` |
| L5 | Suite de tests automatisés (166 tests à ce jour) | `tests/` |
| L6 | Jeu de 50 questions de référence pour la recette (à constituer avec un pilote) | Tableur |
| L7 | Guide d'utilisation client (mise en route en 15 minutes) | À produire en phase 3 |
| L8 | Environnement de préproduction et de production | Serveur configuré |
| L9 | Rapport de pilote (3 à 5 entreprises) | Document |

## 9. Planification

Hypothèse : 2 développeurs à temps plein et une personne de pilotage produit à environ 30 %. Les durées sont des **estimations** à recaler après la phase 0.

| Phase | Contenu | Durée | Jalon de sortie |
|---|---|---|---|
| **0. Cadrage** | Validation du TDR ; choix du modèle IA après comparaison sur 30 questions réelles ; ouverture du compte Meta Business ; recrutement de 3 à 5 entreprises pilotes ; constitution du jeu de questions ; avis juridique | 1 semaine | TDR signé, pilotes identifiés |
| **1. Socle web** | Finalisation de l'ingestion et de la conversation avec clé IA réelle ; réglage du seuil de pertinence ; lecture des photos et PDF scannés en conditions réelles ; réponses en flux ; interface arabe | 3 semaines | Un pilote utilise le widget sur son site |
| **2. WhatsApp et équipe** | Activation Meta sur un numéro réel de bout en bout ; Twilio en secours ; boîte de réception ; notifications ; messages modèles hors fenêtre | 3 semaines | Un pilote sur WhatsApp |
| **3. Production** | PostgreSQL et pgvector, Redis, supervision, sauvegardes, revue de sécurité, guide client, offres et facturation manuelle | 3 semaines | Mise en production contrôlée |
| **4. Pilotes** | Suivi de 3 à 5 entreprises, mesure des indicateurs, corrections | 3 semaines | Rapport de pilote, décision de généralisation |
| **Ensuite** | Connexion Facebook (Graph API), Embedded Signup, rôles conseillers, contextualisation des extraits, autres canaux | à planifier | Feuille de route V2 |

Charge estimée : **70 à 90 jours-hommes** avant généralisation, dont environ 15 % de gestion de projet et de recette. Le prototype fourni avec ce document couvre une part importante des phases 1 et 2 sous forme fonctionnelle mais non éprouvée en conditions réelles.

## 10. État du prototype livré avec ce document

**Fonctionne et vérifié** : création de compte et d'espace ; sources de tous types ; ingestion asynchrone ; recherche hybride ; conversation avec repli, transfert et quotas ; widget dans un vrai navigateur (dont réception d'une réponse humaine) ; webhooks Meta et Twilio (signatures, doublons, réponses) ; back-office et test de connexion ; crawl d'un site public réel ; 166 tests automatisés.

**Non éprouvé** (à faire en phase 1 et 2) :

- Appels **réels** à Claude et à un fournisseur d'embeddings : aucune clé n'était disponible ; le mode hors ligne (réponses extractives, vecteurs locaux) a servi aux essais. La qualité de réponse réelle reste donc à mesurer.
- Lecture de photos et de PDF scannés (elle passe par Claude).
- Envoi réel via Meta ou Twilio (simulé dans les tests).
- Tenue en charge et exécution derrière un vrai serveur web multi-processus.
- Réponses en flux continu (non implémentées).
- La protection contre l'injection est éprouvée par des tests sur la construction du prompt, pas encore par un jeu d'attaques face au modèle réel.

## 11. Organisation et gouvernance du projet

### 11.1 Rôles

| Rôle | Responsabilités |
|---|---|
| Porteur du projet | Priorités, arbitrages, relation avec les pilotes, validation des recettes |
| Développeur référent | Architecture, revues de code, décisions techniques (consignées) |
| Développeur | Réalisation, tests |
| Responsable WhatsApp (équipe technique) | Comptes Meta et Twilio, activations, suivi de la qualité des numéros |

### 11.2 Méthode de travail

- Itérations d'**une semaine**, avec démonstration en fin de semaine devant au moins un pilote quand c'est possible.
- Point quotidien de 10 minutes, écrit si l'équipe est répartie.
- Revue hebdomadaire des risques et du coût IA.
- Toute décision d'architecture est consignée en une demi-page (fiche de décision) dans `docs/ARCHITECTURE.md`.

### 11.3 Outils et pratiques

| Sujet | Pratique |
|---|---|
| Versionnement | Git, branche principale protégée, une branche par sujet, revue avant fusion |
| Qualité | Tests automatisés obligatoires pour toute règle métier ; formatage automatique (Laravel Pint) ; **le jeu de questions de référence rejoué avant chaque changement de prompt ou de modèle** |
| Environnements | Local (mode hors ligne, sans clé) ; préproduction (clés de test, numéro WhatsApp d'essai) ; production |
| Secrets | Variables d'environnement, jamais dans le dépôt ; identifiants des canaux chiffrés en base |
| Définition de « terminé » | Code relu, tests verts, documentation à jour, critères d'acceptation vérifiés, aucun secret ni donnée réelle dans le dépôt |
| Suivi | Tableau de tâches par phase ; registre des risques tenu à jour |

### 11.4 Organisation du dépôt

```
app/Ai/            fournisseurs IA (LLM, embeddings) derrière des interfaces
app/Ingestion/     extracteurs de fichiers, crawler sécurisé, découpage, pipeline
app/Retrieval/     recherche hybride (vecteurs + BM25)
app/Chat/          construction du prompt, service de conversation
app/Channels/      WhatsApp : interface, adaptateurs Meta et Twilio, traitement entrant
app/Http/          contrôleurs (tableau de bord, API du widget, webhooks, back-office)
public/widget/     widget embarquable
docs/              TDR, architecture, WhatsApp, coûts, déploiement, recherche
tests/             tests unitaires et fonctionnels
```

## 12. Risques et mesures

| # | Risque | Probabilité | Impact | Mesure |
|---|---|---|---|---|
| R1 | Délais de vérification Meta (entreprise, numéro, nom affiché) | Élevée | Moyen | Lancer les démarches en phase 0 ; Twilio en secours ; ne pas promettre de date ferme au client |
| R2 | Un numéro déjà utilisé sur l'application WhatsApp mobile ne peut pas être enregistré tel quel | Moyenne | Moyen | Vérifier en amont ; étudier le mode coexistence (disponibilité par pays à confirmer) ou un numéro dédié |
| R3 | Politique Meta sur les assistants IA interprétée contre la plateforme | Faible à moyenne | Élevé | Avis juridique en phase 0 ; assistants strictement limités aux sources du client ; ne jamais présenter Kouma comme un assistant généraliste |
| R4 | Coût IA supérieur aux prévisions | Moyenne | Élevé | Comparer les modèles en phase 0 ; quotas par offre ; coût par réponse suivi ; réponses courtes ; court-circuit sans appel IA quand aucune source ne correspond |
| R5 | Mauvaise qualité de réponse en langues locales, sur documents mal numérisés ou photos floues | Élevée | Moyen | Périmètre linguistique annoncé (fr, en, ar) ; message d'échec clair à l'import ; recette sur documents réels des pilotes |
| R6 | Invention d'informations par le modèle (prix, horaires) | Moyenne | Élevé | Consignes strictes, seuil de pertinence, aveu d'ignorance, jeu de questions rejoué à chaque changement |
| R7 | Injection de consignes via un document ou un site exploré | Moyenne | Moyen | Contenu non fiable isolé dans des balises neutralisées ; aucune action sensible accessible à l'assistant ; jeu d'attaques en recette |
| R8 | Fuite de données entre clients | Faible | Très élevé | Filtre automatique, filtrage explicite dans la recherche, tests dédiés ; à terme sécurité au niveau des lignes PostgreSQL |
| R9 | Sites clients entièrement en JavaScript, illisibles pour le crawler | Élevée | Faible | Message clair et repli sur le texte collé ; navigateur sans tête (Playwright) ou service d'exploration en V2 |
| R10 | Accès aux données Facebook restreint | Certaine | Moyen | Import assisté en V1 ; connexion Graph API en V2 après revue d'application |
| R11 | Recherche en PHP trop lente au-delà de quelques milliers d'extraits par assistant | Moyenne | Moyen | Migration prévue vers pgvector derrière l'interface existante (phase 3) |
| R12 | Fournisseur IA indisponible ou limité | Moyenne | Moyen | Repli automatique, conversation conservée, possibilité de changer de modèle par configuration |
| R13 | Non-conformité aux lois sur les données personnelles | Moyenne | Élevé | Avis juridique, mentions d'information dans le widget, durée de conservation à définir, suppression à la demande |
| R14 | Dépendance à une seule personne pour WhatsApp | Moyenne | Moyen | Procédure écrite ([WHATSAPP.md](WHATSAPP.md)), deux personnes formées |

## 13. Recette

La recette est prononcée si **tous** les points suivants sont vérifiés :

1. Sur le jeu de 50 questions de référence d'un pilote, au moins 85 % de réponses correctes et sourcées, et moins de 2 % de réponses inventées.
2. Les tests automatisés d'isolation entre clients sont verts, et une revue manuelle confirme qu'aucune requête n'échappe au filtrage.
3. Un message WhatsApp réel reçoit une réponse sur un numéro d'essai, via Meta puis via Twilio, sans changement de code.
4. Un même message livré deux fois par le fournisseur n'est traité qu'une fois.
5. Une demande de conseiller déclenche le transfert, l'e-mail, et la réponse humaine parvient au client sur le bon canal.
6. Le widget fonctionne sur au moins un site WordPress, un site Shopify et une page HTML, sur mobile et ordinateur.
7. Une adresse interne (127.0.0.1, 10.x, métadonnées cloud) et un réseau social ne peuvent pas être explorés.
8. Le dépassement de quota dégrade le service sans erreur visible pour le client final.
9. Les sauvegardes sont restaurées avec succès lors d'un essai.
10. La documentation permet à une personne extérieure d'installer et d'activer un canal WhatsApp.

## 14. Indicateurs de suivi après lancement

| Indicateur | Pourquoi |
|---|---|
| Part de réponses fondées sur les sources | Qualité perçue |
| Nombre de questions sans réponse traitées par semaine et par client | Le client améliore-t-il son assistant ? |
| Taux de transfert humain et délai de première réponse humaine | Le bot désengorge-t-il l'équipe ? |
| Coût IA moyen par réponse et par client | Rentabilité |
| Délai moyen d'activation WhatsApp | Efficacité de l'équipe technique |
| Clients actifs après 30 jours | Adoption |

## 15. Points à trancher avant le démarrage

| # | Question | Impact |
|---|---|---|
| Q1 | Quel modèle IA par défaut ? Le code utilise Claude Opus 5.5 par défaut ; Sonnet 5.5 ou Haiku 4.5 coûtent 2 à 5 fois moins par réponse. À trancher après comparaison sur 30 questions réelles | Coût, vitesse, qualité |
| Q2 | Hébergement : serveur dédié, VPS ou plateforme managée ? Où se situent les données ? | Conformité, coût |
| Q3 | Pays cibles de la phase pilote (Burkina Faso, Comores, autres) | Tarifs WhatsApp, numéros, langues |
| Q4 | Modèle commercial : forfaits par offre, ou facturation de l'activation WhatsApp en supplément ? | Offres |
| Q5 | Qui paie les messages WhatsApp : le client via son compte Meta (recommandé) ou nous par refacturation ? | Risque financier |
| Q6 | Nom définitif du produit et domaine | Marque, e-mails |
| Q7 | Faut-il un rôle « conseiller » distinct dès le pilote ? | Périmètre phase 2 |

## Annexe A. Glossaire

| Terme | Définition |
|---|---|
| **RAG** | Génération augmentée par la recherche : on retrouve d'abord les passages utiles dans les documents du client, puis le modèle rédige la réponse à partir d'eux |
| **Extrait (chunk)** | Petit passage d'un document, unité de recherche |
| **Embedding** | Représentation numérique du sens d'un texte, qui permet de comparer des passages |
| **BM25** | Méthode de recherche par mots exacts, complémentaire de la recherche par sens |
| **Workspace** | Espace isolé d'une entreprise cliente |
| **WABA** | Compte WhatsApp Business (WhatsApp Business Account) |
| **BSP / Tech Provider** | Prestataire agréé par Meta pour proposer WhatsApp à d'autres entreprises |
| **Fenêtre de service** | Période de 24 h après le dernier message du client, pendant laquelle un texte libre est autorisé |
| **SSRF** | Attaque qui fait appeler par le serveur une adresse interne ; bloquée dans le crawler |
| **Injection de consignes** | Texte piégé (dans un document ou un message) qui tente de détourner l'assistant |
| **Handoff** | Passage de la conversation du bot à un humain |

## Annexe B. Sources

Les sources consultées pour ce document sont recensées, avec leur date de consultation, dans [RECHERCHE.md](RECHERCHE.md).
