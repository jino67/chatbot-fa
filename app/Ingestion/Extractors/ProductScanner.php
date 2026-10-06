<?php

namespace App\Ingestion\Extractors;

use App\Ingestion\Catalog\CatalogProduct;
use App\Ingestion\Catalog\Money;
use App\Ingestion\Catalog\PriceText;
use App\Ingestion\Crawler\Url;
use App\Support\Text;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;
use DOMXPath;
use SplObjectStorage;

/**
 * Repère les produits d'une page web, pour que leur nom reste collé à leur prix.
 *
 * Trois sources, de la plus fiable à la plus souple : les données structurées (JSON-LD « Product »), les balises
 * Open Graph, puis la forme de la page : une fiche produit est un titre (h1) avec UN seul prix autour de lui ; une
 * liste est une suite de blocs frères qui contiennent chacun un titre et UN seul prix. Une fiche produit se débarrasse
 * de ses « produits de la même gamme » (leurs prix se mélangeraient à ceux du produit), une liste ou la page d'accueil
 * remplace chaque bloc par une ligne « Nom : prix, disponibilité ».
 */
final class ProductScanner implements PageTransformer
{
    private const BUTTONS = '/^(commander|commande|voir|ajouter|acheter|buy|add to cart|d[ée]tails?|en savoir plus|lire la suite|choisir|select)\b/iu';

    private const ACTION_LINK = '/(panier|cart|basket|checkout|favoris|wishlist|ajouter|add-to|compare|login|connexion|wa\.me|whatsapp)/i';

    private const ORDER_LINK = '/(commander|commande|checkout|order|acheter|buy)/i';

    private const BAD_IMAGE = '/(logo|icon|sprite|avatar|flag|badge|payment|loader|placeholder|pixel|blank|spacer|\.svg(\?|$)|\/images\/(?:icon|picto))/i';

    private const OLD_PRICE_CLASS = '/(?:^|[\s_-])(?:old|was|ancien|barre|compare|strike|line-through|before)(?:$|[\s_-])/i';

    private const CATEGORY_CLASS = '/(?:^|[\s_-])(?:tag|badge|cat|categ\w*|kick\w*|collection|gamme|range)(?:$|[\s_-])/i';

    /** @var list<array<string,mixed>> */
    private array $ld = [];

    private ?string $ogType = null;

    private ?string $ogImage = null;

    private ?string $canonical = null;

    private bool $noindex = false;

    /** @var list<string> fil d'Ariane de la page, lu avant le retrait des menus */
    private array $crumbs = [];

    /** @var list<CatalogProduct> */
    private array $products = [];

    private ?CatalogProduct $main = null;

    private bool $listing = false;

    /** @var SplObjectStorage<DOMNode, array{len:int, prices:list<string>}> */
    private SplObjectStorage $info;

    public function __construct(private readonly string $currency = 'XOF')
    {
        $this->info = new SplObjectStorage;
    }

    /** @return list<CatalogProduct> tous les produits de la page, la fiche principale en premier */
    public function products(): array
    {
        return $this->products;
    }

    /** La fiche d'un produit seul, quand la page en est une. */
    public function main(): ?CatalogProduct
    {
        return $this->main;
    }

    /** Une liste de produits (catégorie, boutique, accueil) : plusieurs blocs frères avec chacun un prix. */
    public function isListing(): bool
    {
        return $this->listing;
    }

    public function canonical(): ?string
    {
        return $this->canonical;
    }

    public function noindex(): bool
    {
        return $this->noindex;
    }

