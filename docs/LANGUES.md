# Langues et voix

Ce document dit ce que l'assistant sait faire dans chaque langue, ce qui est branché, ce qui ne l'est pas, et comment ajouter un modèle libre pour les langues locales. Relevé des modèles : 30 septembre 2026, à revérifier avant tout engagement (les licences et les performances changent).

## 1. Ce que choisit le client

Dans les réglages de l'assistant (« Langues et voix »), le client coche **plusieurs langues** et désigne la **langue principale**. Il peut changer sa liste à tout moment ; le changement s'applique à la conversation suivante.

- Le catalogue est `config/languages.php` (accesseur : `App\Support\Languages`). Chaque langue porte un **niveau de fiabilité** que le client lit avant de cocher.
- Le prompt de la plateforme construit sa règle de langue à partir de la liste (`Languages::promptRule`) : une seule langue, ou « réponds dans la langue du dernier message quand elle fait partie de la liste, sinon dans la langue principale ». La consigne écrite du client ne cite aucune langue, pour ne jamais contredire ses réglages.
- Pour une langue locale, le prompt ajoute une prudence : phrases courtes, prix, chiffres et noms propres repris tels quels des extraits, aucun mot inventé, repli sur la langue principale en cas de doute.
- Le widget affiche un sélecteur de langue quand l'assistant en parle plusieurs ; la langue choisie est transmise à chaque message (`lang`, validée contre la liste de l'assistant).

| Langue | Code | Niveau | Écoute des vocaux | Réponse en audio |
|---|---|---|---|---|
| Français | fr | Très bon | oui (OpenAI) | oui |
| Anglais | en | Très bon | oui (OpenAI) | oui |
| Arabe | ar | Très bon | oui (OpenAI) | oui |
| Swahili | sw | Bon | oui (OpenAI) | non |
| Bambara | bm | Assisté | serveur libre requis | non |
| Dioula | dyu | Assisté | serveur libre requis | non |
| Peul | ff | Assisté | serveur libre requis | non |
| Wolof | wo | Assisté | serveur libre requis | non |
| Mooré | mos | Expérimental | serveur libre requis | non |
| Shikomori | zdj | Expérimental | aucun modèle | non |

« Assisté » : l'assistant comprend et écrit ces langues de façon imparfaite ; à tester avec de vraies phrases avant de s'engager auprès d'un client. « Expérimental » : très peu de données existent ; l'assistant répond aussi en français. Les niveaux se corrigent dans `config/languages.php` dès qu'un locuteur a testé.

## 2. La voix

### 2.1 Ce qui existe

