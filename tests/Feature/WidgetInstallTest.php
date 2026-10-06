<?php

namespace Tests\Feature;

use App\Ingestion\Crawler\SafeUrl;
use App\Models\Bot;
use App\Models\User;
use App\Support\WidgetInstallCheck;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/** « Je ne vois pas la bulle sur mon site » : le contrôle dit pourquoi, et l'origine ne dépend plus de « www ». */
class WidgetInstallTest extends TestCase
{
    use CreatesTenants;
    use RefreshDatabase;

    private Bot $bot;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['app.url' => 'https://kouma.test']);
        SafeUrl::useResolver(fn () => ['93.184.216.34']);
        [, $this->owner, $this->bot] = $this->tenant('Poupécosmetic', 'pro');
    }

    protected function tearDown(): void
    {
        SafeUrl::useResolver(null);
        parent::tearDown();
    }

    private function page(string $body): string
    {
        return "<html><head><title>Boutique</title></head><body><h1>Bienvenue</h1>{$body}</body></html>";
    }

    private function script(?string $key = null, string $host = 'kouma.test'): string
    {
        return '<script src="https://'.$host.'/widget/widget.js" data-bot="'.($key ?? $this->bot->public_key).'" async></script>';
    }

    private function check(string $html, string $url = 'https://poupecosmetic.com/'): array
    {
        return WidgetInstallCheck::analyze($html, $url, $this->bot->fresh());
    }

    /* ---------- Le contrôle ---------- */

    public function test_a_page_without_the_script_is_reported_with_the_usual_causes(): void
    {
        $result = $this->check($this->page('<script src="/js/poupe.js"></script>'));

        $this->assertSame(WidgetInstallCheck::NOT_FOUND, $result['status']);
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('fichier que cette page n\'utilise pas', $result['help']);
        $this->assertStringContainsString('pas été mis en ligne', $result['help']);
        $this->assertStringContainsString('cache', $result['help']);
    }

    public function test_a_commented_script_is_recognised(): void
    {
        $result = $this->check($this->page('<!-- '.$this->script().' -->'));

        $this->assertSame(WidgetInstallCheck::COMMENTED, $result['status']);
        $this->assertStringContainsString('commentaire', $result['title']);
    }

    public function test_the_script_of_another_assistant_is_recognised(): void
    {
        $result = $this->check($this->page($this->script('pk_autreassistant')));

        $this->assertSame(WidgetInstallCheck::WRONG_KEY, $result['status']);
        $this->assertStringContainsString('pk_autreassistant', $result['help']);
        $this->assertStringContainsString($this->bot->public_key, $result['help']);
    }

    public function test_a_script_copied_from_a_test_computer_is_recognised(): void
    {
        $result = $this->check($this->page('<script src="http://127.0.0.1:8123/widget/widget.js" data-bot="'.$this->bot->public_key.'" async></script>'));

        $this->assertSame(WidgetInstallCheck::WRONG_HOST, $result['status']);
        $this->assertStringContainsString('127.0.0.1:8123', $result['title']);
        $this->assertStringContainsString('https://kouma.test/widget/widget.js', $result['help']);
    }

    public function test_a_disabled_assistant_is_reported(): void
    {
        $this->bot->forceFill(['is_active' => false])->save();

        $this->assertSame(WidgetInstallCheck::INACTIVE, $this->check($this->page($this->script()))['status']);
    }

    public function test_a_site_missing_from_the_allowed_list_is_reported_with_the_exact_address_to_add(): void
    {
        $this->bot->forceFill(['allowed_origins' => ['https://autre-site.com']])->save();

        $result = $this->check($this->page($this->script()), 'https://poupecosmetic.com/produits/duo');

        $this->assertSame(WidgetInstallCheck::ORIGIN_BLOCKED, $result['status']);
        $this->assertStringContainsString('https://poupecosmetic.com', $result['help']);
    }

    public function test_a_correct_installation_is_confirmed(): void
    {
        $result = $this->check($this->page($this->script()));

        $this->assertSame(WidgetInstallCheck::OK, $result['status']);
        $this->assertTrue($result['ok']);

        // Guillemets simples et script sans async : même résultat.
        $this->assertTrue($this->check($this->page("<script src='https://kouma.test/widget/widget.js' data-bot='{$this->bot->public_key}'></script>"))['ok']);
    }

    /* ---------- L'origine ne dépend plus de « www » ---------- */

    public function test_www_and_the_bare_domain_are_the_same_site_for_the_allowed_list(): void
    {
        $this->bot->forceFill(['allowed_origins' => ['https://www.poupecosmetic.com']])->save();
        $bot = $this->bot->fresh();

        $this->assertTrue($bot->allowsOrigin('https://poupecosmetic.com'));
        $this->assertTrue($bot->allowsOrigin('https://www.poupecosmetic.com/'));
        $this->assertFalse($bot->allowsOrigin('http://poupecosmetic.com'), 'http et https restent distincts');
        $this->assertFalse($bot->allowsOrigin('https://autre.com'));

        $this->bot->forceFill(['allowed_origins' => ['https://poupecosmetic.com']])->save();
        $this->assertTrue($this->bot->fresh()->allowsOrigin('https://www.poupecosmetic.com'));
    }

    public function test_the_widget_api_accepts_the_www_variant_of_an_allowed_site(): void
    {
        $this->bot->forceFill(['allowed_origins' => ['https://poupecosmetic.com']])->save();

        $this->withHeaders(['Origin' => 'https://www.poupecosmetic.com'])->getJson('/api/v1/widget/'.$this->bot->public_key.'/config')->assertOk();
        $this->withHeaders(['Origin' => 'https://pirate.com'])->getJson('/api/v1/widget/'.$this->bot->public_key.'/config')->assertForbidden();
    }

    /* ---------- La page Canaux ---------- */

    public function test_the_channels_page_checks_a_live_page_and_shows_the_verdict(): void
    {
        Http::fake(['https://poupecosmetic.com/*' => fn () => Http::response($this->page('<script src="/js/poupe.js"></script>'), 200, ['Content-Type' => 'text/html'])]);

        $this->actingAs($this->owner)->post(route('channels.check-install', $this->bot), ['url' => 'poupecosmetic.com'])->assertRedirect();

        $this->actingAs($this->owner)->get(route('channels.show', $this->bot))->assertOk()
            ->assertSee('Vérifier mon site')->assertSee('Le script n&#039;est pas dans la page que votre site envoie aujourd&#039;hui', false)->assertSee('poupecosmetic.com');

        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake(['https://poupecosmetic.com/*' => fn () => Http::response($this->page($this->script()), 200, ['Content-Type' => 'text/html'])]);
        $this->actingAs($this->owner)->post(route('channels.check-install', $this->bot), ['url' => 'https://poupecosmetic.com/'])->assertRedirect();

        $this->actingAs($this->owner)->get(route('channels.show', $this->bot))->assertOk()->assertSee('Installation correcte');
    }

    public function test_an_unreachable_page_and_an_internal_address_are_refused_politely(): void
    {
        Http::fake(['https://panne.test/*' => fn () => Http::response('', 503)]);

        $this->actingAs($this->owner)->post(route('channels.check-install', $this->bot), ['url' => 'https://panne.test/']);
        $this->actingAs($this->owner)->get(route('channels.show', $this->bot))->assertSee('Impossible d&#039;ouvrir cette page', false)->assertSee('HTTP 503');

        SafeUrl::useResolver(fn () => ['127.0.0.1']);
        $this->actingAs($this->owner)->post(route('channels.check-install', $this->bot), ['url' => 'https://interne.test/'])->assertRedirect();
        $this->actingAs($this->owner)->get(route('channels.show', $this->bot))->assertSee('Impossible d&#039;ouvrir cette page', false)->assertSee('privée', false);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'interne.test'));
    }

    public function test_another_clients_assistant_cannot_be_checked(): void
    {
        [, , $otherBot] = $this->tenant('Autre', 'pro');

        $this->actingAs($this->owner)->post(route('channels.check-install', $otherBot), ['url' => 'https://exemple.com'])->assertNotFound();
    }

    public function test_the_channels_page_shows_when_the_bubble_was_last_loaded_by_a_site(): void
    {
        Cache::flush();
        $this->actingAs($this->owner)->get(route('channels.show', $this->bot))->assertSee('Aucun chargement de la bulle détecté');

        auth()->logout();
        $this->withHeaders(['Origin' => 'https://poupecosmetic.com'])->getJson('/api/v1/widget/'.$this->bot->public_key.'/config')->assertOk();

        $this->actingAs($this->owner)->get(route('channels.show', $this->bot))->assertSee('Dernier chargement de la bulle')->assertSee('https://poupecosmetic.com');
    }

    public function test_the_widget_writes_the_reason_in_the_console_when_it_cannot_show(): void
    {
        $script = (string) file_get_contents(public_path('widget/widget.js'));

        $this->assertStringContainsString("[Kouma] La bulle de discussion ne s\\'affiche pas", $script);
        $this->assertStringContainsString('origin_not_allowed', $script);
    }
}