    public function before(DOMDocument $dom, DOMXPath $xpath, ?string $baseUrl): void
    {
        foreach ($xpath->query('//script[@type="application/ld+json"]') as $script) {
            $data = json_decode(trim(html_entity_decode($script->textContent)), true);
            if (is_array($data)) {
                $this->collectLd($data);
            }
        }

        $this->ogType = $this->meta($xpath, 'og:type');
        $this->ogImage = $this->meta($xpath, 'og:image');
        $canonical = trim((string) $xpath->evaluate('string(//link[@rel="canonical"]/@href)'));
        $this->canonical = $canonical !== '' && $baseUrl ? Url::resolve($baseUrl, $canonical) : null;
        $this->noindex = str_contains(strtolower((string) $xpath->evaluate('string(//meta[@name="robots"]/@content)')), 'noindex');

        // Le fil d'Ariane est lu ici : les menus (<nav>) disparaissent avant `after`.
        foreach ($xpath->query('//*[@aria-label or @class]') as $element) {
            $hint = strtolower($element->getAttribute('aria-label').' '.$element->getAttribute('class'));
            if (! preg_match('/ariane|breadcrumb|(?:^|\s)fil(?:\s|$)/', $hint) || $element->getElementsByTagName('a')->length === 0) {
                continue;
            }
            foreach ($element->getElementsByTagName('a') as $a) {
                $text = trim(preg_replace('/\s+/u', ' ', (string) $a->textContent));
                if ($text !== '' && ! preg_match('/^(accueil|home)$/iu', $text)) {
                    $this->crumbs[] = $text;
                }
            }
            break;
        }
    }

    public function after(DOMDocument $dom, DOMXPath $xpath, ?string $baseUrl): void
    {
        $body = $dom->getElementsByTagName('body')->item(0);
        if (! $body instanceof DOMElement) {
            return;
        }

        // Un prix barré est l'ancien prix : il ne compte pas, il ne doit jamais être pris pour le prix du produit.
        foreach (iterator_to_array($xpath->query('//del|//s|//strike|//*[@class]')) as $node) {
            if ($node instanceof DOMElement && $node->parentNode
                && (in_array($node->nodeName, ['del', 's', 'strike'], true) || preg_match(self::OLD_PRICE_CLASS, $node->getAttribute('class')))) {
                $node->parentNode->removeChild($node);
            }
        }

        $this->analyse($body);

        $mainBlock = $this->mainBlock($xpath);
        $cards = $this->cards($xpath, $mainBlock);

        $found = [];
        if ($mainBlock) {
            $name = $this->name($mainBlock['h1']);
            if ($name !== null) {
                $main = $this->productFrom($mainBlock['block'], $name, $baseUrl, true);
                if ($main->category === null && $this->crumbs !== [] && Text::fold(end($this->crumbs)) !== Text::fold($name)) {
                    $main = new CatalogProduct(...[...$main->toArray(), 'category' => end($this->crumbs)]);
                }
                // La fiche se désigne elle-même : son adresse est celle de la page (un lien du bloc pourrait être celui d'un fil d'Ariane).
                if ($baseUrl) {
                    $main = new CatalogProduct(...[...$main->toArray(), 'link' => $baseUrl]);
                }
                $found[] = $this->main = $main;
            }
        }

        $listing = ! $this->main && count($cards) >= 2;
        $category = $listing ? $this->categoryFromTitle($xpath) : null;

        foreach ($cards as $card) {
            $product = $this->productFrom($card['element'], $card['name'], $baseUrl, false);
            if ($category !== null && $product->category === null) {
                $product = new CatalogProduct(...[...$product->toArray(), 'category' => $category]);
            }
            $found[] = $product;

            if ($this->main) {
                continue; // retiré plus bas, avec sa section
            }
            $this->replaceWithLine($dom, $card['element'], $product);
        }

        // Sur une fiche produit, les « produits de la même gamme » sont retirés : leurs prix ne sont pas ceux du produit.
        if ($this->main) {
            $this->removeRelated($cards, $mainBlock['block'] ?? null);
        }

        $this->listing = $listing;
        $this->products = $this->unique($this->withStructuredData($found));

        if (! $this->main && $this->ld !== [] && count($this->ld) === 1 && ! $listing && $this->products !== []) {
            $this->main = $this->products[0];
        }
    }

