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
| Frais de l'intermédiaire | 0 | 0,005 USD par message reçu et par message envoyé |
| Tarifs Meta | Payés par la plateforme | Payés par la plateforme, via le portefeuille Twilio |
| Illustration : 1 000 conversations de 5 messages reçus et 5 envoyés (10 000 messages) | 0 | **≈ 50 USD par mois**, à la charge de la plateforme |

**Qui paie ?** La plateforme : les clients n'ont ni carte bancaire à saisir, ni compte à ouvrir chez Meta ou Twilio. Chaque offre inclut un volume de messages par mois ; le dépassement se recharge par Mobile Money. Chaque message est enregistré avec son coût estimé (page Consommation du super admin, D14).

Tarifs Meta : par message délivré ; catégories marketing, utilitaire, authentification, service. Rapporté pour le « reste de l'Afrique » : environ 0,0225 USD par message marketing (les autres catégories n'ont pas été relevées). **À partir du 1er octobre 2026**, les messages de service sont facturés au tarif utilitaire de chaque marché après 1 000 messages gratuits par numéro et par mois ; les réponses en modèle utilitaire dans la fenêtre ne sont plus gratuites. Un assistant qui répond dans la fenêtre de 24 h est concerné par ce changement.

**Point non tranché (vérifié le 30 septembre 2026).** La page officielle de tarification de Meta indique que les modèles utilitaires envoyés dans une fenêtre de service ouverte sont gratuits, et ne liste pas « reste de l'Afrique » parmi les marchés modifiés le 1er octobre 2026. Des sources tierces annoncent au contraire que les messages de service deviennent payants à cette date. Les grilles de tarifs sont des fichiers téléchargeables, non lisibles depuis la page. **Tant que ce n'est pas tranché, les tarifs Meta se règlent dans l'administration** (valeurs par défaut : service 0, utilitaire 0,0077, marketing 0,0225 USD) **et la page Consommation en tient compte.** Avec Meta direct comme avec Twilio, ces frais sont à la charge de la plateforme : à vérifier dans la grille téléchargeable de Meta avant de resserrer les marges.

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
| Messages WhatsApp (Meta direct, catégorie service) | 0 à quelques dollars selon la grille Meta |
| Messages vocaux (100 événements à 0,002 USD en moyenne) | ≈ 0,2 USD |
| Quote-part d'hébergement (à répartir sur l'ensemble des clients) | à calculer |

Avec Opus 5.5 le même client coûte environ 38 USD par mois d'IA.

## 6. Fixer les offres

Les prix sont calculés à partir des coûts ci-dessus. **Les messages WhatsApp et les messages vocaux sont payés par la plateforme** : ils entrent donc dans le coût de chaque offre, avec un volume inclus par mois. Cibles : au moins **60 % de marge à usage normal** (40 % du volume), au moins **25 % au volume entier par Twilio**, au moins **45 % au volume entier par Meta direct**. Le test `BonPlanTest` recalcule ces marges à chaque exécution et échoue si une offre devient déficitaire. Les prix se règlent ensuite sans code, offre par offre et devise par devise (Administration, Offres).

### 6.1 Hypothèses du calcul

| Élément | Valeur | Source ou justification |
|---|---|---|
| Coût IA par réponse, pire cas (Claude Haiku 4.5) | 0,0038 USD | 2 800 jetons en entrée à 1 USD par million, 200 en sortie à 5 USD par million |
| Marge de sécurité sur l'IA | + 15 % | Relances, contextes longs, lecture de photos et PDF, ingestion : 0,00437 USD par réponse |
| Coût IA par réponse, OpenAI `gpt-4o-mini` | 0,00054 USD | 0,15 et 0,60 USD par million de jetons (relevé du 30 septembre 2026) |
| Hébergement et support, par client et par mois | Essentiel 4, Bon plan 5, Pro 6, Business 12 USD | Quote-part de l'infrastructure (section 4) et du suivi ; à recalculer avec le nombre réel de clients |
| Frais de paiement | 3 % | Mobile Money, virement, carte |
| Message WhatsApp par Meta direct | 0 (service) à 0,0077 USD (utilitaire) | Payé par la plateforme ; catégorie de service supposée gratuite dans la fenêtre de 24 h (voir le point non tranché ci-dessus) |
| Message WhatsApp par Twilio | 0,005 USD par message reçu et par message envoyé | Payé par la plateforme, hors frais Meta |
| Écoute d'un message vocal | 0,003 USD par minute (`gpt-4o-mini-transcribe`) | Relevé du 30 septembre 2026 ; whisper-1 : 0,006 |
| Réponse en audio | 0,015 USD par minute dite (`gpt-4o-mini-tts`) | Relevé du 30 septembre 2026 |
| Événement vocal, pire cas | 0,01 USD | Un vocal de deux minutes, ou une réponse dite pendant une minute |
| Import des discussions WhatsApp | environ 0,003 USD par import | Un appel de résumé du style (7 000 caractères en entrée) ; le reste est local |
| Parités | 1 EUR = 655,957 XOF = 491,968 KMF ; 1 EUR = 10,8 MAD | Parités fixes pour le FCFA et le franc comorien ; dirham à revoir |
| Dollar | 1 EUR = 1,1339 USD | Taux du 30 septembre 2026, à revoir de temps en temps |

