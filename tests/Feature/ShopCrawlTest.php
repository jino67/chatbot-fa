<?php

namespace Tests\Feature;

use App\Ingestion\Catalog\CatalogProduct;
use App\Ingestion\Crawler\SafeUrl;
use App\Ingestion\IngestionPipeline;
use App\Models\Bot;
use App\Models\CatalogItem;
use App\Models\Plan;
use App\Models\Source;
use App\Models\SourcePage;
use App\Models\User;
use App\Services\ProductCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * Lecture d'une boutique en ligne : les pages inutiles (panier, connexion, formulaires de commande) sont ignorées, chaque
 * produit a sa fiche avec son vrai prix et sa photo, la limite de l'offre garde l'essentiel, et la lecture se reprend.
 */
class ShopCrawlTest extends TestCase
{
    use CreatesTenants;
    use RefreshDatabase;

    private Bot $bot;

    private User $owner;

    private const SHOP = 'https://boutique.exemple.com';

    private const PRODUCTS = [
        'creme' => ['Crème visage', 5000, 'Hydrate la peau sensible sans la graisser.'],
        'serum' => ['Sérum éclat', 12000, 'Un sérum à la vitamine C pour un teint lumineux.'],
        'huile' => ['Huile corporelle', 7000, 'Nourrit la peau sèche, parfum léger de karité.'],
        'savon' => ['Savon doux', 2000, 'Nettoie en douceur, convient à toute la famille.'],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
        SafeUrl::useResolver(fn () => ['93.184.216.34']);
        [, $this->owner, $this->bot] = $this->tenant('Boutique Awa', 'pro');
    }

    protected function tearDown(): void
    {
        SafeUrl::useResolver(null);
        parent::tearDown();
    }

    private function money(int $amount): string
    {
        return number_format($amount, 0, ',', "\u{202F}").' FCFA';
    }

    /** Une réponse neuve à chaque requête : le corps d'une réponse déjà lue ne se relit pas. */
    private function page(string $title, string $body, string $head = '')
    {
        return fn () => Http::response("<html><head><title>{$title}</title>{$head}</head><body><header><a href=\"/\">Accueil</a><a href=\"/panier\">Panier</a><a href=\"/login\">Connexion</a></header>{$body}<footer>Boutique Awa, Ouagadougou. Contact : 70 00 00 00. Livraison sous 48 h.</footer></body></html>", 200, ['Content-Type' => 'text/html']);
    }

    private function card(string $slug): string
    {
        [$name, $price] = self::PRODUCTS[$slug];

        return '<article class="pr"><a href="/produits/'.$slug.'"><img src="/img/'.$slug.'.jpg" alt="'.$name.'"></a><h3>'.$name.'</h3><span>En stock</span><div>'.number_format($price, 0, ',', ' ').'<small>XOF</small></div><a href="/commander/'.$slug.'">Commander</a></article>';
    }