    /* ---------- Données structurées ---------- */

    /** @param array<mixed> $node */
    private function collectLd(array $node): void
    {
        if (array_is_list($node)) {
            foreach ($node as $child) {
                if (is_array($child)) {
                    $this->collectLd($child);
                }
            }

            return;
        }

        if (isset($node['@graph']) && is_array($node['@graph'])) {
            $this->collectLd($node['@graph']);
        }

        $types = array_map('strtolower', (array) ($node['@type'] ?? []));
        if (in_array('product', $types, true)) {
            $this->ld[] = $node;
        } elseif (in_array('itemlist', $types, true) && isset($node['itemListElement']) && is_array($node['itemListElement'])) {
            foreach ($node['itemListElement'] as $element) {
                if (is_array($element)) {
                    $this->collectLd(isset($element['item']) && is_array($element['item']) ? $element['item'] : $element);
                }
            }
        }
    }

    /** @return list<CatalogProduct> */
    private function structured(): array
    {
        $out = [];
        foreach ($this->ld as $node) {
            $name = $this->plain((string) ($node['name'] ?? ''));
            if ($name === '') {
                continue;
            }

            $offer = $node['offers'] ?? [];
            $offers = is_array($offer) && ! array_is_list($offer) ? [$offer] : (array) $offer;
            $offer = collect($offers)->first(fn ($o) => is_array($o) && (isset($o['price']) || isset($o['lowPrice']))) ?? (is_array($offers[0] ?? null) ? $offers[0] : []);

            $price = $offer['price'] ?? $offer['lowPrice'] ?? null;
            $currency = (string) ($offer['priceCurrency'] ?? $this->currency);
            $money = $price !== null && $price !== '' ? Money::parse(trim($price.' '.$currency), $this->currency) : ['display' => null, 'amount' => null, 'currency' => null];

            $images = $node['image'] ?? null;
            $images = is_array($images) && ! array_is_list($images) ? [$images] : (array) $images;
            $image = collect($images)->map(fn ($i) => is_array($i) ? ($i['url'] ?? $i['contentUrl'] ?? null) : $i)->first(fn ($i) => is_string($i) && $i !== '');

            $brand = $node['brand'] ?? null;
            $rating = $node['aggregateRating']['ratingValue'] ?? null;

            $out[] = new CatalogProduct(
                name: $name,
                category: isset($node['category']) && is_string($node['category']) ? $this->plain($node['category']) : null,
                price: $money['display'],
                amount: $money['amount'],
                currency: $money['currency'],
                availability: $this->schemaAvailability((string) ($offer['availability'] ?? '')),
                description: ($d = $this->plain((string) ($node['description'] ?? ''))) !== '' ? Text::limit($d, 500) : null,
                reference: isset($node['sku']) ? (string) $node['sku'] : null,
                brand: is_array($brand) ? ($brand['name'] ?? null) : (is_string($brand) ? $brand : null),
                link: isset($offer['url']) && is_string($offer['url']) ? $offer['url'] : (isset($node['url']) && is_string($node['url']) ? $node['url'] : null),
                image: $image,
                rating: is_numeric($rating) ? (float) $rating : null,
            );
        }

        return $out;
    }

    private function schemaAvailability(string $value): ?string
    {
        $value = strtolower(basename(str_replace('\\', '/', $value)));

        return match (true) {
            str_contains($value, 'instock') || str_contains($value, 'limitedavailability') => 'En stock',
            str_contains($value, 'outofstock') || str_contains($value, 'soldout') || str_contains($value, 'discontinued') => 'Rupture de stock',
            str_contains($value, 'preorder') || str_contains($value, 'backorder') => 'Sur commande',
            default => null,
        };
    }

