<?php

namespace App\Ingestion\Extractors;

use App\Ingestion\Catalog\CatalogFormatter;
use App\Ingestion\Catalog\CatalogProduct;
use App\Ingestion\Catalog\Money;
use App\Ingestion\ExtractedDocument;
use App\Ingestion\IngestionException;
use App\Support\Currency;
use App\Support\Text;

/**
 * Comprend un tableau (CSV ou feuilles Excel) : liste de prix, carte de restaurant, catalogue de boutique, export du
 * Commerce Manager de Meta. Il repère la ligne d'en-tête (même sous un titre), reconnaît les colonnes par leur nom
 * (nom, prix, disponibilité, catégorie, description... en français comme en anglais), lit les prix et les stocks tels que
 * les commerçants les écrivent, range les produits par catégorie, et signale ce qui pose problème. Un tableau qui n'est
 * pas un catalogue reste lu ligne par ligne, « Colonne : valeur | ... ».
 *
 * Les colonnes d'achat, de marge et de fournisseur ne sont jamais lues : un client ne doit pas voir un prix d'achat.
 */
class TableReader
{
    public const MAX_PRODUCTS = 5000;

    /** Colonnes lues par leur mot-clé (premier groupe qui correspond ; l'ordre compte : « prix promo » avant « prix »). */
    private const FIELDS = [
        'ignore' => ['image', 'images', 'photo', 'photos', 'img', 'gtin', 'mpn', 'ean', 'upc', 'condition', 'google', 'facebook', 'fb', 'group', 'shipping', 'effective', 'achat', 'fournisseur', 'supplier', 'marge', 'margin', 'benefice'],
        'sale' => ['promo', 'promotion', 'solde', 'reduit', 'reduction', 'sale', 'remise', 'discount'],
        'currency' => ['devise', 'monnaie', 'currency'],
        'price' => ['prix', 'tarif', 'tarifs', 'pu', 'montant', 'price', 'cout', 'cost'],
        'availability' => ['disponibilite', 'dispo', 'disponible', 'stock', 'quantite', 'qte', 'qty', 'quantity', 'availability', 'inventaire'],
        'category' => ['categorie', 'categories', 'famille', 'rayon', 'collection', 'category', 'gamme', 'type', 'rubrique'],
        'description' => ['description', 'descriptif', 'details', 'detail', 'desc', 'caracteristiques', 'infos', 'info', 'presentation', 'commentaire'],
        'brand' => ['marque', 'brand', 'fabricant'],
        'link' => ['lien', 'url', 'link', 'site'],
        'ref' => ['ref', 'reference', 'sku', 'code', 'id', 'retailerid'],
        'name' => ['nom', 'produit', 'produits', 'article', 'designation', 'libelle', 'titre', 'title', 'name', 'item', 'service', 'prestation', 'plat'],
    ];

    /** Mots qui signalent une colonne confidentielle : on le dit au client. */
    private const PRIVATE = ['achat', 'fournisseur', 'supplier', 'marge', 'margin', 'benefice'];

    public function __construct(private readonly CatalogFormatter $formatter) {}

    /**
     * @param  list<array{name:string, rows:list<list<string>>}>  $sheets
     * @param  ?string  $currency  devise de l'espace du client, utilisée quand un prix n'en porte pas
     */
    public function read(array $sheets, string $title, ?string $currency = null): ExtractedDocument
    {
        $currency = Currency::isValid($currency) ? $currency : Currency::default();
        $many = count($sheets) > 1;
        $state = ['noPrice' => [], 'noName' => 0, 'examples' => 0, 'duplicates' => [], 'seen' => [], 'capped' => false, 'private' => [], 'generic' => false];
        $products = [];
        $generic = [];

        foreach ($sheets as $sheet) {
            $rows = $this->tidy($sheet['rows']);
            if ($rows === []) {
                continue;
            }

            $at = $this->headerIndex($rows);
            $header = $rows[$at];
            $body = array_slice($rows, $at + 1);
            [$map, $ignored] = $this->columns($header);

            foreach ($ignored as $i) {
                if (array_intersect($this->tokens($header[$i]), self::PRIVATE)) {
                    $state['private'][] = $header[$i];
                }
            }

            if (isset($map['name']) && (isset($map['price']) || isset($map['description']))) {
                $products = array_merge($products, $this->products($header, $map, $ignored, $body, $currency, $many ? $sheet['name'] : null, $state));
            } else {
                $state['generic'] = $state['generic'] || count($header) >= 2;
                $generic[] = $this->generic($header, $body, $many ? $sheet['name'] : null);
            }
        }

        $parts = array_filter([$this->formatter->format($products, $title), ...$generic]);
        $text = trim(implode("\n\n", $parts));

        if ($text === '' && $state['examples'] > 0) {
            throw new IngestionException("Ce fichier ne contient que les lignes d'exemple du modèle : remplacez-les par vos produits, puis renvoyez-le.");
        }

        return new ExtractedDocument($title, $text, null, [
            'products' => count($products),
            'notes' => $this->notes($products, $state),
        ]);
    }