La marge est `(prix - coûts) / prix`, hors taxes.

### 6.2 Offres retenues

| Offre | FCFA | KMF | Euro | Dollar | Dirham | Réponses IA | Assistants | WhatsApp (messages) | Vocaux |
|---|---|---|---|---|---|---|---|---|---|
| Découverte | Gratuit, 14 jours | | | | | 100 | 1 | non | non |
| Essentiel | 10 000 | 7 500 | 15 | 17 | 165 | 1 000 | 1 | non | non |
| **Bon plan** | **25 000** | 18 750 | 38 | 43 | 410 | 2 000 | **1** | 800 | 100 |
| Pro | 40 000 | 30 000 | 60 | 70 | 660 | 5 000 | 3 | 2 500 | 300 |
| Business | 120 000 | 90 000 | 180 | 205 | 1 975 | 15 000 | 10 | 10 000 | 1 500 |
| API Développeur (page à part) | 20 000 | 15 000 | 30 | 35 | 330 | 3 000 | 3 | non | non |

Le **Bon plan** est l'offre intermédiaire : un seul assistant, site web et WhatsApp, vocaux en volume mesuré, 40 sources, 3 utilisateurs. Il garde la mention « Propulsé par » et n'inclut ni les modèles de messages WhatsApp (facturés par Meta) ni l'import des discussions (option à 5 000 FCFA). Pro ajoute plusieurs assistants, le retrait de la mention, les modèles de messages et l'import. L'euro et le dollar sont arrondis à un prix simple, à moins de 5 % de l'équivalent en FCFA.

**Options à la carte** : lot de 1 000 messages WhatsApp (6 000 FCFA), import des discussions WhatsApp pour une offre qui ne l'inclut pas (5 000 FCFA, une fois).

### 6.3 Marges

Calculées avec l'IA au pire tarif (Haiku 4.5, marge de sécurité comprise), 40 % du volume pour l'usage normal, un événement vocal à 0,01 USD.

| Offre | Usage normal | Volume entier, tout par Twilio | Volume entier, tout par Meta direct |
|---|---|---|---|
| Essentiel | 64 % | 49 % | 49 % |
| Bon plan | 73 % | 54 % | 63 % |
| Pro | 67 % | 34 % | 52 % |
| Business | 66 % | 28 % | 52 % |

Ce qui a changé par rapport à la grille précédente (Pro 35 000, Business 90 000 FCFA, WhatsApp payé par le client) : avec les messages à la charge de la plateforme, l'ancienne grille tombait à 23 % puis 2 % de marge si le client consommait tout son volume par Twilio.

### 6.4 Décisions qui en découlent

- **L'offre à 10 000 FCFA n'inclut pas WhatsApp.** Elle sert le site web seulement. Le **Bon plan à 25 000 FCFA** est le premier palier avec WhatsApp, pour un seul assistant.
- **WhatsApp par Meta direct est le mode par défaut** (D10), car il n'ajoute aucun frais d'intermédiaire. Twilio reste utile pour démarrer vite ; son surcoût est supporté par la plateforme, et la page Consommation le montre client par client.
- **Le dépassement se recharge** : lot de 1 000 messages par Mobile Money, ajouté par l'équipe (`wa_credit`).
- **L'offre gratuite est un essai de 14 jours**, limité à 100 réponses : au pire 0,44 USD par essai avec Haiku. Passé ce délai, l'assistant se met en pause (les données sont conservées) jusqu'au choix d'une offre.
- **Un abonné dont la période de grâce est écoulée** repasse à l'offre gratuite avec un essai déjà consommé : son assistant est en pause tant qu'il ne renouvelle pas.

### 6.5 Recalculer

À refaire quand l'un de ces éléments change : tarifs du modèle principal, choix du modèle par défaut, grille Meta ou Twilio, tarifs de la voix, nombre de clients (donc la quote-part d'hébergement), taux du dollar. Remplacer ensuite les valeurs dans l'administration (Paramètres, WhatsApp et coûts) et adapter `BonPlanTest::margins()` ; la migration `revise_plans_for_new_features` ne sert qu'à la première installation. Mesurer le coût réel par client avec la page Consommation avant de resserrer les quotas.

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