    /**
     * Les données structurées complètent ce que la page montre (et l'inverse) : le même produit n'apparaît qu'une fois.
     *
     * @param  list<CatalogProduct>  $found
     * @return list<CatalogProduct>
     */
    private function withStructuredData(array $found): array
    {
        $structured = $this->structured();
        if ($structured === []) {
            if ($this->ogType === 'product' && $this->main && $this->main->image === null && $this->ogImage) {
                $found[0] = $this->main = new CatalogProduct(...[...$this->main->toArray(), 'image' => $this->ogImage]);
            }

            return $found;
        }

        $merged = $this->unique([...$structured, ...$found]);

        if ($this->main) {
            foreach ($merged as $product) {
                if ($this->same($product, $this->main)) {
                    $this->main = $product;
                    break;
                }
            }
        }

        return $merged;
    }

    /* ---------- Analyse de la page ---------- */

    /** Taille du texte et prix distincts de chaque élément, calculés une seule fois de bas en haut. */
    private function analyse(DOMNode $node): array
    {
        if ($node instanceof DOMText) {
            return [mb_strlen(trim($node->nodeValue)), []];
        }
        if (! $node instanceof DOMElement) {
            return [0, []];
        }

        $length = 0;
        $union = [];
        foreach ($node->childNodes as $child) {
            [$l, $prices] = $this->analyse($child);
            $length += $l;
            foreach ($prices as $price) {
                $union[$price] = true;
            }
        }

        // Un prix peut être coupé en plusieurs éléments (« 5 000 » puis « XOF ») : on relit le texte des petits blocs.
        $prices = $length <= 900 ? PriceText::amounts($this->text($node)) : array_keys($union);
        $this->info[$node] = ['len' => $length, 'prices' => $prices];

        return [$length, $prices];
    }

    /**
     * Le texte d'un élément, avec une espace entre deux morceaux de texte : un site dont le HTML est écrit sans espace entre
     * les balises (« En stock<div>5 000 »), très courant, donnerait « En stock5 000 » et ne montrerait plus aucun prix.
     */
    private function text(DOMNode $node): string
    {
        $parts = [];
        $this->collect($node, $parts);

        return trim(preg_replace('/\s+/u', ' ', implode(' ', $parts)));
    }

    /** @param list<string> $parts */
    private function collect(DOMNode $node, array &$parts): void
    {
        if ($node instanceof DOMText) {
            $value = trim($node->nodeValue);
            if ($value !== '') {
                $parts[] = $value;
            }

            return;
        }

        foreach ($node->childNodes as $child) {
            $this->collect($child, $parts);
        }
    }

    /**
     * La fiche d'un produit seul : le plus grand bloc autour du titre de la page qui ne contient qu'un seul prix.
     *
     * @return array{h1:DOMElement, block:DOMElement}|null
     */
    private function mainBlock(DOMXPath $xpath): ?array
    {
        foreach ($xpath->query('//h1') as $h1) {
            if (! $h1 instanceof DOMElement || $this->insideChrome($h1)) {
                continue;
            }

            $best = null;
            $node = $h1;
            while ($node->parentNode instanceof DOMElement && ! in_array($node->parentNode->nodeName, ['body', 'html'], true)) {
                $parent = $node->parentNode;
                $count = count($this->info[$parent]['prices'] ?? []);
                if ($count > 1 || ($this->info[$parent]['len'] ?? 0) > 3500) {
                    break;
                }
                if ($count === 1) {
                    $best = $parent;
                }
                $node = $parent;
            }

            if ($best) {
                return ['h1' => $h1, 'block' => $best];
            }

            return null; // le titre de la page n'a pas un seul prix autour de lui : une liste, pas une fiche
        }

        return null;
    }