    private function fakeShop(): void
    {
        $cards = fn (array $slugs) => '<div class="prods">'.implode('', array_map(fn ($s) => $this->card($s), $slugs)).'</div>';

        $fakes = [
            self::SHOP.'/robots.txt' => fn () => Http::response('', 404),
            self::SHOP.'/sitemap.xml' => fn () => Http::response('<?xml version="1.0"?><urlset>'
                .'<url><loc>'.self::SHOP.'/</loc></url><url><loc>'.self::SHOP.'/produits/serum</loc></url><url><loc>'.self::SHOP.'/commander/serum</loc></url>'
                .'<url><loc>'.self::SHOP.'/produits/creme</loc></url><url><loc>'.self::SHOP.'/login</loc></url><url><loc>'.self::SHOP.'/categories/soins</loc></url>'
                .'<url><loc>'.self::SHOP.'/contact</loc></url></urlset>', 200, ['Content-Type' => 'application/xml']),
            self::SHOP.'/sitemap_index.xml' => fn () => Http::response('', 404),
            self::SHOP.'/' => $this->page('Accueil', '<main><h1>Boutique Awa</h1><p>Des soins naturels faits à Ouagadougou depuis 2015, livrés chez vous.</p>'
                .'<a href="/contact">Contact</a><a href="/categories/soins">Soins</a><a href="/Produits/Creme">Crème (autre graphie)</a><a href="/blog/conseils">Conseils</a><a href="/commander/creme">Commander</a>'
                .'<section><h2>Nos produits phares</h2>'.$cards(['creme', 'serum']).'</section></main>'),
            self::SHOP.'/contact' => $this->page('Contact', '<main><h1>Contact</h1><p>Écrivez-nous sur WhatsApp au +226 70 00 00 00, tous les jours de 8 h à 20 h.</p></main>'),
            self::SHOP.'/blog/conseils' => $this->page('Conseils', '<main><h1>Prendre soin de sa peau</h1><p>Nettoyez votre visage matin et soir avec un produit doux, puis hydratez.</p></main>'),
            self::SHOP.'/categories/soins' => $this->page('Soins', '<main><h1>Gamme Soins</h1><p>Nos soins du visage et du corps.</p>'.$cards(['creme', 'serum', 'huile', 'savon']).'</main>'),
        ];

        foreach (self::PRODUCTS as $slug => [$name, $price, $about]) {
            $related = $slug === 'creme' ? '<section class="sec"><h2>Dans la même gamme</h2>'.$cards(['serum']).'</section>' : '';
            $fakes[self::SHOP.'/produits/'.$slug] = $this->page($name, '<main><section><nav class="fil"><a href="/">Accueil</a><a href="/categories/soins">Soins</a><span>'.$name.'</span></nav>'
                .'<img src="/img/'.$slug.'.jpg" alt="'.$name.'"><h1>'.$name.'</h1><span>12 en stock</span><p>'.$about.'</p>'
                .'<div>'.number_format($price, 0, ',', ' ').'<small>XOF</small></div><a href="/commander/'.$slug.'">Commander maintenant</a></section>'.$related.'</main>');
        }

        Http::fake($fakes + [self::SHOP.'/*' => fn () => Http::response('', 404)]);
    }

    private function source(): Source
    {
        return Source::create([
            'workspace_id' => $this->bot->workspace_id, 'bot_id' => $this->bot->id, 'type' => Source::TYPE_URL,
            'name' => 'boutique.exemple.com', 'payload' => ['url' => self::SHOP, 'mode' => 'site'],
        ]);
    }

    private function setPageLimit(int $pages): void
    {
        $plan = Plan::bySlug('pro');
        $plan->update(['limits' => ['pages_per_crawl' => $pages] + $plan->limits]);
    }

    private function requested(string $path): bool
    {
        return Http::recorded(fn ($request) => $request->url() === self::SHOP.$path)->isNotEmpty();
    }

    /* ---------- Une boutique lue en entier ---------- */

    public function test_a_shop_is_read_without_its_useless_pages_and_each_page_once(): void
    {
        $this->fakeShop();
        $source = $this->source();

        app(IngestionPipeline::class)->run($source);

        $source->refresh();
        $this->assertSame(Source::READY, $source->status, (string) $source->error);
        // Accueil, contact, article, catégorie et les quatre fiches produit : le reste (panier, connexion, commandes, doublon) est ignoré.
        $this->assertSame(8, $source->stats['pages']);
        $this->assertSame(8, $source->stats['found']);
        $this->assertGreaterThanOrEqual(3, $source->stats['ignored']);
        $this->assertFalse($source->stats['truncated']);
        $this->assertNull($source->progress, 'la lecture est terminée');

        foreach (['/panier', '/login', '/commander/serum', '/commander/creme'] as $path) {
            $this->assertFalse($this->requested($path), "{$path} ne se lit jamais");
        }
        $this->assertSame(1, Http::recorded(fn ($r) => $r->url() === self::SHOP.'/produits/creme')->count(), 'une page lue une seule fois');
        $this->assertFalse($this->requested('/Produits/Creme'), 'la même page avec une autre graphie ne se relit pas');
    }

