# Guide du développeur Kouma

## Bienvenue

Ce guide s'adresse aux développeurs et aux webmasters qui veulent mettre l'assistant d'une entreprise **dans leur propre site ou application**. Il couvre trois manières d'intégrer Kouma, de la plus simple à la plus libre :

| Besoin | Solution | Difficulté |
|---|---|---|
| Afficher une bulle de discussion sur un site web | **Le widget** : une ligne de code | Cinq minutes |
| Appeler l'assistant depuis une application mobile, un back-office ou un serveur | **L'API développeur** : une clé secrète, une requête | Une heure |
| Construire une interface de chat entièrement sur mesure | **L'API publique du widget** | Une demi-journée |

WhatsApp, lui, ne demande aucun développement : notre équipe active le canal et les messages arrivent directement à l'assistant.

> **On s'occupe de tout :** si vous préférez déléguer l'intégration, notre équipe peut installer le widget, configurer l'assistant et brancher WhatsApp pour vous. Nos coordonnées se trouvent à la fin de ce guide.

### Les notions à connaître

- **Assistant** : l'agent conversationnel d'une entreprise, avec ses sources de connaissances, sa consigne, ses langues. Un compte peut avoir plusieurs assistants.
- **Source** : un document, un site, une photo ou un texte que l'assistant a appris. Ses réponses viennent de là.
- **Conversation** : un fil de messages avec un client. Chaque canal (site, WhatsApp, API) a ses conversations, visibles dans l'onglet « Conversations » du tableau de bord.
- **Réponse comptée** : chaque réponse de l'assistant, quel que soit le canal, compte dans le **quota mensuel** de l'offre.
- **Clé publique** (`pk_...`) : identifie un assistant pour le widget. Elle peut être visible dans le code de la page.
- **Clé secrète** (`kma_...`) : authentifie vos appels à l'API développeur. **Elle ne doit jamais apparaître côté navigateur ni dans une application distribuée.**

## 1. Le widget de chat

### Installation

Dans le tableau de bord, ouvrez l'assistant, onglet **Canaux**, bloc **Votre site web**, et copiez la ligne de code. Elle ressemble à ceci :

```html
<script src="https://kouma.site/widget/widget.js" data-bot="pk_votre_cle_publique" async></script>
```