    /**
     * @param  array{h1:DOMElement, block:DOMElement}|null  $main
     * @return list<array{element:DOMElement, name:string}>
     */
    private function cards(DOMXPath $xpath, ?array $main): array
    {
        $groups = new SplObjectStorage;
        $order = [];

        foreach ($xpath->query('//body//*') as $element) {
            if (! $element instanceof DOMElement || ! $this->isCardShape($element)) {
                continue;
            }
            // Seul le bloc le plus large compte : son parent contient plusieurs prix (c'est la liste).
            if ($element->parentNode instanceof DOMElement && $this->isCardShape($element->parentNode)) {
                continue;
            }
            if ($this->insideChrome($element) || ($main && ($main['block'] === $element || $this->contains($element, $main['block']) || $this->contains($main['block'], $element)))) {
                continue;
            }
            if (($name = $this->name($element)) === null) {
                continue;
            }

            $parent = $element->parentNode;
            $group = $groups[$parent] ?? [];
            $group[] = ['element' => $element, 'name' => $name];
            $groups[$parent] = $group;
            $order[spl_object_id($parent)] = $parent;
        }

        $cards = [];
        foreach ($order as $parent) {
            $group = $groups[$parent];
            // Un bloc seul n'est une fiche que s'il a une image et un lien : sinon c'est un bandeau ou une promotion.
            if (count($group) === 1 && ! ($this->imageOf($group[0]['element'], $group[0]['name'], null) && $this->linksOf($group[0]['element'], $group[0]['name'], null)[0] !== null)) {
                continue;
            }
            array_push($cards, ...$group);
        }

        return $cards;
    }

    private function isCardShape(DOMElement $element): bool
    {
        $info = $this->info[$element] ?? null;

        return $info !== null && count($info['prices']) === 1 && $info['len'] >= 8 && $info['len'] <= 600
            && ! in_array($element->nodeName, ['body', 'html', 'main', 'header', 'footer', 'nav', 'form', 'table', 'tbody', 'tr', 'td'], true);
    }

    private function insideChrome(DOMNode $node): bool
    {
        for ($n = $node->parentNode; $n instanceof DOMElement; $n = $n->parentNode) {
            if (in_array($n->nodeName, ['header', 'nav', 'footer', 'aside'], true)) {
                return true;
            }
        }

        return false;
    }

    private function contains(DOMNode $ancestor, DOMNode $node): bool
    {
        for ($n = $node->parentNode; $n; $n = $n->parentNode) {
            if ($n === $ancestor) {
                return true;
            }
        }

        return false;
    }

    /* ---------- Lecture d'un bloc produit ---------- */

    private function productFrom(DOMElement $block, string $name, ?string $baseUrl, bool $detail): CatalogProduct
    {
        $text = $this->text($block);
        $price = PriceText::first($text);
        $money = $price !== null ? Money::parse($price, $this->currency) : ['display' => null, 'amount' => null, 'currency' => null];
        [$link, $order] = $this->linksOf($block, $name, $baseUrl);

        $description = null;
        $highlights = [];
        if ($detail) {
            [$description, $highlights] = $this->detailsOf($block, $name);
        }

        return new CatalogProduct(
            name: $name,
            category: $this->categoryOf($block, $name),
            price: $money['display'],
            amount: $money['amount'],
            currency: $money['currency'],
            availability: $this->availabilityIn($text),
            description: $description,
            link: $link,
            image: $this->imageOf($block, $name, $baseUrl),
            orderLink: $order,
            rating: $this->ratingIn($text),
            highlights: $highlights,
        );
    }

