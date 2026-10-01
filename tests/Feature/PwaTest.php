<?php

namespace Tests\Feature;

use App\Services\PlatformSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/** Application installable : manifeste, icônes, page hors connexion, invitation à « ajouter à l'écran d'accueil ». */
class PwaTest extends TestCase
{
    use CreatesTenants;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_the_manifest_describes_an_installable_standalone_app(): void
    {
        $response = $this->get('/manifest.webmanifest')->assertOk();

        $this->assertStringContainsString('application/manifest+json', $response->headers->get('Content-Type'));
        $manifest = $response->json();

        $this->assertSame('Kouma', $manifest['name']);
        $this->assertSame('standalone', $manifest['display']);
        $this->assertSame('/dashboard?source=app', $manifest['start_url']);
        $this->assertSame('#2340D9', $manifest['theme_color']);
        $this->assertSame('fr', $manifest['lang']);

        $purposes = collect($manifest['icons'])->pluck('purpose')->all();
        $this->assertContains('maskable', $purposes);
        foreach ($manifest['icons'] as $icon) {
            $this->assertFileExists(public_path(ltrim($icon['src'], '/')), 'l\'icône '.$icon['src'].' doit exister');
        }
    }

    public function test_the_manifest_follows_the_brand_name(): void
    {
        app(PlatformSettings::class)->set('brand.name', 'Sanaya');

        $manifest = $this->get('/manifest.webmanifest')->json();

        $this->assertSame('Sanaya', $manifest['name']);
        $this->assertSame('Sanaya', $manifest['short_name']);
    }

    public function test_the_icons_have_the_sizes_each_platform_expects(): void
    {
        foreach (['apple-touch-icon.png' => [180, 180], 'icon-192.png' => [192, 192], 'icon-512.png' => [512, 512], 'icon-maskable-512.png' => [512, 512], 'og-image.png' => [1200, 630]] as $file => $size) {
            [$w, $h] = getimagesize(public_path($file));
            $this->assertSame($size, [$w, $h], $file);
        }
    }

    public function test_every_kind_of_page_declares_the_icons_and_the_manifest(): void
    {
        [, $owner] = $this->tenant();

        $pages = [
            'accueil' => $this->get('/')->assertOk()->getContent(),
            'connexion' => $this->get('/login')->assertOk()->getContent(),
            'developpeurs' => $this->get('/developpeurs')->assertOk()->getContent(),
            'espace client' => $this->actingAs($owner)->get(route('dashboard'))->assertOk()->getContent(),
        ];

        foreach ($pages as $name => $html) {
            $this->assertStringContainsString('rel="apple-touch-icon"', $html, $name);
            $this->assertStringContainsString('rel="manifest"', $html, $name);
            $this->assertStringContainsString('name="theme-color"', $html, $name);
            $this->assertStringContainsString('apple-mobile-web-app-capable', $html, $name);
        }
    }

    public function test_the_connected_space_invites_to_install_with_the_ios_steps(): void
    {
        [, $owner] = $this->tenant();

        $this->actingAs($owner)->get(route('dashboard'))->assertOk()
            ->assertSee('écran d\'accueil', false)
            ->assertSee('Sur l\'écran d\'accueil', false)
            ->assertSee('Partager')
            ->assertSee('installHint(', false)
            ->assertSee('Ne plus afficher');

        // L'invitation ne s'affiche pas aux visiteurs de la page d'accueil.
        $this->get('/')->assertOk()->assertDontSee('installHint(', false);
    }

    public function test_opening_the_app_reports_the_installation_once_and_stops_the_invitation(): void
    {
        [, $owner] = $this->tenant();
        $this->assertNull($owner->pwa_installed_at);

        // Un visiteur non connecté ne peut pas signaler d'installation.
        $this->post(route('pwa.installed'))->assertRedirect(route('login'));

        $this->actingAs($owner)->post(route('pwa.installed'))->assertNoContent();
        $first = $owner->fresh()->pwa_installed_at;
        $this->assertNotNull($first);

        // Un second signalement ne change pas la date du premier.
        $this->travel(3)->days();
        $this->actingAs($owner)->post(route('pwa.installed'))->assertNoContent();
        $this->assertEquals($first->timestamp, $owner->fresh()->pwa_installed_at->timestamp);

        // Plus d'invitation pour cette personne, sur aucun appareil.
        $this->actingAs($owner->fresh())->get(route('dashboard'))->assertOk()->assertDontSee('installHint(', false)->assertDontSee('écran d\'accueil', false);
    }

    public function test_the_invitation_is_gentle_and_the_app_reports_itself_when_opened_as_an_app(): void
    {
        [, $owner] = $this->tenant();

        $html = $this->actingAs($owner)->get(route('dashboard'))->assertOk()->getContent();

        // Une fois par visite, quatre apparitions au plus, report au serveur en mode application.
        foreach (['kouma-install-seen', 'saved.seen >= 4', 'display-mode: standalone', 'appinstalled', route('pwa.installed', [], false), 'Plus tard', 'Ne plus afficher'] as $needle) {
            $this->assertStringContainsString($needle, str_replace('\/', '/', $html), $needle);
        }
    }

    public function test_the_service_worker_only_handles_navigations_and_never_caches_pages(): void
    {
        $worker = file_get_contents(public_path('sw.js'));

        $this->assertStringContainsString("request.mode !== 'navigate'", $worker);
        $this->assertStringContainsString('/api/', $worker);
        $this->assertStringContainsString('/webhooks/', $worker);
        $this->assertStringNotContainsString('cache.put', $worker, 'aucune page n\'est gardée sur l\'appareil');

        $offline = file_get_contents(public_path('offline.html'));
        $this->assertStringContainsString('Pas de connexion', $offline);
        $this->assertStringContainsString('noindex', $offline);
    }
}