    public function test_every_product_gets_a_sheet_with_its_own_price_and_a_photo_reference(): void
    {
        $this->fakeShop();
        $source = $this->source();

        app(IngestionPipeline::class)->run($source);

        $this->assertSame(4, $source->fresh()->stats['products']);
        $this->assertSame(4, $source->fresh()->stats['photos']);
        $this->assertSame(4, CatalogItem::withoutGlobalScopes()->where('bot_id', $this->bot->id)->count());

        $creme = CatalogItem::withoutGlobalScopes()->where('name', 'Crème visage')->firstOrFail();
        $this->assertSame($this->money(5000), $creme->price_text);
        $this->assertSame('Soins', $creme->category);
        $this->assertSame(self::SHOP.'/img/creme.jpg', $creme->image_url);
        $this->assertSame(CatalogItem::IMAGE_PENDING, $creme->image_status);

        $sheet = $source->documents()->where('title', 'Crème visage')->firstOrFail()->content;
        $this->assertStringContainsString('Produit : Crème visage', $sheet);
        $this->assertStringContainsString('Prix : '.$this->money(5000), $sheet);
        $this->assertStringContainsString('Catégorie : Soins', $sheet);
        $this->assertStringContainsString('Photo : '.$creme->ref(), $sheet);
        $this->assertStringContainsString('Commander en ligne : '.self::SHOP.'/commander/creme', $sheet);
        // Le sérum, présenté dans « Dans la même gamme » de la crème, ne se mélange pas à elle.
        $this->assertStringNotContainsString('Sérum', $sheet);
        $this->assertStringNotContainsString($this->money(12000), $sheet);
    }

    public function test_lines_repeated_on_every_sheet_stay_in_every_sheet(): void
    {
        $this->fakeShop();
        $source = $this->source();

        app(IngestionPipeline::class)->run($source);

        // Les menus et pieds de page répétés sont retirés des pages ; « Disponibilité : En stock » reste dans chaque fiche.
        foreach (self::PRODUCTS as [$name]) {
            $sheet = $source->documents()->where('title', $name)->firstOrFail()->content;
            $this->assertStringContainsString('Disponibilité : En stock', $sheet, $name);
        }
        $this->assertNotNull($source->documents()->where('title', 'like', 'Catalogue :%')->first(), 'un aperçu de tout le catalogue');
    }

    /* ---------- La limite de l'offre ---------- */

    public function test_a_small_plan_reads_information_pages_and_categories_before_products(): void
    {
        $this->setPageLimit(3);
        $this->fakeShop();
        $source = $this->source();

        app(IngestionPipeline::class)->run($source);

        $source->refresh();
        $this->assertSame(Source::READY, $source->status, (string) $source->error);
        $this->assertSame(3, $source->stats['pages']);
        $this->assertTrue($source->stats['truncated']);
        $this->assertGreaterThan(3, $source->stats['found']);

        $this->assertTrue($this->requested('/contact'), 'la page de contact est lue');
        $this->assertTrue($this->requested('/categories/soins'), 'la catégorie est lue');
        $this->assertFalse($this->requested('/produits/savon'), 'les fiches produit passent après');
        // La page de catégorie liste les quatre produits : l'assistant les connaît même sans avoir lu leurs fiches.
        $this->assertSame(4, $source->stats['products']);
    }

    /* ---------- Une lecture qui reprend ---------- */

    public function test_reading_stops_after_a_few_seconds_and_resumes_where_it_left_off(): void
    {
        $this->fakeShop();
        $source = $this->source();
        $pipeline = app(IngestionPipeline::class);

        $pipeline->run($source, 0.0);
        $source->refresh();

        $this->assertSame(Source::PROCESSING, $source->status, 'une seule page lue : la lecture continue');
        $this->assertSame(1, SourcePage::withoutGlobalScopes()->where('source_id', $source->id)->where('status', 'done')->count());
        $this->assertNotNull($source->progress);

        for ($i = 0; $i < 30 && $source->fresh()->status === Source::PROCESSING; $i++) {
            $pipeline->run($source->fresh(), 0.0);
        }

        $source->refresh();
        $this->assertSame(Source::READY, $source->status, (string) $source->error);
        $this->assertSame(8, $source->stats['pages']);
        $this->assertSame(4, $source->stats['products']);
        $this->assertSame(1, Http::recorded(fn ($r) => $r->url() === self::SHOP.'/')->count(), 'la page de départ n\'est pas relue entre deux tranches');
    }

