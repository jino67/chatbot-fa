<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/** Le changement de nom de domaine : les anciens liens redirigent, ce que les programmes appellent reste servi. */
class DomainChangeTest extends TestCase
{
    use CreatesTenants;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['app.url' => 'https://kouma-ai.com', 'platform.legacy_hosts' => ['kouma.site']]);
    }

    private function legacy(string $path = '/', string $host = 'kouma.site')
    {
        return $this->call('GET', 'https://'.$host.$path);
    }

    public function test_pages_on_the_old_domain_redirect_to_the_new_one_keeping_the_path_and_query(): void
    {
        $this->legacy('/ressources?devise=EUR')->assertStatus(301)->assertRedirect('https://kouma-ai.com/ressources?devise=EUR');
        $this->legacy('/')->assertStatus(301)->assertRedirect('https://kouma-ai.com/');
        $this->legacy('/login', 'www.kouma.site')->assertStatus(301)->assertRedirect('https://kouma-ai.com/login');
    }

    public function test_the_shared_chat_links_and_printed_qr_codes_keep_working(): void
    {
        [, , $bot] = $this->tenant('Boutique Awa');

        $this->legacy('/chat/'.$bot->public_key)->assertStatus(301)->assertRedirect('https://kouma-ai.com/chat/'.$bot->public_key);
    }

    public function test_what_programs_and_installed_apps_call_is_still_served_on_the_old_domain(): void
    {
        [, , $bot] = $this->tenant('Boutique Awa');

        foreach (['/sw.js', '/manifest.webmanifest', '/up', '/api/v1/widget/'.$bot->public_key.'/config'] as $path) {
            $response = $this->legacy($path);
            $this->assertNotContains($response->getStatusCode(), [301, 302], "{$path} ne doit pas être redirigé");
        }
        $this->legacy('/webhooks/whatsapp/meta')->assertStatus(403);
        // Le widget des sites de vos clients : son adresse reste celle de l'ancien domaine tant que le site existe.
        $this->assertNotContains($this->legacy('/widget/widget.js')->getStatusCode(), [301, 302]);
    }

    public function test_only_reads_are_redirected_never_forms(): void
    {
        $this->assertNotSame(301, $this->call('POST', 'https://kouma.site/login', ['email' => 'x@y.test', 'password' => 'secret'])->getStatusCode());
        $this->assertNotSame(301, $this->call('POST', 'https://kouma.site/login')->getStatusCode());
    }

    public function test_nothing_is_redirected_without_an_old_domain_or_on_the_official_one(): void
    {
        $this->call('GET', 'https://kouma-ai.com/ressources')->assertOk();

        config(['platform.legacy_hosts' => []]);
        $this->legacy('/ressources')->assertOk();

        // Une adresse officielle locale ne redirige rien : pas de boucle ni de redirection vers localhost.
        config(['platform.legacy_hosts' => ['kouma.site'], 'app.url' => 'http://localhost:8123']);
        $this->legacy('/ressources')->assertOk();
    }

    public function test_the_official_domain_and_its_old_name_are_both_allowed_origins_for_the_widget(): void
    {
        [, , $bot] = $this->tenant('Boutique Awa');
        $bot->update(['allowed_origins' => ['https://www.boutique.com']]);
        app(\App\Services\PlatformSettings::class)->set('brand.url', 'https://kouma-ai.com');

        foreach (['https://kouma-ai.com', 'https://kouma.site', 'https://www.kouma.site'] as $origin) {
            $this->assertTrue($bot->allowsOrigin($origin), $origin);
        }
        $this->assertFalse($bot->allowsOrigin('https://evil.example'));
        $this->assertFalse($bot->allowsOrigin('https://kouma.site.evil.example'));

        config(['platform.legacy_hosts' => []]);
        $this->assertFalse($bot->allowsOrigin('https://kouma.site'), 'sans ancien nom déclaré, il n\'est plus accepté');
    }
}
