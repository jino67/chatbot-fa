# Modèle de coûts d'exploitation

Ce document permet de calculer ce que coûte un client et de fixer un prix. **Tous les chiffres sont des estimations** fondées sur des hypothèses explicites : remplacez-les par les mesures réelles du pilote (chaque réponse enregistre ses jetons dans `messages.meta`).

## 1. Coût de l'IA par réponse

### 1.1 Tarifs de référence (USD par million de jetons)

Relevé du 25 septembre 2026, tarifs de l'API Anthropic ; à revérifier avant usage.

| Modèle | Entrée | Sortie | Lecture du cache |
|---|---|---|---|
| Claude Opus 5.5 (`claude-opus-5-5`) | 4,00 | 20,00 | 0,20 |
| Claude Sonnet 5.5 (`claude-sonnet-5-5`) | 2,00 | 10,00 | 0,20 |
| Claude Haiku 4.5 (`claude-haiku-4-5`) | 1,00 | 5,00 | non relevé |

Le modèle se règle par `PLATFORM_MODEL`. **Le code livre `claude-opus-5-5` par défaut : c'est un choix à valider** (question Q1 du TDR), pas une recommandation de coût.

### 1.2 Hypothèses par réponse

| Élément | Estimation | Justification |
|---|---|---|
| Prompt système | 450 jetons | Règles fixes de l'assistant + consignes du client |
| Extraits de la base | 1 800 jetons | 6 extraits d'environ 300 jetons (plafond 9 000 caractères) |
| Historique | 500 jetons | 8 derniers messages |
| Question et date | 50 jetons | |
| **Entrée totale** | **≈ 2 800 jetons** | |
| Sortie, Opus 5.5 et Sonnet 5.5 | ≈ 400 jetons | Réponse courte + raisonnement (toujours actif sur ces modèles, effort « low ») |
| Sortie, Haiku 4.5 | ≈ 200 jetons | Réponse courte, sans raisonnement |

### 1.3 Résultat

| Modèle | Coût par réponse | Pour 1 000 réponses | Pour 5 000 réponses |
|---|---|---|---|
| Opus 5.5 | ≈ 0,019 USD | ≈ 19 USD | ≈ 96 USD |
| Sonnet 5.5 | ≈ 0,010 USD | ≈ 10 USD | ≈ 48 USD |
| Haiku 4.5 | ≈ 0,004 USD | ≈ 4 USD | ≈ 19 USD |

Formule : `coût = entrée × tarif_entrée / 1 000 000 + sortie × tarif_sortie / 1 000 000`.

### 1.4 Ce qui réduit ce coût dans le code

- **Aucun appel IA** quand aucun extrait n'est pertinent (hors salutation et réclamation), pour les demandes de conseiller humain, et quand le quota est atteint.
- Réponses courtes exigées par le prompt.
- Prompt système identique d'un message à l'autre, marqué mis en cache (le gain dépend de la taille minimale du modèle pour le cache : à mesurer via `cache_read` dans les jetons enregistrés).
- Nombre d'extraits limité (`top_k`) et taille de contexte plafonnée.

### 1.5 Ce qui l'augmente

- Historique long, extraits nombreux, réponses longues.
- Consignes du client très longues.
- Lecture de photos et de PDF scannés : un appel par fichier à l'import, avec jusqu'à 8 000 jetons de sortie ; coût ponctuel, pas par question.

## 2. Embeddings et ingestion

L'ingestion d'un site de 100 pages (environ 50 000 jetons) et chaque question (environ 30 jetons) consomment très peu d'embeddings : de l'ordre de quelques centimes au total. **Vérifier la grille du fournisseur choisi (Voyage ou OpenAI)** ; le mode local `hashing` est gratuit mais sert seulement aux démonstrations.

## 3. WhatsApp

| Poste | Meta direct | Twilio |
|---|---|---|
| Marge de l'intermédiaire | 0 | 0,005 USD par message reçu et par message envoyé |
| Tarifs Meta | Facturés au client sur son compte | Répercutés |
| Illustration : 1 000 conversations de 5 messages reçus et 5 envoyés (10 000 messages) | 0 | **≈ 50 USD par mois** |

Tarifs Meta : par message délivré ; catégories marketing, utilitaire, authentification, service. Rapporté pour le « reste de l'Afrique » : environ 0,0225 USD par message marketing (les autres catégories n'ont pas été relevées). **À partir du 1er octobre 2026**, les messages de service sont facturés au tarif utilitaire de chaque marché après 1 000 messages gratuits par numéro et par mois ; les réponses en modèle utilitaire dans la fenêtre ne sont plus gratuites. Un assistant qui répond dans la fenêtre de 24 h est concerné par ce changement.

## 4. Hébergement et services

Ordres de grandeur à confirmer par devis :

| Poste | Estimation |
|---|---|
| Serveur d'application (2 vCPU, 4 Go) | 10 à 30 USD par mois |
| PostgreSQL managé (dès la phase 3) | 15 à 50 USD par mois |
| Redis (si utilisé) | 0 à 15 USD par mois |
| Sauvegardes et stockage des fichiers | 5 à 15 USD par mois |
| Nom de domaine, certificat | 15 à 30 USD par an |
| Supervision, journaux | 0 à 25 USD par mois |

## 5. Exemple : coût mensuel d'un client

Hypothèse : 2 000 réponses IA par mois sur le site et WhatsApp, Meta direct, modèle Sonnet 5.5.

| Poste | Coût |
|---|---|
| IA (2 000 × 0,010) | ≈ 20 USD |
| Embeddings et ingestion | quelques centimes |
| WhatsApp (marge) | 0 |
| Messages WhatsApp Meta | payés par le client |
| Quote-part d'hébergement (à répartir sur l'ensemble des clients) | à calculer |

Avec Opus 5.5 le même client coûte environ 38 USD par mois d'IA.

## 6. Fixer les offres

Les quotas actuels (`config/platform.php`) sont des **valeurs d'essai** :

| Offre | Assistants | Sources | Pages par site | Réponses par mois |
|---|---|---|---|---|
| Gratuit | 1 | 5 | 20 | 100 |
| Starter | 3 | 30 | 100 | 2 000 |
| Pro | 10 | 200 | 500 | 10 000 |

Méthode : prendre le coût IA par réponse **mesuré** au pilote, ajouter la quote-part d'hébergement et de support, viser une marge d'un facteur 3 à 5, puis arrondir à des paliers simples en monnaie locale. L'offre gratuite doit rester assez petite pour ne pas coûter cher (100 réponses coûtent de l'ordre de 0,4 à 2 USD selon le modèle).

## 7. Suivi

Chaque réponse enregistre `tokens.in`, `tokens.out`, `tokens.cache_read`, le modèle et la latence. Pour un mois donné :

```sql
-- exemple SQLite : jetons consommés par entreprise ce mois-ci
select workspace_id,
       sum(json_extract(meta, '$.tokens.in'))  as jetons_entree,
       sum(json_extract(meta, '$.tokens.out')) as jetons_sortie,
       count(*)                                as reponses
from messages
where role = 'assistant' and json_extract(meta, '$.llm') = 1
  and created_at >= date('now', 'start of month')
group by workspace_id;
```

Un tableau de bord de coût par client est prévu en phase 3.
