# Catalogues WhatsApp Business : les faire entrer dans la base de connaissances

Question posée : des clients utilisent déjà un numéro WhatsApp Business et ont un catalogue de produits. Comment l'assistant peut-il le connaître (noms, prix, disponibilité, descriptions) ?

**État.** L'étape 1 (fichiers Excel et CSV compris comme des catalogues) est **développée et testée**. L'étape 2 (lecture directe du catalogue Meta par l'API) reste à l'étude : elle dépend de points à vérifier chez Meta, décrits plus bas. La documentation développeur de Meta n'a pas pu être relue en détail pendant l'étude : les points marqués « à vérifier » ne doivent pas être tenus pour acquis.

## 1. Étape 1 (faite) : tableaux Excel et CSV

Le client envoie son catalogue, sa carte ou sa liste de prix dans « Documents » (`.xlsx`, `.csv`, `.tsv`). Le code est dans `app/Ingestion/Extractors/` (`XlsxReader`, `TableReader`, `FileExtractor`) et `app/Ingestion/Catalog/` (`Money`, `CatalogProduct`, `CatalogFormatter`).

**Ce que la lecture comprend toute seule**

| Sujet | Comportement |
|---|---|
| Lignes de titre | L'en-tête est la première ligne, parmi les douze premières, où au moins deux cellules sont des noms de colonnes connus. |
| Colonnes | Reconnues par mot-clé, en français et en anglais : nom / produit / article / désignation / title, prix / tarif / price, disponibilité / stock / quantité / availability, catégorie / famille / type, description, marque, lien, référence / sku / id, devise, promo / sale price. Les colonnes en plus (taille, couleur...) sont gardées avec leur nom. |
| Export Meta | Les colonnes du Commerce Manager (`id, title, description, availability, condition, price, link, image_link, brand, sale_price`) sont comprises telles quelles ; `image_link` et `condition` sont ignorées. |
| Prix | « 18 000 », « 18.000 », « 18,000 », « 18000 F CFA », « XOF 18000 », « à partir de 5000 », « 5000 - 8000 », « 12,50 € », « 2 000 la pièce », « 1500/kg », « Sur devis » : tous deviennent une phrase lisible (« 18 000 FCFA »). La devise écrite dans la cellule prime, puis la colonne « devise », puis la devise de l'espace du client. |
| Disponibilité | « en stock », « oui », « 12 », « disponible » deviennent « En stock » ; « rupture », « 0 », « épuisé », « pas en stock » deviennent « Rupture de stock » ; « sur commande », « available for order » deviennent « Sur commande ». Un texte plus précis (« Disponible sous 48h ») est gardé tel quel. |
| Catégories | Colonne de catégorie, sinon titres de section écrits en majuscules (« ROBES »), sinon le nom de la feuille Excel quand il y en a plusieurs. Chaque catégorie devient une section : son nom accompagne chaque extrait. |
| Résumé | Un paragraphe d'ensemble répond à « que vendez-vous ? » et « dans quelle fourchette de prix ? ». |
| Encodage et séparateur | UTF-8 ou Windows-1252 (CSV d'un Excel français), BOM, virgule, point-virgule, tabulation ou barre ; cellules entre guillemets avec retours à la ligne. |
| Excel | Feuilles visibles seulement ; cellules vides entre deux colonnes, textes partagés ou en ligne, nombres, formules (dernière valeur calculée). Les anciens `.xls` sont refusés avec la marche à suivre (enregistrer en `.xlsx` ou CSV). |

**Ce que la lecture ne fait jamais**

- Lire une colonne de **prix d'achat, de marge ou de fournisseur** : elle est ignorée et le client en est averti (« Colonnes non lues par prudence »). Un client ne doit pas voir un prix d'achat.
- Lire les lignes d'exemple du modèle (« (à supprimer) »). Un fichier qui ne contient que ces exemples est refusé avec un message clair.
- Inventer un prix : un produit sans prix reste connu, mais l'assistant ne le chiffre pas (règle « n'invente jamais un prix »).

**Ce que le client voit** après l'envoi, sur la ligne de la source : « 37 produit(s) lu(s) » et, en encadré jaune, ce qui mérite son attention (produits sans prix, noms en double, lignes sans nom, colonnes ignorées, coupe à 5000 produits).

**Un modèle à télécharger** : `GET /bots/{assistant}/modele-catalogue` donne un CSV (séparateur point-virgule, BOM UTF-8) qui s'ouvre correctement dans Excel en français.

**Limites connues**

- 5000 produits par fichier, 20 Mo, 30 Mo décompressés par feuille Excel.
- Les dates Excel apparaissent comme des nombres (sans objet pour un catalogue).
- Un titre de section qui n'est pas en majuscules est lu comme un produit sans prix (visible dans le résumé).
- La mise à jour n'est pas automatique : le client renvoie le fichier (ou « Relancer la lecture » ne suffit pas, le fichier reste l'ancien). C'est ce que l'étape 2 remplace.
- Tests : `tests/Feature/CatalogTest.php`.

