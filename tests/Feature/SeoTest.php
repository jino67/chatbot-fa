<?php

namespace Tests\Feature;

use App\Services\PlatformSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Référencement : données structurées valides, et graphies « kuma, couma, cuma, koumah » tant que la marque reste Kouma. */
class SeoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    /** @return list<array<string,mixed>> */
    private function structuredData(string $html): array
    {
        preg_match_all('#<script type="application/ld\+json">\s*(.*?)\s*</script>#s', $html, $matches);

        return array_map(fn ($json) => json_decode($json, true, 512, JSON_THROW_ON_ERROR), $matches[1]);
    }

    private function ofType(array $blocks, string $type): array
    {
        return collect($blocks)->firstWhere('@type', $type) ?? [];
    }

    public function test_every_structured_data_block_is_valid_json_with_its_schema_context(): void
    {
        $blocks = $this->structuredData($this->get('/')->assertOk()->getContent());

        $this->assertCount(4, $blocks);
        foreach ($blocks as $block) {
            $this->assertSame('https://schema.org', $block['@context'], 'Blade ne doit pas lire « @context » comme une directive');
        }
        $this->assertSame(['SoftwareApplication', 'WebSite', 'Organization', 'FAQPage'], array_column($blocks, '@type'));
    }

    public function test_the_default_brand_declares_all_its_spellings(): void
    {
        $blocks = $this->structuredData($this->get('/')->getContent());

        $this->assertSame(['Kuma', 'Couma', 'Cuma', 'Koumah'], $this->ofType($blocks, 'WebSite')['alternateName']);
        $this->assertSame(['Kuma', 'Couma', 'Cuma', 'Koumah'], $this->ofType($blocks, 'SoftwareApplication')['alternateName']);

        $questions = array_column($this->ofType($blocks, 'FAQPage')['mainEntity'], 'name');
        $this->assertContains("Comment s'écrit Kouma ?", $questions);
    }

    public function test_the_page_carries_the_spellings_in_its_keywords_and_footer(): void
    {
        $response = $this->get('/')->assertOk();

        $response->assertSee('<meta name="keywords" content="kouma, kuma, couma, cuma, koumah', false);
        $response->assertSee('On l\'écrit aussi Kuma, Couma, Cuma ou Koumah.', false);
        $response->assertSee('la parole en bambara et en dioula', false);
    }

    public function test_a_renamed_brand_does_not_claim_the_kouma_spellings(): void
    {
        app(PlatformSettings::class)->set('brand.name', 'Sanaya');

        $response = $this->get('/')->assertOk();
        $html = $response->getContent();
        $blocks = $this->structuredData($html);

        $this->assertArrayNotHasKey('alternateName', $this->ofType($blocks, 'WebSite'));
        $this->assertArrayNotHasKey('alternateName', $this->ofType($blocks, 'SoftwareApplication'));
        $this->assertNotContains("Comment s'écrit Sanaya ?", array_column($this->ofType($blocks, 'FAQPage')['mainEntity'], 'name'));

        foreach (['Kuma', 'Couma', 'Cuma', 'Koumah'] as $spelling) {
            $this->assertStringNotContainsString($spelling, $html, "« {$spelling} » ne doit plus apparaître pour une autre marque");
        }
    }

    public function test_the_sitemap_and_robots_point_to_the_public_pages(): void
    {
        $this->get('/sitemap.xml')->assertOk()->assertSee(url('/'), false)->assertSee(route('developers'), false);
        $this->get('/robots.txt')->assertOk()->assertSee('Sitemap:', false);
    }

    public function test_the_public_pages_have_a_title_and_a_canonical_address(): void
    {
        foreach (['/', '/developpeurs'] as $path) {
            $this->get($path)->assertOk()->assertSee('<title>', false)->assertSee('rel="canonical"', false);
        }
    }
}
