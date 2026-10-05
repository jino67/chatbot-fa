<?php

namespace Tests\Unit;

use App\Ingestion\Catalog\CatalogProduct;
use App\Ingestion\Catalog\PriceText;
use App\Ingestion\Crawler\PageReader;
use App\Ingestion\Crawler\UrlRules;
use App\Ingestion\Extractors\HtmlToText;
use Tests\TestCase;

/** Lecture des produits d'une page web : le nom reste collé à son prix, les produits « de la même gamme » ne se mélangent pas. */
class ProductScannerTest extends TestCase
{
    private function read(string $html, string $url): array
    {
        return (new PageReader(new HtmlToText))->read($html, $url);
    }

    private function fixture(string $name): string
    {
        return (string) file_get_contents(__DIR__.'/../Fixtures/boutique/'.$name);
    }

    /** « 5 000 FCFA » tel que la plateforme l'écrit (espace fine insécable entre les milliers). */
    private function money(int $amount): string
    {
        return number_format($amount, 0, ',', "\u{202F}").' FCFA';
    }

    /** @param list<CatalogProduct> $products */
    private function byName(array $products, string $name): CatalogProduct
    {
        foreach ($products as $product) {
            if ($product->name === $name) {
                return $product;
            }
        }

        $this->fail("produit introuvable : {$name}");
    }

    /* ---------- Prix ---------- */

    public function test_prices_are_found_with_their_currency_and_nothing_else(): void
    {
        $this->assertSame(['5 000XOF'], PriceText::all('Duo visage 12 en stock 5 000XOF'));
        $this->assertSame(['18.000 F CFA'], PriceText::all('Robe : 18.000 F CFA'));
        $this->assertSame(['XOF 5000'], PriceText::all('Prix XOF 5000 seulement'));
        $this->assertSame(['12,50 €'], PriceText::all('12,50 € TTC'));
        $this->assertSame([], PriceText::all('Livraison sous 48 h, 12 en stock, note 4.5/5'), 'un nombre sans monnaie n\'est pas un prix');
        $this->assertEquals([5000], PriceText::amounts('5 000 XOF puis 5000 FCFA'), 'le même montant n\'est compté qu\'une fois');
    }

    /* ---------- Fiche produit ---------- */

    public function test_a_product_page_gives_one_sheet_with_the_right_price_photo_and_order_link(): void
    {
        $page = $this->read($this->fixture('fiche-produit.html'), 'https://poupecosmetic.com/produits/Duo-visage');

        $main = $page['main'];
        $this->assertNotNull($main);
        $this->assertSame('Duo visage', $main->name);
        $this->assertSame($this->money(5000), $main->price);
        $this->assertSame('Réparatrice', $main->category);
        $this->assertSame('En stock', $main->availability);
        $this->assertSame('https://poupecosmetic.com/produits/Duo-visage', $main->link);
        $this->assertSame('https://poupecosmetic.com/commander/2', $main->orderLink);
        $this->assertStringEndsWith('70pCQeRdskXJmVkzpN8eeL4GRTcukyzU3lZOyKsH.png', (string) $main->image);
        $this->assertSame(4.5, $main->rating);
        $this->assertStringContainsString('non éclaircissant', (string) $main->description);
        $this->assertContains('Ingrédients naturels', $main->highlights);
    }

    public function test_the_prices_of_related_products_never_mix_into_a_product_page(): void
    {
        $page = $this->read($this->fixture('fiche-produit.html'), 'https://poupecosmetic.com/produits/Duo-visage');

        // « Dans la même gamme » portait la mini gamme à 31 000 : c'est ainsi que le Duo visage s'est retrouvé à 31 000.
        $digits = str_replace([" ", " ", ' '], '', $page['text']);
        $this->assertStringNotContainsString('31000', $digits);
        $this->assertStringNotContainsString('8000', $digits);
        $this->assertStringNotContainsString('Mini gamme réparatrice', $page['text']);
        $this->assertStringNotContainsString('Dans la même gamme', $page['text']);
        $this->assertStringContainsString('5 000XOF', $page['text']);
    }

    /* ---------- Liste ---------- */

    public function test_a_category_page_gives_every_product_with_its_own_price(): void
    {
        $page = $this->read($this->fixture('categorie.html'), 'https://poupecosmetic.com/categories/reparatrice');

        $this->assertTrue($page['listing']);
        $this->assertNull($page['main']);
        $this->assertCount(10, $page['products']);

        $expected = [
            'Duo visage' => 5000, 'Savon réparateur' => 7000, 'Gommage Coffee' => 6000, 'Lait collagène' => 8000,
            'Trio de visage réparateur' => 10000, 'Mini gamme réparatrice' => 31000, 'Gamme réparatrice' => 48000,
            'Lotion vert anti-imperfections' => 5000, 'Savon visage hydratant' => 2000, 'Crème visage hydratant' => 3000,
        ];
        foreach ($expected as $name => $amount) {
            $product = $this->byName($page['products'], $name);
            $this->assertSame($this->money($amount), $product->price, $name);
            $this->assertSame('Réparatrice', $product->category, $name);
            $this->assertNotNull($product->image, "photo de {$name}");
            $this->assertNotNull($product->link, "lien de {$name}");
        }

        $this->assertStringEndsWith('/produits/duo-visage', (string) $this->byName($page['products'], 'Duo visage')->link);
    }

