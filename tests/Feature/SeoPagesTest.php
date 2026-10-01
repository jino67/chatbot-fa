<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Services\PlatformSettings;
use App\Support\SeoPages;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/** Pages de contenu pour le référencement (config/seo.php), plan du site, robots.txt et llms.txt. */
class SeoPagesTest extends TestCase
{
    use CreatesTenants;
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

    private function meta(string $html, string $pattern): string
    {
        $this->assertSame(1, preg_match($pattern, $html, $m), 'balise absente : '.$pattern);

        return html_entity_decode($m[1], ENT_QUOTES);
    }

    public function test_the_configuration_is_consistent(): void
    {
        $pages = SeoPages::all();
        $this->assertGreaterThanOrEqual(20, count($pages));

        $paths = array_column($pages, 'path');
        $this->assertSame($paths, array_unique($paths), 'chaque page a une adresse unique');

        foreach ($pages as $key => $page) {
            $this->assertArrayHasKey($page['type'], config('seo.groups'), $key);
            $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $page['updated'], $key);
            $this->assertLessThanOrEqual(now()->toDateString(), $page['updated'], "{$key} : une date de mise à jour dans le futur est ignorée par les moteurs");
            foreach ($page['related'] ?? [] as $related) {
                $this->assertArrayHasKey($related, $pages, "{$key} renvoie vers une page qui n'existe pas : {$related}");
                $this->assertNotSame($key, $related, "{$key} ne doit pas se citer lui-même");
            }
            if ($page['type'] === 'sector') {
                $this->assertArrayHasKey($page['sector'], config('sectors'), "{$key} : métier inconnu");
            }
        }