Collez-la juste avant la balise `</body>` de chaque page où l'assistant doit apparaître. Une bulle de discussion s'affiche, aux couleurs de l'assistant, avec son message d'accueil, ses questions suggérées, le microphone (si les messages vocaux sont activés) et le sélecteur de langue (si l'assistant parle plusieurs langues).

| Outil | Emplacement |
|---|---|
| WordPress | Extension « Insert Headers and Footers », zone « Footer » |
| Shopify | Boutique en ligne, Thèmes, Modifier le code, `theme.liquid`, avant `</body>` |
| Wix, Squarespace | « Code personnalisé », en pied de page |
| Application web (React, Vue, etc.) | Charger le script une seule fois, par exemple dans `index.html` |
| Site statique | Avant `</body>` |

### Options du script

| Attribut | Rôle |
|---|---|
| `data-bot` | **Obligatoire.** La clé publique de l'assistant |
| `data-open="true"` | Ouvre la fenêtre dès le chargement de la page |
| `data-api="https://..."` | Surcharge l'adresse de l'API (rarement utile) |

### Contrôler le widget par programmation

Une fois le script chargé, un objet global `window.KoumaWidget` est disponible :

```js
window.KoumaWidget.open();    // ouvre la fenêtre
window.KoumaWidget.close();   // la ferme
window.KoumaWidget.toggle();  // bascule
```

Un exemple courant : un bouton « Poser une question » de votre page qui ouvre le widget.

```html
<button onclick="window.KoumaWidget && window.KoumaWidget.open()">Poser une question</button>
```

### Charger le widget à la demande

Pour ne pas alourdir une page, chargez le script seulement au clic :

```js
function openKoumaChat() {
  if (window.KoumaWidget) { window.KoumaWidget.open(); return; }
  const s = document.createElement('script');
  s.src = 'https://kouma.site/widget/widget.js';
  s.async = true;
  s.setAttribute('data-bot', 'pk_votre_cle_publique');
  s.setAttribute('data-open', 'true');
  document.body.appendChild(s);
}
```

### Apparence et comportement

Tout se règle dans l'onglet **Réglages** de l'assistant, sans toucher au code : titre de la fenêtre, couleur, position (droite ou gauche), message d'accueil, questions suggérées, langues, voix, demande du nom et du téléphone avant la discussion. Les modifications s'appliquent immédiatement, sans republier votre site.

Le widget se charge en un seul fichier JavaScript, sans dépendance et sans cookie : il garde un identifiant de visiteur dans le stockage local du navigateur pour retrouver la conversation en cours.

### Sites autorisés (origines)

Dans **Réglages**, rubrique « Sécurité », la liste **Sites autorisés à afficher le widget** limite les sites qui peuvent utiliser votre assistant dans un navigateur. Une origine est écrite avec son schéma : `https://www.monsite.com`. Avec la liste vide, le widget fonctionne partout : **renseignez-la avant la mise en production**.

> **À savoir :** cette liste protège contre l'usage du widget par un site tiers. Elle ne protège pas contre un appel direct de serveur à serveur avec votre clé publique : seules la limitation de débit et les quotas de l'offre s'appliquent alors. Pour un usage serveur, préférez l'API développeur.

### Politique de sécurité du contenu (CSP)

Si votre site impose une CSP, autorisez le domaine de Kouma :

- `script-src` : le domaine du widget (`https://kouma.site`) ;
- `connect-src` : le même domaine, pour les appels de l'API ;
- `img-src` et `media-src` : `data:` si vous utilisez les réponses audio.

### Performances

Le script est chargé de façon asynchrone (`async`) : il ne bloque pas l'affichage de votre page. Le fichier est mis en cache par le navigateur et reste à jour chez tous les clients, sans republication.

## 2. L'API développeur

L'API développeur permet d'appeler un assistant depuis **votre serveur ou votre application**, avec une clé secrète. Chaque appel renvoie la réponse, ses sources, et l'état de votre consommation.

### Prérequis

- Une offre qui **inclut l'API** (par exemple « API Développeur »). La page **Développeurs** apparaît alors dans le menu du tableau de bord.
- Un assistant avec ses connaissances (créez-le comme n'importe quel assistant et testez-le dans l'onglet « Tester »).

### Créer une clé

1. Ouvrez **Développeurs** dans le menu.
2. Donnez un **nom** à la clé (par exemple « Application mobile ») et choisissez l'**assistant** concerné.
3. Cliquez pour créer : la clé s'affiche **une seule fois**. Copiez-la immédiatement dans votre gestionnaire de secrets.

Une clé est liée à **un assistant**. Vous pouvez avoir jusqu'à **dix clés actives**. Une clé se **révoque en un clic** : elle cesse alors de fonctionner. La date de dernière utilisation est affichée pour repérer les clés oubliées.

> **Attention :** une clé secrète donne accès à l'assistant et à son quota. Ne la mettez jamais dans le code d'une page web, d'une application mobile ou d'un dépôt public. Appelez l'API depuis **votre serveur**, qui transmet les messages de vos utilisateurs.

### Authentification

Toutes les requêtes portent l'en-tête :

```
Authorization: Bearer kma_votre_cle_secrete
```

### Envoyer un message : `POST /api/v1/chat`

```bash
curl https://kouma.site/api/v1/chat \
  -H "Authorization: Bearer kma_votre_cle_secrete" \
  -H "Content-Type: application/json" \
  -d '{"message": "Vous livrez à Bobo ?", "conversation_id": "client-4821", "user": "Awa"}'
```

**Corps de la requête (JSON)**

| Champ | Type | Obligatoire | Description |
|---|---|---|---|
| `message` | texte | oui | La question, 2 000 caractères au plus |
| `conversation_id` | texte | non | Identifiant de votre choix pour garder le fil de la discussion : 8 à 64 caractères, lettres, chiffres, tirets et tirets bas. Sans lui, un identifiant est créé et renvoyé |
| `user` | texte | non | Le nom de la personne (120 caractères au plus), visible dans la conversation du tableau de bord |

**Réponse (200)**

```json
{
  "conversation_id": "client-4821",
  "answer": "Oui, à Bobo-Dioulasso : 2 000 FCFA, livré sous 48 h.",
  "grounded": true,
  "handoff": false,
  "suggestions": ["Commander", "Autres villes"],
  "sources": [{ "title": "Livraison", "url": "https://boutique.example/livraison" }],
  "usage": { "answers_used": 128, "answers_limit": 3000, "resets_at": "2026-11-01T00:00:00+00:00" }
}
```

| Champ | Signification |
|---|---|
| `conversation_id` | À renvoyer aux appels suivants pour poursuivre la conversation |
| `answer` | La réponse de l'assistant, en texte (avec des retours à la ligne ; les marqueurs internes sont déjà retirés) |
| `grounded` | `true` si la réponse s'appuie sur les sources ; `false` quand l'assistant n'a pas trouvé l'information |
| `handoff` | `true` quand une personne doit reprendre la conversation (le client l'a demandé, ou l'assistant n'a pas su répondre deux fois de suite) |
| `suggestions` | Réponses rapides conseillées, à proposer comme boutons |
| `sources` | Les pages sources citées (titre et adresse), quand elles ont une adresse |
| `usage` | Votre consommation du mois : réponses utilisées, quota, date de remise à zéro |

### Consulter sa consommation : `GET /api/v1/usage`

```bash
curl https://kouma.site/api/v1/usage -H "Authorization: Bearer kma_votre_cle_secrete"
```

```json
{
  "plan": "API Développeur",
  "usage": { "answers_used": 128, "answers_limit": 3000, "resets_at": "2026-11-01T00:00:00+00:00" },
  "assistant": { "name": "Assistant boutique" }
}
```

### Erreurs

Quelle que soit l'erreur de sécurité ou de quota, la réponse est un objet `{ "error": { "code": "...", "message": "..." } }` avec un code stable et un message clair.

| Statut | Code | Signification | Que faire |
|---|---|---|---|
| 401 | `missing_key` | L'en-tête `Authorization` est absent | Ajoutez `Authorization: Bearer kma_...` |
| 401 | `invalid_key` | Clé inconnue ou révoquée | Vérifiez la clé, créez-en une nouvelle |
| 403 | `plan_required` | L'offre de l'espace n'inclut pas l'API | Passez à l'offre qui inclut l'API |
| 403 | `account_suspended` | Espace suspendu ou essai terminé | Contactez l'équipe |
| 422 | (validation) | Message vide ou trop long, `conversation_id` invalide | Corrigez la requête ; le corps décrit chaque champ en cause |
| 429 | `quota_exceeded` | Le quota de réponses du mois est atteint | Changez d'offre ou attendez `usage.resets_at` |
| 429 | (limitation de débit) | Trop de requêtes | Réessayez après le délai de l'en-tête `Retry-After` |

### Limites

- **60 requêtes par minute et par clé**, avec un plafond de **240 requêtes par minute et par adresse IP**.
- Le quota mensuel de réponses dépend de l'offre. Chaque réponse compte, quel que soit le canal.
- Une réponse peut prendre **plusieurs secondes** : prévoyez un délai d'attente (timeout) de 30 secondes au moins.

### Gérer une conversation

- Utilisez un **`conversation_id` stable par utilisateur** (par exemple l'identifiant de votre utilisateur) : l'assistant se souvient de l'échange.
- Les conversations de l'API apparaissent dans l'onglet **Conversations** du tableau de bord, avec le canal « api ».
- Quand `handoff` vaut `true`, la conversation est en **« À traiter »** dans le tableau de bord : une personne du client la reprend. L'équipe de l'entreprise est **prévenue tout de suite** (notification sur son téléphone avec le compteur sur l'icône, selon ses alertes), et chaque nouveau message de votre utilisateur pendant que la personne a la main déclenche une alerte (une toutes les cinq minutes au plus). Dans votre interface, affichez par exemple « Un conseiller va vous répondre ».
- Quand `grounded` vaut `false`, l'assistant dit qu'il ne sait pas : vous pouvez proposer au visiteur de contacter l'entreprise.

### Exemples

#### JavaScript (Node.js, côté serveur)

```js
const res = await fetch('https://kouma.site/api/v1/chat', {
  method: 'POST',
  headers: {
    Authorization: `Bearer ${process.env.KOUMA_API_KEY}`,
    'Content-Type': 'application/json',
  },
  body: JSON.stringify({ message: 'Vous livrez à Bobo ?', conversation_id: 'client-4821' }),
});

if (!res.ok) {
  const { error } = await res.json();
  throw new Error(`Kouma ${res.status} ${error?.code ?? ''} : ${error?.message ?? ''}`);
}

const { answer, sources, handoff, usage } = await res.json();
```

#### PHP

```php
$response = Http::withToken(env('KOUMA_API_KEY'))
    ->timeout(30)
    ->acceptJson()
    ->post('https://kouma.site/api/v1/chat', [
        'message' => 'Vous livrez à Bobo ?',
        'conversation_id' => 'client-4821',
    ]);

if ($response->failed()) {
    report(new RuntimeException('Kouma : '.$response->json('error.message', $response->status())));
}

$answer = $response->json('answer');
```

#### Python

```python
import os, requests

r = requests.post(
    "https://kouma.site/api/v1/chat",
    headers={"Authorization": f"Bearer {os.environ['KOUMA_API_KEY']}"},
    json={"message": "Vous livrez à Bobo ?", "conversation_id": "client-4821"},
    timeout=30,
)
r.raise_for_status()
print(r.json()["answer"])
```

#### Passerelle pour votre application mobile ou votre page web

Votre application ne contient jamais la clé : elle appelle **votre** serveur, qui appelle Kouma.

```js
// Serveur Express : POST /assistant
app.post('/assistant', async (req, res) => {
  const { message } = req.body;
  const r = await fetch('https://kouma.site/api/v1/chat', {
    method: 'POST',
    headers: { Authorization: `Bearer ${process.env.KOUMA_API_KEY}`, 'Content-Type': 'application/json' },
    body: JSON.stringify({ message, conversation_id: `user-${req.user.id}` }),
  });
  res.status(r.status).json(await r.json());
});
```

### Bonnes pratiques

- **Ne jamais exposer la clé.** Passez par votre serveur, stockez-la dans une variable d'environnement ou un gestionnaire de secrets, et changez-la si elle a pu fuiter (révoquez, créez-en une nouvelle).
- **Une clé par application** : vous savez qui consomme quoi, et vous révoquez sans tout casser.
- **Reprenez en cas d'échec** : sur une erreur 429 ou 5xx, attendez et réessayez avec un délai croissant (1 s, 2 s, 4 s), sans boucler indéfiniment.
- **Limitez les messages de vos utilisateurs** (longueur, fréquence) pour protéger votre quota.
- **Affichez les sources** quand elles sont présentes : elles rassurent l'utilisateur.
- **Traitez `grounded: false` et `handoff: true`** : une bonne interface sait dire « je ne sais pas » et passer à une personne.
- **Surveillez** la consommation avec `GET /api/v1/usage`, par exemple pour alerter votre équipe à 80 % du quota.
- **Journalisez** le statut et le code d'erreur, jamais la clé.

## 3. L'API publique du widget

Cette API sert le widget officiel. Elle permet de **construire votre propre interface de chat** (application native, composant web sur mesure) sans clé secrète : l'assistant est identifié par sa **clé publique**.

> **Important :** cette API évolue avec le widget. Pour un site web, préférez le widget (une ligne de code). Pour un usage serveur, préférez l'API développeur. N'utilisez l'API publique que pour une interface de chat que vous dessinez vous-même.

### Principes

- Base : `https://kouma.site/api/v1/widget/{cle_publique}`.
- Pas de cookie ni de session : un **identifiant de visiteur** (`visitor_id`) que vous générez, et un **jeton de conversation** (`token`) renvoyé au démarrage, suffisent.
- L'en-tête `Origin` de la requête est comparé à la liste des **sites autorisés** de l'assistant (voir plus haut). Les réponses acceptent les appels depuis le navigateur (CORS).
- Limites : **30 requêtes par minute et par adresse IP**, **300 par minute et par assistant**.
- Une clé inconnue ou un assistant en pause renvoie `404` (`assistant_not_found`) ; une origine non autorisée renvoie `403` (`origin_not_allowed`).

### Les routes

| Méthode | Route | Rôle |
|---|---|---|
| GET | `/config` | Réglages publics de l'assistant (titre, couleur, accueil, langues, voix, mention « Propulsé par ») |
| POST | `/conversations` | Démarre ou reprend une conversation |
| POST | `/conversations/{token}/messages` | Envoie un message et reçoit la réponse |
| GET | `/conversations/{token}/messages?after={id}` | Récupère les nouveaux messages (réponses d'un conseiller humain) |
| POST | `/conversations/{token}/voice` | Envoie un message vocal |
| POST | `/conversations/{token}/speak` | Lit une réponse à voix haute |
| POST | `/conversations/{token}/close` | Clôture la conversation (« Nouvelle conversation ») |
| POST | `/conversations/{token}/messages/{id}/feedback` | Pouce levé ou baissé sur une réponse |

### Démarrer une conversation

```bash
curl -X POST https://kouma.site/api/v1/widget/pk_votre_cle/conversations \
  -H "Content-Type: application/json" \
  -d '{"visitor_id": "visiteur-3f9a2c71", "name": "Awa"}'
```

| Champ | Obligatoire | Description |
|---|---|---|
| `visitor_id` | oui | 8 à 64 caractères (lettres, chiffres, tirets, tirets bas), que vous générez et conservez côté appareil |
| `name`, `email`, `phone` | non | Coordonnées facultatives, visibles dans le tableau de bord |

Réponse :

```json
{ "token": "9b1d8f3a-...", "status": "bot", "messages": [] }
```

Avec le même `visitor_id`, une conversation non clôturée est **reprise** : `messages` contient l'historique (100 derniers messages). `status` vaut `bot` (l'assistant répond), `needs_human` (le client attend une personne), `human` (une personne a pris la main) ou `closed`.

### Envoyer un message

```bash
curl -X POST https://kouma.site/api/v1/widget/pk_votre_cle/conversations/$TOKEN/messages \
  -H "Content-Type: application/json" \
  -d '{"content": "Combien coûte la livraison ?", "lang": "fr"}'
```

`content` : 2 000 caractères au plus. `lang` (facultatif) : l'une des langues de l'assistant.

```json
{
  "message": {
    "id": 412,
    "role": "assistant",
    "content": "La livraison coûte 2 000 FCFA à Bobo-Dioulasso.",
    "at": "2026-10-01T10:22:31+00:00",
    "suggestions": ["Commander"],
    "sources": [{ "title": "Livraison", "url": "https://boutique.example/livraison" }]
  },
  "status": "bot"
}
```

`message` vaut `null` quand l'assistant ne répond pas (par exemple parce qu'une personne a pris la main).

### Recevoir les réponses d'un conseiller

Quand `status` n'est plus `bot`, interrogez régulièrement (toutes les 3 à 5 secondes) :

```
GET /conversations/{token}/messages?after=412
```

La réponse contient `status` et la liste des `messages` d'assistant ou de conseiller arrivés après l'identifiant donné (50 au plus). Le rôle (`role`) vaut `assistant` ou `agent`.

### Voix

- `POST /conversations/{token}/voice` : envoi **multipart** d'un fichier `audio` (8 Mo et 120 secondes au plus), avec `lang` en option. La réponse contient `transcript` (ce qui a été compris), `message`, `status` et, si l'assistant répond en audio, `audio` (une adresse `data:` lisible directement). Un refus (offre sans voix, quota, audio trop long, audio inaudible) renvoie un `422` avec un `error` et un `message` à afficher.
- `POST /conversations/{token}/speak` : corps `{"message_id": 412}`, renvoie `{"audio": "data:..."}` pour lire un message à voix haute.

### Clôturer et noter

- `POST /conversations/{token}/close` : la conversation en cours est close ; la suivante repart de zéro.
- `POST /conversations/{token}/messages/{id}/feedback` : `{"value": "up"}`, `{"value": "down"}` ou `{"value": null}` pour annuler. Les pouces servent à repérer les réponses à corriger dans le tableau de bord.

## 4. WhatsApp

Il n'y a **rien à développer** : le canal WhatsApp est activé par notre équipe sur le numéro de l'entreprise, et les messages des clients arrivent directement à l'assistant, qui répond avec les mêmes connaissances et la même consigne que sur le site. Les conversations apparaissent dans le tableau de bord.

Pour écrire à un client **après 24 heures** sans réponse de sa part, WhatsApp impose un **modèle de message approuvé**. Kouma fournit une bibliothèque de modèles prêts à l'emploi (suivi de commande, rappel de rendez-vous, devis, paiement reçu, promotions) que l'entreprise ajoute en un clic depuis **Canaux, Modèles**.

Si vous développez un outil métier qui doit déclencher ces messages (par exemple « commande expédiée »), parlez-en à notre équipe : l'envoi automatique à partir d'un événement fait partie des évolutions prévues.

## 5. Sécurité et conformité

- **HTTPS uniquement.** Toutes les adresses de l'API sont en HTTPS.
- **Clés secrètes** : stockées côté serveur, révocables, une par application. Nous ne les stockons qu'en forme chiffrée irréversible et ne les affichons qu'une fois.
- **Données personnelles** : n'envoyez à l'assistant que ce qui est nécessaire. Évitez les numéros de carte, les mots de passe et les données de santé. Informez vos utilisateurs que leurs messages sont traités par un assistant et enregistrés dans l'espace de l'entreprise.
- **Isolation** : chaque entreprise a son espace ; une clé ne donne accès qu'à l'assistant pour lequel elle a été créée.
- **Contenu non fiable** : les réponses sont du texte. Échappez-les avant de les afficher dans une page web, comme tout contenu externe.

## 6. Dépannage

| Symptôme | Cause probable | Solution |
|---|---|---|
| `401 missing_key` | En-tête `Authorization` absent ou mal écrit | Vérifiez `Authorization: Bearer kma_...` |
| `401 invalid_key` | Clé erronée, copiée avec un espace, ou révoquée | Recopiez la clé, ou créez-en une nouvelle |
| `403 plan_required` | L'offre n'inclut pas l'API | Passez à l'offre qui l'inclut |
| `422` sur `/chat` | `message` vide ou trop long, `conversation_id` invalide | Respectez les formats du tableau des champs |
| `429 quota_exceeded` | Quota mensuel atteint | Attendez la date `resets_at` ou changez d'offre |
| `429` sans code | Trop de requêtes | Respectez `Retry-After` et lissez vos appels |
| Réponses lentes | L'IA rédige la réponse | Prévoyez un timeout de 30 s, affichez un indicateur d'attente |
| `grounded: false` fréquent | L'information manque dans les sources | Ajoutez des sources, ou répondez aux questions sans réponse du tableau de bord |
| Le widget n'apparaît pas | Script mal placé, domaine non autorisé, assistant en pause | Vérifiez l'emplacement, la liste « Sites autorisés » et l'option « Assistant actif » |
| `403 origin_not_allowed` | L'origine de la page n'est pas dans la liste | Ajoutez `https://votre-domaine` (avec le schéma) |
| `404 assistant_not_found` | Clé publique erronée ou assistant en pause | Vérifiez la clé, activez l'assistant |

## Nous contacter

> **On s'occupe de tout :** besoin d'un coup de main pour intégrer l'assistant, ou envie de déléguer ? Notre équipe installe le widget, configure l'assistant, branche WhatsApp et forme vos équipes. Vous n'avez qu'à valider.

- **Le chat web de Kouma** : sur le site, la bulle en bas à droite répond tout de suite, à toute heure.
- **Par e-mail** et **sur WhatsApp** : les coordonnées sont affichées en bas de la page d'aide du site.

Quand vous nous écrivez pour un problème d'API, joignez : l'adresse appelée, le statut HTTP, le corps de l'erreur (sans la clé), l'heure de l'appel et, si possible, le `conversation_id`. Nous retrouvons ainsi votre appel en quelques instants.

Bonne intégration, et merci de votre confiance.