    private function name(DOMElement $element): ?string
    {
        $candidates = [];
        if (in_array($element->nodeName, ['h1', 'h2', 'h3', 'h4', 'h5', 'h6'], true)) {
            $candidates[] = $this->text($element);
        }
        $xpath = new DOMXPath($element->ownerDocument);
        foreach ($xpath->query('.//h1|.//h2|.//h3|.//h4|.//h5|.//h6', $element) as $heading) {
            $candidates[] = $this->text($heading);
        }
        foreach ($xpath->query('.//*[@class]', $element) as $node) {
            if ($node instanceof DOMElement && preg_match('/(?:^|[\s_-])(?:title|titre|name|nom|product-name)(?:$|[\s_-])/i', $node->getAttribute('class'))) {
                $candidates[] = $this->text($node);
            }
        }
        foreach ($xpath->query('.//a[@title]|.//img[@alt]', $element) as $node) {
            $candidates[] = $node instanceof DOMElement ? trim($node->getAttribute($node->nodeName === 'img' ? 'alt' : 'title')) : '';
        }

        foreach ($candidates as $candidate) {
            $candidate = trim(preg_replace('/\s+/u', ' ', $candidate), " \t\n\r:-");
            if ($candidate !== '' && mb_strlen($candidate) <= 120 && ! preg_match(self::BUTTONS, $candidate) && PriceText::all($candidate) === []) {
                return $candidate;
            }
        }

        return null;
    }

    private function availabilityIn(string $text): ?string
    {
        return match (true) {
            (bool) preg_match('/(\d+)\s*(?:en stock|disponibles?|in stock|restants?)/iu', $text, $m) => (int) $m[1] > 0 ? 'En stock' : 'Rupture de stock',
            (bool) preg_match('/rupture(?: de stock)?|[ée]puis[ée]|sold out|out of stock|indisponible|plus disponible/iu', $text) => 'Rupture de stock',
            (bool) preg_match('/\ben stock\b|\bin stock\b|\bdisponible\b/iu', $text) => 'En stock',
            (bool) preg_match('/sur commande|pr[ée]commande|pre-?order/iu', $text) => 'Sur commande',
            default => null,
        };
    }

    private function ratingIn(string $text): ?float
    {
        if (preg_match('/(\d(?:[.,]\d)?)\s*\/\s*5\b/u', $text, $m) || preg_match('/★\s*(\d(?:[.,]\d)?)/u', $text, $m)) {
            $rating = (float) str_replace(',', '.', $m[1]);

            return $rating > 0 && $rating <= 5 ? $rating : null;
        }

        return null;
    }

    /** « Réparatrice » : l'étiquette de catégorie du bloc, ou à défaut le fil d'Ariane. */
    private function categoryOf(DOMElement $block, string $name): ?string
    {
        $xpath = new DOMXPath($block->ownerDocument);
        foreach ($xpath->query('.//*[@class]', $block) as $node) {
            if (! $node instanceof DOMElement || ! preg_match(self::CATEGORY_CLASS, $node->getAttribute('class'))) {
                continue;
            }
            $text = $this->text($node);
            if (mb_strlen($text) >= 2 && mb_strlen($text) <= 40 && $text !== $name && PriceText::all($text) === [] && $this->availabilityIn($text) === null && ! preg_match(self::BUTTONS, $text)) {
                return $text;
            }
        }

        // Fil d'Ariane : l'avant-dernier maillon (le dernier est le produit lui-même).
        $crumbs = [];
        foreach ($xpath->query('.//*[@aria-label or @class]//a', $block) as $a) {
            $parent = $a->parentNode;
            while ($parent instanceof DOMElement && ! $parent->hasAttribute('aria-label') && ! $parent->hasAttribute('class')) {
                $parent = $parent->parentNode;
            }
            $hint = strtolower($parent instanceof DOMElement ? $parent->getAttribute('aria-label').' '.$parent->getAttribute('class') : '');
            if (preg_match('/ariane|breadcrumb|crumb|(?:^|\s)fil(?:\s|$)/', $hint)) {
                $crumbs[] = $this->text($a);
            }
        }
        $crumbs = array_values(array_filter($crumbs, fn ($c) => $c !== '' && ! preg_match('/^(accueil|home)$/i', $c)));

        return $crumbs !== [] ? end($crumbs) : null;
    }

