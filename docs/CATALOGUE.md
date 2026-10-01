# Catalogues WhatsApp Business : les faire entrer dans la base de connaissances

Étude, pas encore développée. Question posée : des clients utilisent déjà un numéro WhatsApp Business et ont un catalogue de produits. Comment l'assistant peut-il le connaître (noms, prix, disponibilité, descriptions) ?

**Niveau de certitude.** Ce document sépare ce qui marche déjà dans Kouma, ce que fait l'interface de Meta d'après sa documentation générale, et ce qui reste à vérifier avant de coder. La documentation développeur de Meta n'a pas pu être relue en détail pendant cette étude : les points marqués « à vérifier » ne doivent pas être tenus pour acquis.

## 1. Ce qui marche aujourd'hui, sans développement

| Piste | Comment | Limite |
|---|---|---|
| Fichier CSV | « Ajouter un document » accepte déjà `.csv` ; `FileExtractor` écrit une ligne lisible par article (« Colonne : valeur \| Colonne : valeur »). Le client prépare un tableau : nom, prix, description, disponibilité. | Le client doit produire le fichier ; pas de mise à jour automatique ; le format Excel (`.xlsx`) n'est pas accepté, seulement CSV. |
| Photos du catalogue | « Ajouter une photo » : le modèle lit une capture d'écran du catalogue (nom et prix visibles). | Coût de lecture d'image, peu fiable sur des listes longues. |
| Texte collé | « Ajouter un texte » ou une « question-réponse » par produit phare. | Manuel, vite périmé. |

Un client qui a vingt produits s'en sort déjà avec un CSV ou un texte. Le besoin d'une vraie synchronisation commence autour de plusieurs dizaines de produits dont les prix ou les stocks bougent.

## 2. Piste recommandée : lire le catalogue Meta par l'API

Un catalogue WhatsApp Business vit dans le **Commerce Manager** de Meta (le catalogue créé dans l'application WhatsApp Business y est rattaché au compte Meta Business du client) et se lit avec l'API Graph. La lecture suit, d'après la documentation générale :

1. Le client a déjà relié son numéro à Kouma par l'intégration WhatsApp Meta (jeton d'accès + identifiant du compte WhatsApp Business `waba_id`, déjà chiffrés dans `channels.credentials`).
2. Kouma demande la liste des catalogues du compte (`GET /{waba_id}/product_catalogs`) puis les produits (`GET /{catalog_id}/products?fields=name,description,price,currency,availability,url,retailer_id,category,image_url&limit=100`, avec la pagination par curseur).
3. Chaque produit devient un extrait de connaissance, par exemple : « Robe wax Awa. Prix : 18 000 FCFA. En stock. Description : … ». Un seul `Source` de type catalogue, remplacé à chaque relecture (comme le fait déjà `LandingBot` pour ses sources : une source inchangée n'est pas relue).
4. Relecture planifiée (`platform:resync-due`, déjà horaire) pour suivre les prix et les stocks.

Avantages : données structurées (le prix vient du catalogue, jamais d'une supposition du modèle, donc cohérent avec la règle « n'invente jamais un prix »), mise à jour automatique, et le client n'a rien à exporter.

### À vérifier avant de coder

- **Permission du jeton** : la lecture du catalogue demande, d'après la documentation générale de Meta, une permission de gestion de catalogue (`catalog_management`) en plus de celles de la messagerie WhatsApp. Cela peut entraîner une revue d'application supplémentaire côté Meta. Statut à confirmer.
- **Numéro déjà dans l'application WhatsApp Business** : un numéro utilisé dans l'application mobile ne peut pas, en règle générale, être utilisé en même temps avec l'API Cloud, sauf mode de cohabitation proposé par Meta. À confirmer avant de promettre « gardez votre numéro et votre catalogue » à un client, car c'est le cas le plus fréquent chez les petites entreprises.
- **Lien catalogue / compte** : le catalogue doit être associé au compte WhatsApp Business et visible par le jeton ; sinon la liste est vide.
- **Format du prix** : Meta renvoie le prix sous forme de texte avec devise ; prévoir un nettoyage (milliers, devise FCFA/XOF).
- **Limites de débit** de l'API Graph pour des catalogues de plusieurs centaines de produits.

## 3. Piste écartée : lire la page publique du catalogue

La page publique d'un catalogue (lien `wa.me/c/…`) est destinée aux clients dans l'application. La « lire » comme un site web (robot d'exploration) est fragile (page dynamique, structure qui change) et contraire aux conditions d'utilisation de WhatsApp. Elle n'est pas retenue. `SafeUrl` et le crawler existants ne la traiteraient pas de toute façon.

## 4. Découpage proposé si on développe

1. **Étape 1 (petite)** : un modèle de CSV « catalogue » téléchargeable depuis l'écran des sources (colonnes nom, prix, disponibilité, description), et la lecture des fichiers Excel `.xlsx`, que beaucoup de commerçants utilisent à la place du CSV.
2. **Étape 2** : bouton « Importer mon catalogue Meta » dans le canal WhatsApp Meta : lecture des produits, source de type `catalog`, résumé affiché (nombre de produits importés, prix manquants).
3. **Étape 3** : relecture planifiée et alerte si le jeton n'a plus la permission.
4. **Plus tard** : envoi de fiches produit dans la conversation (messages de catalogue WhatsApp) et prise de commande dans le fil, déjà couverte côté demandes par `LeadService::capture`.

Avant l'étape 2 : un essai réel avec le compte Meta d'un client pilote, pour lever les points « à vérifier ».