## 1 bis. Les produits d'un site web

Un site de boutique n'a pas besoin d'un tableau : `ProductScanner` lit les produits directement sur les pages (voir D34 à D36 de `docs/ARCHITECTURE.md`). Chaque produit devient une fiche (nom, catégorie, prix, disponibilité, description, photo, lien de commande) et une ligne de `catalog_items`, avec une référence `P12` que l'assistant utilise pour joindre la photo. Les colonnes d'achat ne concernent que les tableaux ; un site n'en montre pas. Un prix sans monnaie (« 5000 ») n'est pas lu comme un prix.

## 2. Étape 2 (à l'étude) : lire le catalogue Meta par l'API

Un catalogue WhatsApp Business vit dans le **Commerce Manager** de Meta (le catalogue créé dans l'application WhatsApp Business y est rattaché au compte Meta Business du client) et se lit avec l'API Graph. La lecture suivrait, d'après la documentation générale :

1. Le client a déjà relié son numéro à Kouma par l'intégration WhatsApp Meta (jeton d'accès et identifiant du compte WhatsApp Business `waba_id`, déjà chiffrés dans `channels.credentials`).
2. Kouma demande la liste des catalogues du compte (`GET /{waba_id}/product_catalogs`) puis les produits (`GET /{catalog_id}/products?fields=name,description,price,currency,availability,url,retailer_id,category,image_url&limit=100`, avec la pagination par curseur).
3. Chaque produit devient un `CatalogProduct`, mis en forme par le **même `CatalogFormatter`** que l'étape 1 : même texte, même résumé, mêmes sections. Un seul `Source` de type catalogue, remplacé à chaque relecture.
4. Relecture planifiée (`platform:resync-due`, déjà horaire) pour suivre les prix et les stocks.

Avantages : le client n'a rien à exporter, les prix et les stocks se mettent à jour tout seuls, et la valeur vient du catalogue (jamais d'une supposition du modèle).

### À vérifier avant de coder

- **Permission du jeton** : la lecture du catalogue demande, d'après la documentation générale de Meta, une permission de gestion de catalogue (`catalog_management`) en plus de celles de la messagerie WhatsApp. Cela peut entraîner une revue d'application supplémentaire côté Meta. Statut à confirmer.
- **Numéro déjà dans l'application WhatsApp Business** : un numéro utilisé dans l'application mobile ne peut pas, en règle générale, être utilisé en même temps avec l'API Cloud, sauf mode de cohabitation proposé par Meta. À confirmer avant de promettre « gardez votre numéro et votre catalogue » à un client, car c'est le cas le plus fréquent chez les petites entreprises.
- **Lien catalogue / compte** : le catalogue doit être associé au compte WhatsApp Business et visible par le jeton ; sinon la liste est vide.
- **Format du prix** : Meta renvoie le prix sous forme de texte avec devise ; `Money::parse` le comprend déjà (« 18000 XOF »).
- **Limites de débit** de l'API Graph pour des catalogues de plusieurs centaines de produits.

Avant l'étape 2 : un essai réel avec le compte Meta d'un client pilote, pour lever ces points.

## 3. Piste écartée : lire la page publique du catalogue

La page publique d'un catalogue (lien `wa.me/c/…`) est destinée aux clients dans l'application. La « lire » comme un site web (robot d'exploration) est fragile (page dynamique, structure qui change) et contraire aux conditions d'utilisation de WhatsApp. Elle n'est pas retenue.

## 4. Suite possible

- **Étape 3** : relecture planifiée du catalogue Meta, et alerte si le jeton n'a plus la permission.
- **Plus tard** : envoi d'une fiche produit dans la conversation (messages de catalogue WhatsApp) et prise de commande dans le fil, déjà couverte côté demandes par `LeadService::capture`.
- **Tableur partagé** : lire une feuille Google Sheets publiée (lien d'export CSV) pour une mise à jour sans renvoi du fichier ; passe par `SafeUrl`, à décider avec un client pilote.