    /** Du titre d'une page de catégorie (« Gamme Réparatrice ») à la catégorie de ses produits (« Réparatrice »). */
    private function categoryFromTitle(DOMXPath $xpath): ?string
    {
        $h1 = $xpath->query('//h1')->item(0);
        if (! $h1) {
            return null;
        }
        $title = preg_replace('/^(?:gamme|cat[ée]gorie|collection|rayon|nos|tous les|toute la|all)\s+/iu', '', $this->text($h1));

        return $title !== '' && mb_strlen($title) <= 40 ? $title : null;
    }

    /**
     * L'image du produit : celle qui porte son nom, sinon la première image de contenu (ni logo, ni icône, ni pastille).
     */
    private function imageOf(DOMElement $block, string $name, ?string $baseUrl): ?string
    {
        $xpath = new DOMXPath($block->ownerDocument);
        $best = null;

        foreach ($xpath->query('.//img|.//picture//source', $block) as $img) {
            if (! $img instanceof DOMElement) {
                continue;
            }
            $src = $this->imageUrl($img);
            if ($src === null || preg_match(self::BAD_IMAGE, $src)) {
                continue;
            }
            $w = (int) $img->getAttribute('width');
            $h = (int) $img->getAttribute('height');
            if (($w > 0 && $w < 80) || ($h > 0 && $h < 80)) {
                continue;
            }
            $alt = Text::fold($img->getAttribute('alt'));
            if ($alt !== '' && $alt === Text::fold($name)) {
                $best = $src;
                break;
            }
            $best ??= $src;
        }

        if ($best === null) {
            return null;
        }

        return $baseUrl ? Url::resolve($baseUrl, $best) : $best;
    }

    private function imageUrl(DOMElement $img): ?string
    {
        foreach (['data-src', 'data-lazy-src', 'data-original', 'src'] as $attribute) {
            $value = trim($img->getAttribute($attribute));
            if ($value !== '' && ! str_starts_with($value, 'data:')) {
                return $value;
            }
        }

        foreach (['srcset', 'data-srcset'] as $attribute) {
            $set = trim($img->getAttribute($attribute));
            if ($set === '') {
                continue;
            }
            $largest = null;
            $width = -1;
            foreach (explode(',', $set) as $candidate) {
                $parts = preg_split('/\s+/', trim($candidate));
                $w = isset($parts[1]) ? (int) $parts[1] : 0;
                if ($parts[0] !== '' && $w >= $width) {
                    $largest = $parts[0];
                    $width = $w;
                }
            }
            if ($largest !== null && ! str_starts_with($largest, 'data:')) {
                return $largest;
            }
        }

        return null;
    }

    /** @return array{0:?string, 1:?string} adresse de la page du produit, adresse pour le commander en ligne */
    private function linksOf(DOMElement $block, string $name, ?string $baseUrl): array
    {
        $xpath = new DOMXPath($block->ownerDocument);
        $product = null;
        $order = null;
        $first = null;

        foreach ($xpath->query('.//a[@href]', $block) as $a) {
            if (! $a instanceof DOMElement) {
                continue;
            }
            $href = trim($a->getAttribute('href'));
            if ($href === '' || preg_match('/^(#|javascript:|mailto:|tel:)/i', $href)) {
                continue;
            }
            $absolute = $baseUrl ? Url::resolve($baseUrl, $href) : $href;
            if ($absolute === null) {
                continue;
            }
            $path = (string) parse_url($absolute, PHP_URL_PATH);

            if (preg_match(self::ORDER_LINK, $path) && ! preg_match('/panier|cart|basket|ajouter|add-to/i', $path)) {
                $order ??= $absolute;

                continue;
            }
            if (preg_match(self::ACTION_LINK, $absolute)) {
                continue;
            }

            $label = Text::fold($this->text($a).' '.$a->getAttribute('aria-label').' '.$a->getAttribute('title'));
            if ($label !== '' && str_contains($label, Text::fold($name))) {
                $product ??= $absolute;
            }
            $first ??= $absolute;
        }

        return [$product ?? $first, $order];
    }