    /* ---------- Lecture des cellules et des colonnes ---------- */

    /**
     * @param  list<list<string>>  $rows
     * @return list<list<string>> lignes nettoyées, sans les lignes entièrement vides
     */
    private function tidy(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $clean = array_map(fn ($cell) => trim(preg_replace('/\s+/u', ' ', str_replace("\u{00A0}", ' ', (string) $cell))), array_values($row));
            if (array_filter($clean, fn ($c) => $c !== '') !== []) {
                $out[] = $clean;
            }
        }

        return $out;
    }

    /** L'en-tête est la première ligne (parmi les douze premières) où au moins deux cellules sont des noms de colonnes connus. */
    private function headerIndex(array $rows): int
    {
        foreach (array_slice($rows, 0, 12, true) as $i => $row) {
            $recognized = count(array_filter($row, fn ($cell) => $this->classify($cell) !== null));
            if ($recognized >= 2) {
                return $i;
            }
        }

        return 0;
    }

    /**
     * @param  list<string>  $header
     * @return array{0: array<string,int>, 1: list<int>} champ => colonne, colonnes ignorées
     */
    private function columns(array $header): array
    {
        $map = [];
        $ignored = [];

        foreach ($header as $i => $cell) {
            $field = $this->classify($cell);
            if ($field === 'ignore') {
                $ignored[] = $i;
            } elseif ($field !== null && ! isset($map[$field])) {
                $map[$field] = $i;
            }
        }

        return [$map, $ignored];
    }

    private function classify(string $cell): ?string
    {
        $tokens = $this->tokens($cell);
        if ($tokens === [] || count($tokens) > 6) {
            return null;
        }

        foreach (self::FIELDS as $field => $words) {
            if (array_intersect($tokens, $words)) {
                return $field;
            }
        }

        return null;
    }

    /** @return list<string> */
    private function tokens(string $text): array
    {
        return preg_split('/[^a-z0-9]+/', Text::fold($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }

    /* ---------- Catalogue ---------- */

    /**
     * @param  list<string>  $header
     * @param  array<string,int>  $map
     * @param  list<int>  $ignored
     * @param  list<list<string>>  $body
     * @param  array<string,mixed>  $state
     * @return list<CatalogProduct>
     */
    private function products(array $header, array $map, array $ignored, array $body, string $currency, ?string $sheetName, array &$state): array
    {
        $used = array_values($map);
        $section = null;
        $products = [];

        foreach ($body as $row) {
            $get = fn (string $field) => isset($map[$field]) ? ($row[$map[$field]] ?? '') : '';
            $name = $get('name');
            $filled = count(array_filter($row, fn ($c) => $c !== ''));

            // Une ligne sans nom : un titre de section (« ROBES ») s'il est seul, sinon une ligne abandonnée.
            if ($name === '') {
                if ($filled === 1 && ! isset($map['category'])) {
                    $section = array_values(array_filter($row, fn ($c) => $c !== ''))[0];
                } elseif ($filled > 0) {
                    $state['noName']++;
                }

                continue;
            }

            if (str_contains(Text::fold($name), 'a supprimer')) {
                $state['examples']++;

                continue;
            }

            if ($filled === 1 && ! isset($map['category']) && $this->isHeading($name)) {
                $section = $name;

                continue;
            }

            if (count($products) >= self::MAX_PRODUCTS) {
                $state['capped'] = true;
                break;
            }

            $rowCurrency = $get('currency') !== '' ? Money::currencyFrom($get('currency')) : null;
            $code = $rowCurrency ?: $currency;
            $price = Money::parse($get('price'), $code);
            $sale = Money::parse($get('sale'), $code);

            $extras = [];
            foreach ($header as $i => $label) {
                if (in_array($i, $used, true) || in_array($i, $ignored, true) || $label === '' || ($row[$i] ?? '') === '') {
                    continue;
                }
                $extras[mb_strtoupper(mb_substr($label, 0, 1)).mb_substr($label, 1)] = Text::limit($row[$i], 120);
                if (count($extras) >= 8) {
                    break;
                }
            }

            $key = Text::fold($name);
            if (isset($state['seen'][$key])) {
                $state['duplicates'][$name] = true;
            }
            $state['seen'][$key] = true;

            if ($price['display'] === null && $sale['display'] === null) {
                $state['noPrice'][] = $name;
            }

            $products[] = new CatalogProduct(
                name: $name,
                category: ($get('category') ?: $section ?: $sheetName) ?: null,
                price: $price['display'],
                salePrice: $sale['display'],
                amount: $price['amount'] ?? $sale['amount'],
                currency: $price['amount'] !== null ? $price['currency'] : $sale['currency'],
                availability: Money::availability($get('availability')),
                description: $get('description') ?: null,
                reference: $get('ref') ?: null,
                brand: $get('brand') ?: null,
                link: $get('link') ?: null,
                extras: $extras,
            );
        }

        return $products;
    }

    /** Un titre de section est écrit en majuscules (« ROBES ») : un produit sans détail ne l'est pas. */
    private function isHeading(string $name): bool
    {
        return mb_strtoupper($name) === $name && preg_match('/\p{L}{3,}/u', $name) === 1;
    }

    /* ---------- Tableau quelconque ---------- */

    /**
     * @param  list<string>  $header
     * @param  list<list<string>>  $body
     */
    private function generic(array $header, array $body, ?string $sheetName): string
    {
        $lines = [];

        if ($body === []) {
            $lines[] = implode(' | ', array_filter($header, fn ($c) => $c !== ''));
        }

        foreach (array_slice($body, 0, self::MAX_PRODUCTS) as $row) {
            $pairs = [];
            foreach ($row as $i => $value) {
                if ($value !== '') {
                    $label = $header[$i] ?? '';
                    $pairs[] = $label !== '' ? "{$label} : {$value}" : $value;
                }
            }
            if ($pairs) {
                $lines[] = implode(' | ', $pairs);
            }
        }

        $text = implode("\n\n", $lines);

        return $sheetName ? "## {$sheetName}\n\n{$text}" : $text;
    }

    /* ---------- Constat montré au client ---------- */

    /**
     * @param  list<CatalogProduct>  $products
     * @param  array<string,mixed>  $state
     * @return list<string>
     */
    private function notes(array $products, array $state): array
    {
        $notes = [];

        if ($state['noPrice'] !== []) {
            $n = count($state['noPrice']);
            $notes[] = $n.' '.($n > 1 ? 'produits sans prix' : 'produit sans prix').' (l\'assistant ne les chiffrera pas) : '.$this->sample($state['noPrice']).'.';
        }
        if ($state['examples'] > 0) {
            $notes[] = $state['examples'].' '.($state['examples'] > 1 ? 'lignes d\'exemple ignorées' : 'ligne d\'exemple ignorée').'.';
        }
        if ($state['noName'] > 0) {
            $notes[] = $state['noName'].' '.($state['noName'] > 1 ? 'lignes sans nom ignorées' : 'ligne sans nom ignorée').'.';
        }
        if ($state['duplicates'] !== []) {
            $notes[] = 'Noms en double (les deux lignes sont gardées) : '.$this->sample(array_keys($state['duplicates'])).'.';
        }
        if ($state['capped']) {
            $notes[] = 'Seuls les '.self::MAX_PRODUCTS.' premiers produits ont été lus.';
        }
        if ($state['private'] !== []) {
            $notes[] = 'Colonnes non lues par prudence : '.$this->sample(array_values(array_unique($state['private']))).'.';
        }
        if ($products === [] && $state['generic']) {
            $notes[] = 'Aucune colonne « nom » avec « prix » ou « description » reconnue : le tableau est lu ligne par ligne.';
        }

        return $notes;
    }

    /** @param list<string> $names */
    private function sample(array $names): string
    {
        $shown = array_map(fn ($n) => Text::limit($n, 40), array_slice($names, 0, 3));

        return implode(', ', $shown).(count($names) > 3 ? ' et '.(count($names) - 3).' autre(s)' : '');
    }
}