    public function test_the_open_page_keeps_a_site_reading_by_asking_for_the_next_slice(): void
    {
        $this->fakeShop();
        $source = $this->source();
        app(IngestionPipeline::class)->run($source, 0.0);

        $this->actingAs($this->owner)->postJson(route('sources.advance', [$this->bot, $source]))
            ->assertOk()
            ->assertJsonPath('status', 'ready')
            ->assertJsonPath('stats.pages', 8);
    }

    public function test_the_sources_page_shows_the_progress_of_a_site_being_read(): void
    {
        $this->fakeShop();
        $source = $this->source();
        app(IngestionPipeline::class)->run($source, 0.0);

        $this->actingAs($this->owner)->get(route('sources.index', $this->bot))->assertOk()
            ->assertSee('1 page(s) lue(s)')->assertSee('la lecture continue toute seule')->assertSee('sources\/'.$source->id.'\/avancer', false); // adresse écrite dans le script (JSON, barres échappées)

        app(IngestionPipeline::class)->run($source->fresh());

        $this->actingAs($this->owner)->get(route('sources.index', $this->bot))->assertOk()
            ->assertSee('8 page(s) lue(s)')->assertSee('4 produit(s)')->assertSee('4 avec photo')->assertSee('ont été ignorées');
    }

    public function test_the_sources_page_tells_which_addresses_of_the_site_map_no_longer_answer(): void
    {
        $source = $this->source();
        $source->forceFill(['status' => Source::READY, 'stats' => ['pages' => 36, 'found' => 43, 'limit' => 200, 'plan_limit' => 200, 'truncated' => false, 'ignored' => 36, 'failed' => 7, 'chunks' => 46, 'tokens' => 8000]])->save();

        $this->actingAs($this->owner)->get(route('sources.index', $this->bot))->assertOk()
            ->assertSee('36 page(s) lue(s)')->assertSee('sur 43 trouvée(s)')->assertSee('7 adresse(s) du plan de votre site ne répondent plus');
    }

    public function test_the_scheduler_resumes_a_site_nobody_is_advancing(): void
    {
        $this->fakeShop();
        $source = $this->source();
        app(IngestionPipeline::class)->run($source, 0.0);
        $this->assertSame(Source::PROCESSING, $source->fresh()->status);

        $this->artisan('sources:continue')->assertSuccessful();
        $this->assertSame(Source::PROCESSING, $source->fresh()->status, 'une lecture toute récente n\'est pas reprise');

        $this->travel(5)->minutes();
        $this->artisan('sources:continue')->assertSuccessful();

        $this->assertSame(Source::READY, $source->fresh()->status);
        $this->assertSame(8, $source->fresh()->stats['pages']);
    }

    public function test_reading_again_starts_a_fresh_crawl(): void
    {
        $this->fakeShop();
        $source = $this->source();
        app(IngestionPipeline::class)->run($source);

        $this->actingAs($this->owner)->post(route('sources.resync', [$this->bot, $source]))->assertRedirect();

        $source->refresh();
        $this->assertSame(Source::READY, $source->status, (string) $source->error);
        $this->assertSame(8, $source->stats['pages']);
        $this->assertSame(4, CatalogItem::withoutGlobalScopes()->where('bot_id', $this->bot->id)->count(), 'les produits ne sont pas dupliqués');
    }