    /**
     * Description et points forts d'une fiche : les paragraphes et les puces du bloc, sans les boutons ni les prix.
     *
     * @return array{0:?string, 1:list<string>}
     */
    private function detailsOf(DOMElement $block, string $name): array
    {
        $xpath = new DOMXPath($block->ownerDocument);
        $paragraphs = [];
        foreach ($xpath->query('.//p', $block) as $p) {
            $text = $this->text($p);
            if (mb_strlen($text) >= 25 && PriceText::all($text) === [] && $text !== $name && ! preg_match(self::BUTTONS, $text)) {
                $paragraphs[] = $text;
            }
        }

        $points = [];
        foreach ($xpath->query('.//li', $block) as $li) {
            $text = $this->text($li);
            if (mb_strlen($text) >= 8 && mb_strlen($text) <= 140 && PriceText::all($text) === []) {
                $points[] = $text;
            }
        }

        $description = $paragraphs !== [] ? Text::limit(implode(' ', array_slice($paragraphs, 0, 3)), 600) : null;

        return [$description, array_slice(array_values(array_unique($points)), 0, 6)];
    }

    /* ---------- Modification de la page ---------- */

    private function replaceWithLine(DOMDocument $dom, DOMElement $card, CatalogProduct $product): void
    {
        $bits = array_filter([$product->price, $product->availability]);
        $line = $product->name.($bits !== [] ? ' : '.implode(', ', $bits) : '').'.';

        while ($card->firstChild) {
            $card->removeChild($card->firstChild);
        }
        $card->appendChild($dom->createTextNode($line));
    }

    /**
     * Retire les blocs d'autres produits d'une fiche (et leur section, avec son titre « Dans la même gamme »).
     *
     * @param  list<array{element:DOMElement, name:string}>  $cards
     */
    private function removeRelated(array $cards, ?DOMElement $mainBlock): void
    {
        foreach ($cards as $card) {
            $element = $card['element'];
            if (! $element->parentNode) {
                continue;
            }

            $section = null;
            for ($n = $element->parentNode; $n instanceof DOMElement && ! in_array($n->nodeName, ['body', 'html'], true); $n = $n->parentNode) {
                if ($n->nodeName === 'section') {
                    $section = $n;
                    break;
                }
            }

            $target = $section && (! $mainBlock || ! $this->contains($section, $mainBlock)) ? $section : $element;
            $target->parentNode?->removeChild($target);
        }
    }

    /* ---------- Utilitaires ---------- */

    private function meta(DOMXPath $xpath, string $property): ?string
    {
        $value = trim((string) $xpath->evaluate('string(//meta[@property="'.$property.'"]/@content)'));

        return $value !== '' ? $value : null;
    }

    private function plain(string $text): string
    {
        return trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($text))));
    }

    private function key(string $name): string
    {
        return trim(preg_replace('/\s+/', ' ', Text::fold($name)));
    }

    /**
     * @param  list<CatalogProduct>  $products
     * @return list<CatalogProduct>
     */
    private function unique(array $products): array
    {
        $unique = [];
        foreach ($products as $product) {
            foreach ($unique as $k => $existing) {
                if ($this->same($existing, $product)) {
                    $unique[$k] = $existing->mergedWith($product);
                    continue 2;
                }
            }
            $unique[] = $product;
        }

        return $unique;
    }

    /**
     * Le même produit ? Même nom, et pas deux adresses différentes : « Duo visage » en Réparatrice et « Duo visage » en Glow
     * Skin portent le même nom sur le site, mais ce sont deux produits à deux prix.
     */
    private function same(CatalogProduct $a, CatalogProduct $b): bool
    {
        if ($this->key($a->name) !== $this->key($b->name)) {
            return false;
        }

        return $a->link === null || $b->link === null || \App\Ingestion\Crawler\UrlRules::dedupeKey($a->link) === \App\Ingestion\Crawler\UrlRules::dedupeKey($b->link);
    }
}