- **Écoute** : un message vocal (WhatsApp Meta, WhatsApp Twilio, micro du widget) est transcrit, puis traité comme un message écrit. La transcription est enregistrée comme texte du client (`meta.voice = true`) et le prompt précise qu'elle peut contenir des erreurs.
- **Réponse en audio** : trois réglages par assistant, `voice_out` : **jamais**, **miroir** (audio quand le client a envoyé un vocal), **toujours**. Le texte de la réponse part **toujours**, l'audio s'y ajoute (WhatsApp : audio d'abord, texte ensuite). Une réponse trop longue est dite jusqu'à une fin de phrase, puis renvoie vers le texte.
- **Voix** : féminine, masculine ou neutre. Interprétation demandée : ton chaleureux, rythme posé, chiffres et prix prononcés clairement.
- **Offre** : option `voice` de l'offre (Bon plan, Pro, Business) et volume mensuel `voice_per_month` (événements écoutés ou envoyés). Au-delà, le client reçoit une invitation à écrire ; rien n'est facturé sans transcription.
- **Limites** : 120 secondes et 8 Mo par vocal (`platform.speech`).

### 2.2 Moteurs

Interface `SpeechClient`, sur le modèle de `LlmClient` : `OpenAiSpeech` (n'importe quelle API compatible OpenAI), `SpeechRouter` (choisit le moteur selon la langue), `FakeSpeech` (tests et démonstration hors ligne).

| Usage | Modèle par défaut | Repli | Prix relevé |
|---|---|---|---|
| Écoute | `gpt-4o-mini-transcribe` | `whisper-1` si le modèle est absent | 0,003 $ par minute (whisper-1 : 0,006 $) |
| Voix | `gpt-4o-mini-tts`, format `opus` | aucun | environ 0,015 $ par minute dite |

La clé est cherchée dans cet ordre : réglage « Voix » de l'administration, fournisseur OpenAI de la chaîne de modèles, variable `OPENAI_API_KEY`. Sans clé, la voix est indisponible (jamais de faux moteur en production).

Vérification faite le 30 septembre 2026 avec la clé de la plateforme : une phrase de 8 secondes a été synthétisée (ogg/opus, en-tête `OggS`, durée relue de la dernière page ogg) puis transcrite correctement, en 4,8 s et 1,6 s. Le format ogg/opus mono est celui qu'accepte WhatsApp pour un message vocal. Le bouton « Tester la voix » (Administration, Paramètres, Voix) refait cet essai pour quelques millièmes de dollar.

### 2.3 Où passe le son

- **WhatsApp Meta** : le webhook donne un `media id` ; le média se télécharge en deux temps (adresse temporaire, puis fichier, avec le jeton). Pour répondre : dépôt du fichier (`/{phone_number_id}/media`), puis message `audio`.
- **WhatsApp Twilio** : l'URL du média entrant se télécharge avec les identifiants du compte. Pour répondre, Twilio va chercher le fichier : il est déposé dans le stockage privé et servi par une adresse **signée** (`/media/voice/...`, 2 heures), à partir de `APP_URL` ou de `TWILIO_WEBHOOK_BASE_URL`. L'application doit donc être joignable publiquement.
- **Widget** : `MediaRecorder` (webm/opus ou mp4 selon le navigateur), envoi en `multipart` à `/voice`, réponse avec transcription, texte et audio en `data:` ; bouton « Écouter » sous chaque réponse (`/speak`).

## 3. Langues locales : ce qu'on a trouvé

Recherche du 30 septembre 2026. Seuls les modèles à licence commerciale sont utilisables.

| Modèle | Ce qu'il fait | Licence | Verdict |
|---|---|---|---|
| **Omnilingual ASR** (Meta) | Reconnaissance vocale, bambara, dioula, mooré, peul, wolof, lingala, haoussa, swahili, arabe, français... (pas le comorien) | Apache 2.0 | **Utilisable**, à héberger soi-même |
| **Bambara-ASR-v2** (MALIBA-AI) | Whisper-large-v2 affiné sur le bambara, WER environ 25 %, CER environ 11 % | Apache 2.0 | **Utilisable**, bambara seulement |
| **MADLAD-400** (Google) | Traduction, dont bambara, dioula, peul, wolof, lingala, haoussa, swahili, arabe (pas le mooré, pas le comorien) | Apache 2.0 | Utilisable, non branché (l'assistant écrit déjà par son modèle de langage) |
| MMS (Meta) | Reconnaissance et synthèse vocale, plus de 1 100 langues | CC-BY-NC | **Interdit** en usage commercial |
| NLLB-200 (Meta) | Traduction | CC-BY-NC | **Interdit** en usage commercial |
| bambara-tts (MALIBA-AI) | Synthèse vocale bambara | Recherche seulement | **Interdit** |
| GPT-4o | Écrit et comprend le bambara | Service payant | Le moins bon des grands modèles sur le bambara |

**Aucune synthèse vocale libre à licence commerciale n'a été trouvée pour le bambara, le dioula ou le mooré** : dans ces langues, l'assistant répond par écrit (l'option « répondre en audio » n'y est pas proposée). L'écoute des vocaux, elle, est faisable avec un serveur libre.

## 4. Brancher un serveur libre pour l'écoute des langues locales

Le serveur doit exposer le contrat OpenAI `POST /v1/audio/transcriptions` (Speaches, faster-whisper-server, vLLM avec un modèle audio...). Deux façons de le déclarer :

- Administration, Paramètres, « Voix » : adresse (`https://asr.exemple.com/v1`), modèle, clé si besoin. Le bouton « Tester la voix » vérifie que le serveur répond.
- Ou par l'environnement : `LOCAL_STT_URL`, `LOCAL_STT_MODEL`, `LOCAL_STT_KEY`.

Ensuite, pour un assistant dont **la langue principale** est locale (bambara, dioula, peul, wolof, mooré), les vocaux partent vers ce serveur ; pour tout autre assistant, ils partent vers OpenAI avec détection automatique de la langue. Sans serveur libre, le micro n'est pas proposé pour une langue locale et l'assistant demande d'écrire.

**Limite connue** : un assistant qui parle à la fois français et bambara envoie ses vocaux à OpenAI si le français est sa langue principale (la langue d'un vocal n'est pas détectée avant la transcription). Pour un public surtout bambaraphone, choisir le bambara comme langue principale. Une détection de la langue avant l'écoute est une amélioration possible.

## 5. Ce qu'il reste à faire hors du code

- Faire tester chaque langue locale par un locuteur (phrases de vente, chiffres, salutations) et ajuster les niveaux de `config/languages.php`.
- Choisir et héberger le serveur d'écoute libre si des clients veulent des vocaux en langues locales (un serveur avec GPU, ou un hébergeur de modèles compatible OpenAI).
- Vérifier les salutations locales du pied de page de la page d'accueil avec un locuteur (bambara et dioula « I ni ce », mooré « Ne y windiga », peul « Jam waali ») ; le shikomori n'y figure pas, faute de vérification.