    public function test_two_products_with_the_same_name_stay_two_products_told_apart_by_their_category(): void
    {
        $cards = fn (string $link, string $price) => '<div class="prods"><article><a href="'.$link.'"><img src="/img/duo.jpg" alt="Duo visage"></a><h3>Duo visage</h3><span>En stock</span><div>'.$price.'<small>XOF</small></div></article>'
            .'<article><a href="/produits/savon"><img src="/img/savon.jpg" alt="Savon"></a><h3>Savon</h3><span>En stock</span><div>2 000<small>XOF</small></div></article></div>';

        Http::fake([
            self::SHOP.'/robots.txt' => fn () => Http::response('', 404),
            self::SHOP.'/sitemap.xml' => fn () => Http::response('', 404),
            self::SHOP.'/sitemap_index.xml' => fn () => Http::response('', 404),
            self::SHOP.'/' => $this->page('Accueil', '<main><h1>Boutique</h1><p>Des soins naturels faits à Ouagadougou depuis 2015, livrés chez vous.</p><a href="/categories/reparatrice">Réparatrice</a><a href="/categories/glow">Glow Skin</a></main>'),
            self::SHOP.'/categories/reparatrice' => $this->page('Réparatrice', '<main><h1>Gamme Réparatrice</h1>'.$cards('/produits/duo-visage', '5 000').'</main>'),
            self::SHOP.'/categories/glow' => $this->page('Glow', '<main><h1>Gamme Glow Skin</h1>'.$cards('/produits/duo-visage-glow-skin', '7 000').'</main>'),
            self::SHOP.'/*' => fn () => Http::response('', 404),
        ]);
        $source = $this->source();

        app(IngestionPipeline::class)->run($source);

        $names = CatalogItem::withoutGlobalScopes()->where('bot_id', $this->bot->id)->orderBy('name')->pluck('price_text', 'name')->all();
        $this->assertSame([
            'Duo visage (Glow Skin)' => $this->money(7000),
            'Duo visage (Réparatrice)' => $this->money(5000),
            'Savon' => $this->money(2000),
        ], $names, 'chaque Duo visage garde son prix et son nom précis');
        $this->assertSame(3, $source->fresh()->stats['products']);
    }

    /* ---------- Le catalogue ---------- */

    public function test_a_known_product_keeps_its_reference_and_a_vanished_one_leaves_the_catalog(): void
    {
        $source = $this->source();
        $catalog = app(ProductCatalog::class);
        $a = new CatalogProduct('Crème', price: '5 000 FCFA', link: self::SHOP.'/produits/creme', image: self::SHOP.'/a.jpg');
        $b = new CatalogProduct('Sérum', price: '12 000 FCFA', link: self::SHOP.'/produits/serum');

        [$first] = $catalog->sync($source, [$a, $b]);
        $ref = $first['item']->ref();

        $catalog->sync($source, [new CatalogProduct('Crème visage', price: '5 500 FCFA', link: self::SHOP.'/Produits/Creme/', image: self::SHOP.'/a.jpg')]);

        $kept = CatalogItem::withoutGlobalScopes()->where('bot_id', $this->bot->id)->get();
        $this->assertCount(1, $kept, 'le sérum a disparu du site');
        $this->assertSame($ref, $kept[0]->ref(), 'la référence reste valable');
        $this->assertSame('Crème visage', $kept[0]->name);
        $this->assertSame('5 500 FCFA', $kept[0]->price_text);
    }

    public function test_a_changed_photo_is_read_again(): void
    {
        $source = $this->source();
        $catalog = app(ProductCatalog::class);
        [$one] = $catalog->sync($source, [new CatalogProduct('Crème', link: self::SHOP.'/produits/creme', image: self::SHOP.'/a.jpg')]);
        $one['item']->forceFill(['image_status' => CatalogItem::IMAGE_READY, 'image_path' => 'catalog/x.jpg'])->save();

        [$two] = $catalog->sync($source, [new CatalogProduct('Crème', link: self::SHOP.'/produits/creme', image: self::SHOP.'/b.jpg')]);

        $this->assertSame(CatalogItem::IMAGE_PENDING, $two['item']->fresh()->image_status);
        $this->assertNull($two['item']->fresh()->image_path);
    }
}