        $this->assertStringNotContainsString("\u{2014}", file_get_contents(config_path('seo.php')), 'aucun tiret cadratin');
    }

    public function test_every_page_is_well_formed_for_search_engines(): void
    {
        $titles = $descriptions = [];

        foreach (SeoPages::all() as $key => $page) {
            $html = $this->get('/'.$page['path'])->assertOk()->getContent();

            $title = $this->meta($html, '#<title>(.*?)</title>#s');
            $description = $this->meta($html, '#<meta name="description" content="(.*?)">#');

            $this->assertLessThanOrEqual(70, mb_strlen($title), "{$key} : titre trop long pour s'afficher en entier");
            $this->assertGreaterThanOrEqual(30, mb_strlen($title), $key);
            $this->assertGreaterThanOrEqual(70, mb_strlen($description), "{$key} : description trop courte");
            $this->assertLessThanOrEqual(165, mb_strlen($description), "{$key} : description trop longue");
            $this->assertSame(1, substr_count($html, '<h1'), "{$key} : un seul titre principal");
            $this->assertStringContainsString('<link rel="canonical" href="'.url($page['path']).'">', $html, $key);
            $this->assertStringContainsString('index, follow', $html, $key);
            $this->assertStringContainsString('<meta property="og:image" content="'.url('og-image.png').'">', $html, $key);
            $this->assertStringContainsString('summary_large_image', $html, $key);
            $this->assertStringContainsString('<html lang="fr">', $html, $key);

            // Aucun jeton oublié, aucun tiret cadratin visible.
            $this->assertDoesNotMatchRegularExpression('/\{(brand|trial_days|price:|limit:)/', $html, "{$key} : jeton non remplacé");
            $this->assertStringNotContainsString("\u{2014}", $html, $key);
            $this->assertStringNotContainsString('(voir les tarifs)', $html, "{$key} : offre introuvable");

            $titles[$key] = $title;
            $descriptions[$key] = $description;
        }

        $this->assertSame($titles, array_unique($titles), 'deux pages ne partagent pas le même titre');
        $this->assertSame($descriptions, array_unique($descriptions), 'deux pages ne partagent pas la même description');
    }

    public function test_structured_data_is_valid_and_matches_the_kind_of_page(): void
    {
        foreach (SeoPages::all() as $key => $page) {
            $blocks = $this->structuredData($this->get('/'.$page['path'])->getContent());
            $types = array_column($blocks, '@type');

            $this->assertContains('BreadcrumbList', $types, $key);
            $this->assertContains($page['type'] === 'guide' ? 'Article' : 'WebPage', $types, $key);
            $this->assertSame(! empty($page['faq']), in_array('FAQPage', $types, true), $key);

            foreach ($blocks as $block) {
                $this->assertSame('https://schema.org', $block['@context'], $key);
            }

            $crumbs = collect($blocks)->firstWhere('@type', 'BreadcrumbList')['itemListElement'];
            $this->assertSame(['Accueil', 'Ressources'], array_column(array_slice($crumbs, 0, 2), 'name'), $key);
            $this->assertSame(url($page['path']), end($crumbs)['item'], $key);
        }
    }

    public function test_prices_and_quotas_come_from_the_plans_and_follow_their_changes(): void
    {
        $this->get('/guides/combien-coute-un-chatbot-whatsapp')->assertOk()
            ->assertSee("25\u{202F}000 FCFA", false)
            ->assertSee('2'."\u{202F}".'000 réponses', false);

        $plan = Plan::where('slug', 'bonplan')->firstOrFail();
        $plan->prices = ['XOF' => 30000] + $plan->prices;
        $plan->limits = ['messages_per_month' => 2500] + $plan->limits;
        $plan->save();

        $this->get('/guides/combien-coute-un-chatbot-whatsapp')->assertOk()
            ->assertSee("30\u{202F}000 FCFA", false)
            ->assertSee('2'."\u{202F}".'500 réponses', false)
            ->assertDontSee("25\u{202F}000 FCFA", false);
    }

    public function test_the_visitors_currency_does_not_change_the_indexed_prices(): void
    {
        $this->withSession(['currency' => 'EUR'])->get('/guides/combien-coute-un-chatbot-whatsapp')->assertOk()
            ->assertSee("25\u{202F}000 FCFA", false)
            ->assertDontSee('38 €', false);
    }

    public function test_country_pages_show_the_currency_of_their_country(): void
    {
        $this->get('/chatbot-whatsapp-comores')->assertOk()->assertSee("18\u{202F}750 KMF", false);
        $this->get('/chatbot-whatsapp-maroc')->assertOk()->assertSee("410 DH", false);
        $this->get('/chatbot-whatsapp-burkina-faso')->assertOk()->assertSee("25\u{202F}000 FCFA", false);
    }

    public function test_sector_pages_reuse_the_sector_sheet(): void
    {
        $this->get('/chatbot-whatsapp-boutique')->assertOk()
            ->assertSee('Exemple de conversation')
            ->assertSee('Ce que fait votre assistant')
            ->assertSee('Combien ça coûte ?')
            ->assertSee(config('sectors.commerce.objectifs.0'), false);
    }

    public function test_the_brand_name_follows_the_platform_settings(): void
    {
        app(PlatformSettings::class)->set('brand.name', 'Sanaya');

        $this->get('/chatbot-whatsapp')->assertOk()->assertSee('Sanaya')->assertDontSee('Kouma');
    }

    public function test_the_hub_lists_every_page(): void
    {
        $response = $this->get('/ressources')->assertOk();

        foreach (SeoPages::all() as $page) {
            $response->assertSee('href="'.url($page['path']).'"', false);
        }
        $response->assertSee('CollectionPage', false);
    }

    public function test_the_sitemap_lists_every_public_page_with_a_modification_date(): void
    {
        $xml = simplexml_load_string($this->get('/sitemap.xml')->assertOk()->getContent());
        $this->assertNotFalse($xml);

        $urls = [];
        foreach ($xml->url as $url) {
            $urls[(string) $url->loc] = (string) $url->lastmod;
            $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', (string) $url->lastmod);
        }

        foreach ([url('/'), route('seo.hub'), route('register'), route('developers'), route('legal.terms'), route('legal.privacy')] as $address) {
            $this->assertArrayHasKey($address, $urls);
        }
        foreach (SeoPages::all() as $page) {
            $this->assertArrayHasKey(url($page['path']), $urls);
        }
        $this->assertArrayNotHasKey(route('login'), $urls, 'la page de connexion n\'est pas à indexer');
        $this->assertSame(array_keys($urls), array_unique(array_keys($urls)));
    }

    public function test_robots_blocks_private_areas_and_announces_the_sitemap(): void
    {
        $robots = $this->get('/robots.txt')->assertOk()->getContent();

        foreach (['/admin', '/dashboard', '/bots', '/billing', '/profile', '/api/', '/webhooks/', '/demo/'] as $path) {
            $this->assertStringContainsString('Disallow: '.$path, $robots);
        }
        $this->assertStringContainsString('Sitemap: '.url('/sitemap.xml'), $robots);
        $this->assertStringNotContainsString('Disallow: /ressources', $robots);
        $this->assertStringNotContainsString('Disallow: /login', $robots);
    }

    public function test_no_static_file_hides_the_dynamic_robots_and_the_favicon_is_a_real_icon(): void
    {
        // Un public/robots.txt servirait à la place de la route : sans plan du site ni blocage des espaces privés.
        $this->assertFileDoesNotExist(public_path('robots.txt'));

        $icon = file_get_contents(public_path('favicon.ico'));
        $this->assertGreaterThan(200, strlen($icon), 'un favicon.ico vide donne une icône cassée');
        $this->assertSame([0, 1], array_values(array_slice(unpack('vreserved/vtype', $icon), 0, 2)), 'format ICO');
    }

    public function test_in_production_absolute_addresses_come_from_app_url_not_from_the_host_header(): void
    {
        $this->app['env'] = 'production';
        config(['app.url' => 'https://exemple.test']);
        (new \App\Providers\AppServiceProvider($this->app))->boot();

        try {
            $robots = $this->withServerVariables(['HTTP_HOST' => 'pirate.test'])->get('/robots.txt')->assertOk()->getContent();
            $this->assertStringContainsString('Sitemap: https://exemple.test/sitemap.xml', $robots);
            $this->assertStringNotContainsString('pirate.test', $robots);

            $home = $this->withServerVariables(['HTTP_HOST' => 'pirate.test'])->get('/')->getContent();
            $this->assertStringContainsString('<link rel="canonical" href="https://exemple.test">', $home);
            $this->assertStringNotContainsString('pirate.test', $home);
        } finally {
            \Illuminate\Support\Facades\URL::forceRootUrl(null);
            \Illuminate\Support\Facades\URL::forceScheme(null);
        }
    }
    public function test_llms_txt_summarises_the_site_for_ai_assistants(): void
    {
        $text = $this->get('/llms.txt')->assertOk()->getContent();

        $this->assertStringStartsWith('# Kouma', $text);
        $this->assertStringContainsString(url('/chatbot-whatsapp'), $text);
        $this->assertStringContainsString(url('/guides/combien-coute-un-chatbot-whatsapp'), $text);
        $this->assertStringNotContainsString("\u{2014}", $text);
    }

    public function test_the_landing_page_links_to_the_content_pages(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        foreach (['chatbot-whatsapp', 'chatbot-whatsapp-boutique', 'chatbot-whatsapp-restaurant', 'guides/combien-coute-un-chatbot-whatsapp', 'chatbot-whatsapp-burkina-faso'] as $path) {
            $this->assertStringContainsString('href="'.url($path).'"', $html, $path);
        }
        $this->assertStringContainsString('href="'.route('seo.hub').'"', $html);
        $this->assertMatchesRegularExpression('#<title>[^<]*chatbot WhatsApp[^<]*</title>#u', $html);
        $this->assertStringContainsString('summary_large_image', $html);
        $this->assertStringContainsString('"@type":"Organization"', str_replace(' ', '', $html));
    }

    public function test_private_and_account_pages_stay_out_of_search_results(): void
    {
        [, $owner] = $this->tenant();

        $this->assertStringContainsString('noindex', $this->get('/login')->assertOk()->getContent());

        $register = $this->get('/register')->assertOk()->getContent();
        $this->assertStringNotContainsString('noindex', $register);
        $this->assertStringContainsString('<link rel="canonical" href="'.url('/register').'">', $register);
        $this->assertStringContainsString('name="description"', $register);

        $this->assertStringContainsString('noindex', $this->actingAs($owner)->get(route('dashboard'))->assertOk()->getContent());
    }

    public function test_the_legal_and_developer_pages_have_their_own_description(): void
    {
        foreach (['/conditions', '/confidentialite', '/developpeurs'] as $path) {
            $html = $this->get($path)->assertOk()->getContent();
            $this->assertStringContainsString('name="description"', $html, $path);
            $this->assertStringContainsString('rel="canonical"', $html, $path);
            $this->assertStringContainsString('og:image', $html, $path);
        }
    }

    public function test_there_is_no_whatsapp_button_without_a_commercial_number(): void
    {
        // Un lien wa.me sans destinataire ne mènerait nulle part.
        $this->get('/')->assertOk()->assertDontSee('wa.me/', false)->assertDontSee('Écrivez-nous sur WhatsApp');
    }

    public function test_the_env_fallback_number_shows_the_whatsapp_buttons(): void
    {
        config(['brand.whatsapp' => '+226 70 00 00 00']);

        $this->get('/')->assertOk()->assertSee('https://wa.me/22670000000', false)->assertSee('Écrivez-nous sur WhatsApp');
        $this->get('/chatbot-whatsapp')->assertOk()->assertSee('https://wa.me/22670000000', false);
    }

    public function test_the_number_set_in_the_admin_wins_over_the_env_fallback(): void
    {
        config(['brand.whatsapp' => '+226 70 00 00 00']);
        app(PlatformSettings::class)->set('brand.whatsapp', '+225 07 00 00 00');

        $this->get('/')->assertOk()->assertSee('https://wa.me/22507000000', false)->assertDontSee('22670000000', false);
    }

    public function test_the_contact_email_comes_from_the_env_until_the_admin_sets_one(): void
    {
        config(['brand.email' => 'contac@kouma.site']);

        $this->get('/')->assertOk()->assertSee('mailto:contac@kouma.site', false);
        $this->get('/conditions')->assertOk()->assertSee('contac@kouma.site');
    }

    public function test_an_unknown_page_is_a_clean_404(): void
    {
        $this->get('/chatbot-whatsapp-lune')->assertNotFound();
    }
}
