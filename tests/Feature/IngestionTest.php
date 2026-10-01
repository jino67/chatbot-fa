<?php

namespace Tests\Feature;

use App\Ingestion\Crawler\SafeUrl;
use App\Ingestion\IngestionPipeline;
use App\Models\Chunk;
use App\Models\Source;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;
use ZipArchive;

class IngestionTest extends TestCase
{
    use CreatesTenants;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
        SafeUrl::useResolver(fn () => ['93.184.216.34']);
    }

    protected function tearDown(): void
    {
        SafeUrl::useResolver(null);
        parent::tearDown();
    }

    public function test_a_text_source_is_chunked_and_embedded(): void
    {
        [, , $bot] = $this->tenant();

        $source = $this->teach($bot, 'Horaires', "# Horaires\nOuvert du lundi au samedi de 8 h à 19 h.");

        $this->assertSame(Source::READY, $source->status);
        $this->assertSame(1, $source->stats['pages']);
        $chunk = Chunk::withoutGlobalScopes()->where('source_id', $source->id)->first();
        $this->assertNotNull($chunk->embedding);
        $this->assertStringStartsWith('hashing-', $chunk->embedding_model);
    }

    public function test_reindexing_a_source_replaces_its_chunks_instead_of_duplicating_them(): void
    {
        [, , $bot] = $this->tenant();
        $source = $this->teach($bot, 'Tarifs', 'Le foulard en soie coûte 6 500 FCFA et le sac 22 000 FCFA.');
        $before = Chunk::withoutGlobalScopes()->where('source_id', $source->id)->count();

        app(IngestionPipeline::class)->run($source);

        $this->assertSame($before, Chunk::withoutGlobalScopes()->where('source_id', $source->id)->count());
    }

    public function test_uploading_a_text_file_indexes_it(): void
    {
        [, $user, $bot] = $this->tenant();

        $this->actingAs($user)->post(route('sources.store', $bot), [
            'type' => 'file',
            'file' => UploadedFile::fake()->createWithContent('conditions.txt', "Livraison gratuite dès 25 000 FCFA d'achat à Ouagadougou."),
        ])->assertSessionHasNoErrors();

        $source = $bot->sources()->firstOrFail();
        $this->assertSame(Source::READY, $source->status, (string) $source->error);
        $this->assertSame('conditions.txt', $source->name);
        // Stocke sous un nom aleatoire : jamais le nom fourni par l'utilisateur.
        $this->assertStringNotContainsString('conditions', $source->payload['path']);
        Storage::disk('local')->assertExists($source->payload['path']);
    }

    public function test_dangerous_uploads_are_rejected(): void
    {
        [, $user, $bot] = $this->tenant();

        $this->actingAs($user)->post(route('sources.store', $bot), [
            'type' => 'file',
            'file' => UploadedFile::fake()->createWithContent('virus.php', '<?php system($_GET["c"]);'),
        ])->assertSessionHasErrors('file');

        $this->assertSame(0, $bot->sources()->count());
    }

    public function test_csv_price_lists_become_one_readable_line_per_item(): void
    {
        [, $user, $bot] = $this->tenant();

        $this->actingAs($user)->post(route('sources.store', $bot), [
            'type' => 'file',
            'file' => UploadedFile::fake()->createWithContent('prix.csv', "Article;Prix;Taille\nRobe wax;18000;M\nFoulard;6500;"),
        ]);

        $content = $bot->sources()->firstOrFail()->documents()->firstOrFail()->content;
        // Les colonnes Article et Prix sont reconnues : une phrase par produit, prix lisible, colonne en plus gardée.
        $this->assertStringContainsString("Robe wax : 18\u{202F}000 FCFA. Taille : M.", $content);
        $this->assertStringContainsString("Foulard : 6\u{202F}500 FCFA.", $content);
    }

    public function test_a_word_document_keeps_headings_and_tables(): void
    {
        [, $user, $bot] = $this->tenant();
        $path = tempnam(sys_get_temp_dir(), 'docx');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);
        $zip->addFromString('word/document.xml', <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>
<w:p><w:pPr><w:pStyle w:val="Heading1"/></w:pPr><w:r><w:t>Tarifs coiffure</w:t></w:r></w:p>
<w:p><w:r><w:t>Nos prix sont indiqués en FCFA.</w:t></w:r></w:p>
<w:tbl><w:tr><w:tc><w:p><w:r><w:t>Tresses</w:t></w:r></w:p></w:tc><w:tc><w:p><w:r><w:t>8000</w:t></w:r></w:p></w:tc></w:tr></w:tbl>
</w:body></w:document>
XML);
        $zip->close();

        $this->actingAs($user)->post(route('sources.store', $bot), [
            'type' => 'file',
            'file' => new UploadedFile($path, 'tarifs.docx', null, null, true),
        ])->assertSessionHasNoErrors();

        $source = $bot->sources()->firstOrFail();
        $this->assertSame(Source::READY, $source->status, (string) $source->error);
        $content = $source->documents()->firstOrFail()->content;
        $this->assertStringContainsString('# Tarifs coiffure', $content);
        $this->assertStringContainsString('Tresses | 8000', $content);
    }

    public function test_website_crawl_follows_internal_links_and_ignores_the_rest(): void
    {
        [, $user, $bot] = $this->tenant();

        Http::fake([
            'https://boutique.exemple.com/robots.txt' => Http::response('', 404),
            'https://boutique.exemple.com/sitemap.xml' => Http::response('', 404),
            'https://boutique.exemple.com/' => Http::response('<html><head><title>Accueil</title></head><body><h1>Boutique Awa</h1><p>Robes et boubous artisanaux, faits main à Ouagadougou depuis 2015.</p><a href="/contact">Contact</a><a href="https://autre-site.com/x">Externe</a><script>alert(1)</script></body></html>', 200, ['Content-Type' => 'text/html']),
            'https://boutique.exemple.com/contact' => Http::response('<html><head><title>Contact</title></head><body><h1>Contact</h1><p>Appelez-nous au <a href="tel:+22670000000">70 00 00 00</a>, du lundi au samedi.</p></body></html>', 200, ['Content-Type' => 'text/html']),
        ]);

        $this->actingAs($user)->post(route('sources.store', $bot), [
            'type' => 'url', 'url' => 'https://boutique.exemple.com', 'mode' => 'site',
        ])->assertSessionHasNoErrors();

        $source = $bot->sources()->firstOrFail();
        $this->assertSame(Source::READY, $source->status, (string) $source->error);
        $this->assertSame(2, $source->stats['pages']);
        $text = $source->documents->pluck('content')->implode("\n");
        $this->assertStringContainsString('Robes et boubous artisanaux', $text);
        $this->assertStringContainsString('+22670000000', $text, 'le telephone du lien tel: est conserve');
        $this->assertStringNotContainsString('alert(1)', $text);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'autre-site.com'));
    }

    public function test_robots_txt_that_forbids_crawling_is_respected(): void
    {
        [, $user, $bot] = $this->tenant();

        Http::fake([
            'https://ferme.exemple.com/robots.txt' => Http::response("User-agent: *\nDisallow: /", 200),
            '*' => Http::response('<html><body>ne doit jamais etre lu</body></html>', 200, ['Content-Type' => 'text/html']),
        ]);

        $this->actingAs($user)->post(route('sources.store', $bot), ['type' => 'url', 'url' => 'https://ferme.exemple.com', 'mode' => 'site']);

        $source = $bot->sources()->firstOrFail();
        $this->assertSame(Source::FAILED, $source->status);
        $this->assertStringContainsString('robots.txt', $source->error);
        Http::assertNotSent(fn ($request) => $request->url() === 'https://ferme.exemple.com');
    }

    public function test_internal_addresses_are_refused_before_anything_is_created(): void
    {
        [, $user, $bot] = $this->tenant();
        Http::fake();

        foreach (['http://127.0.0.1/', 'http://169.254.169.254/latest/meta-data/', 'http://localhost/admin'] as $url) {
            $this->actingAs($user)->post(route('sources.store', $bot), ['type' => 'url', 'url' => $url, 'mode' => 'page'])
                ->assertSessionHas('error');
        }

        $this->assertSame(0, $bot->sources()->count());
        Http::assertNothingSent();
    }

    public function test_social_networks_are_never_crawled_but_the_link_is_kept_and_completed_by_the_client(): void
    {
        [, $user, $bot] = $this->tenant();
        Http::fake();

        $this->actingAs($user)->post(route('sources.store', $bot), ['type' => 'url', 'url' => 'https://www.facebook.com/boutique.awa', 'mode' => 'page'])
            ->assertSessionHas('status')->assertSessionHas('focus_source');

        $source = $bot->sources()->firstOrFail();
        $this->assertSame(Source::NEEDS_CONTENT, $source->status);
        $this->assertTrue($source->needsContent());
        Http::assertNothingSent();

        // Le client colle ensuite le contenu de sa page : la source est alors indexee.
        $this->actingAs($user)->put(route('sources.content', [$bot, $source]), [
            'content' => 'À propos : boutique de mode africaine. Promotion de rentrée : -10 % sur les foulards jusqu\'au 30 octobre.',
        ])->assertSessionHasNoErrors();

        $this->assertSame(Source::READY, $source->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_pasted_facebook_content_is_indexed(): void
    {
        [, $user, $bot] = $this->tenant();

        $this->actingAs($user)->post(route('sources.store', $bot), [
            'type' => 'facebook', 'title' => 'Page Facebook', 'page_url' => 'https://www.facebook.com/boutique.awa',
            'content' => 'À propos : Boutique de mode africaine. Promotion de rentrée : -10 % sur les foulards jusqu\'au 30 octobre.',
        ])->assertSessionHasNoErrors();

        $source = $bot->sources()->firstOrFail();
        $this->assertSame(Source::READY, $source->status);
        $this->assertSame('https://www.facebook.com/boutique.awa', $source->documents->first()->url);
    }

    public function test_plan_limits_on_sources_are_enforced(): void
    {
        [, $user, $bot] = $this->tenant('Petit client', 'free');

        for ($i = 1; $i <= 5; $i++) {
            $this->teach($bot, "Source {$i}", "Contenu numéro {$i} suffisamment long pour être indexé.");
        }

        $this->actingAs($user)->post(route('sources.store', $bot), [
            'type' => 'text', 'title' => 'Sixième', 'content' => 'Une sixième source qui dépasse la limite du plan gratuit.',
        ])->assertSessionHas('error');

        $this->assertSame(5, $bot->sources()->count());
    }

    public function test_a_source_can_be_deleted_with_its_chunks_and_file(): void
    {
        [, $user, $bot] = $this->tenant();
        $this->actingAs($user)->post(route('sources.store', $bot), [
            'type' => 'file', 'file' => UploadedFile::fake()->createWithContent('a.txt', 'Contenu suffisamment long pour être découpé et indexé correctement.'),
        ]);
        $source = $bot->sources()->firstOrFail();
        $path = $source->payload['path'];

        $this->delete(route('sources.destroy', [$bot, $source]))->assertRedirect();

        $this->assertDatabaseCount('chunks', 0);
        Storage::disk('local')->assertMissing($path);
    }
}