    public function test_cards_become_one_line_each_so_the_name_stays_with_its_price(): void
    {
        $page = $this->read($this->fixture('categorie.html'), 'https://poupecosmetic.com/categories/reparatrice');

        // Le texte d'une page ne garde que des espaces ordinaires.
        $text = str_replace(" ", ' ', $page['text']);
        $this->assertStringContainsString('Mini gamme réparatrice : 31 000 FCFA, En stock.', $text);
        $this->assertStringContainsString('Duo visage : 5 000 FCFA, En stock.', $text);
    }

    /* ---------- Données structurées et cas limites ---------- */

    public function test_structured_data_gives_price_photo_and_availability(): void
    {
        $html = '<html><head><title>Boubou brodé</title><script type="application/ld+json">'.json_encode([
            '@context' => 'https://schema.org', '@type' => 'Product', 'name' => 'Boubou brodé', 'sku' => 'BB-12',
            'description' => 'Boubou en bazin riche brodé à la main.', 'image' => ['https://boutique.test/img/boubou.jpg'],
            'brand' => ['@type' => 'Brand', 'name' => 'Awa Couture'],
            'offers' => ['@type' => 'Offer', 'price' => '45000', 'priceCurrency' => 'XOF', 'availability' => 'https://schema.org/InStock', 'url' => 'https://boutique.test/boubou'],
        ]).'</script></head><body><h1>Boubou brodé</h1><p>Un grand classique.</p></body></html>';

        $page = $this->read($html, 'https://boutique.test/boubou');

        $this->assertNotNull($page['main']);
        $this->assertSame($this->money(45000), $page['main']->price);
        $this->assertSame('En stock', $page['main']->availability);
        $this->assertSame('https://boutique.test/img/boubou.jpg', $page['main']->image);
        $this->assertSame('BB-12', $page['main']->reference);
        $this->assertSame('Awa Couture', $page['main']->brand);
    }

    public function test_a_struck_through_old_price_is_never_taken_for_the_price(): void
    {
        $html = '<html><body><main><h1>Sac en cuir</h1><p>Un sac solide pour tous les jours, fait main.</p><del>25 000 FCFA</del> <span>18 000 FCFA</span><img src="/img/sac.jpg" alt="Sac en cuir"></main></body></html>';

        $page = $this->read($html, 'https://boutique.test/sac');

        $this->assertSame($this->money(18000), $page['main']->price);
    }

    public function test_a_single_promotional_banner_is_not_a_product(): void
    {
        $html = '<html><body><h1>Bienvenue</h1><section><h2>Livraison gratuite</h2><p>Dès 25 000 FCFA d\'achat, la livraison est offerte.</p></section></body></html>';

        $page = $this->read($html, 'https://boutique.test/');

        $this->assertSame([], $page['products']);
    }

    public function test_a_page_without_prices_is_left_as_plain_text(): void
    {
        $page = $this->read('<html><head><title>Contact</title></head><body><h1>Contact</h1><p>Appelez-nous du lundi au samedi de 8 h à 19 h, nous répondons vite.</p></body></html>', 'https://boutique.test/contact');

        $this->assertSame([], $page['products']);
        $this->assertNull($page['main']);
        $this->assertStringContainsString('du lundi au samedi', $page['text']);
    }

    /* ---------- Adresses ---------- */

    public function test_useless_pages_are_never_read_and_the_rest_is_ranked_for_a_customer(): void
    {
        foreach (['/commander/27', '/panier', '/login', '/register', '/commande/verifier', '/mon-compte', '/favoris', '/produits?add-to-cart=3'] as $path) {
            $this->assertNotNull(UrlRules::skipReason('https://boutique.test'.$path), $path);
        }
        foreach (['/contact', '/produits/duo-visage', '/categories/reparatrice', '/faqs', '/a-propos', '/comment-commander'] as $path) {
            $this->assertNull(UrlRules::skipReason('https://boutique.test'.$path), $path);
        }

        $this->assertLessThan(UrlRules::priority('https://boutique.test/categories/soins'), UrlRules::priority('https://boutique.test/contact'));
        $this->assertLessThan(UrlRules::priority('https://boutique.test/produits/duo-visage'), UrlRules::priority('https://boutique.test/categories/soins'));
        $this->assertLessThan(UrlRules::priority('https://boutique.test/blog/nouveautes'), UrlRules::priority('https://boutique.test/produits/duo-visage'));
        $this->assertSame(0, UrlRules::priority('https://boutique.test/'));
    }

    public function test_the_same_page_under_another_spelling_is_one_page(): void
    {
        $key = UrlRules::dedupeKey('https://poupecosmetic.com/produits/Duo-visage');

        $this->assertSame($key, UrlRules::dedupeKey('https://www.poupecosmetic.com/produits/duo-visage/'));
        $this->assertSame($key, UrlRules::dedupeKey('https://poupecosmetic.com/produits/Duo-visage?utm_source=x'));
        $this->assertNotSame($key, UrlRules::dedupeKey('https://poupecosmetic.com/produits/duo-visage-glow-skin'));
    }
}
