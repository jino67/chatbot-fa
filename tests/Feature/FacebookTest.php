<?php

namespace Tests\Feature;

use App\Ingestion\Crawler\SafeUrl;
use App\Models\Bot;
use App\Models\FacebookConnection;
use App\Models\Source;
use App\Models\User;
use App\Services\PlatformSettings;
use App\Social\FacebookGraph;
use App\Social\FacebookUrl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * Facebook et Instagram : on n'explore jamais ces pages (interdit par Meta, risque pour le compte WhatsApp).
 * Le client colle le lien, puis le contenu ; ou il connecte sa page par l'API officielle.
 */
class FacebookTest extends TestCase
{
    use CreatesTenants;
    use RefreshDatabase;

    private Bot $bot;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        [, $this->owner, $this->bot] = $this->tenant('Boutique Awa', 'pro');
        SafeUrl::useResolver(fn () => ['93.184.216.34']);
    }

    protected function tearDown(): void
    {
        SafeUrl::useResolver(null);
        parent::tearDown();
    }

    private function configureFacebook(): void
    {
        $settings = app(PlatformSettings::class);
        $settings->set('facebook.app_id', '1234567890');
        $settings->set('facebook.app_secret', 'app-secret-facebook', secret: true);
    }

    private function fakeGraph(): void
    {
        Http::fake([
            'graph.facebook.com/*/oauth/access_token*' => Http::response(['access_token' => 'USER-TOKEN']),
            'graph.facebook.com/*/me/accounts*' => Http::response(['data' => [
                ['id' => '111', 'name' => 'Boutique Awa', 'access_token' => 'PAGE-TOKEN-111', 'link' => 'https://www.facebook.com/boutique.awa', 'category' => 'Boutique de vêtements'],
                ['id' => '222', 'name' => 'Autre page', 'access_token' => 'PAGE-TOKEN-222', 'category' => 'Restaurant'],
            ]]),
            'graph.facebook.com/*/111/posts*' => Http::response(['data' => [
                ['message' => 'Promotion de rentrée : -10 % sur les foulards jusqu\'au 30 octobre.', 'created_time' => '2026-09-20T10:00:00+0000'],
                ['message' => '', 'created_time' => '2026-09-19T10:00:00+0000'],
            ]]),
            'graph.facebook.com/*/111*' => Http::response([
                'name' => 'Boutique Awa', 'about' => 'Mode africaine à Ouagadougou.', 'category' => 'Boutique de vêtements', 'phone' => '+226 70 00 00 00',
                'website' => 'https://boutique-awa.example', 'single_line_address' => 'Avenue Kwame N\'Krumah, Ouagadougou', 'link' => 'https://www.facebook.com/boutique.awa',
                'hours' => ['mon_1_open' => '08:00', 'mon_1_close' => '19:00', 'sun_1_open' => '09:00', 'sun_1_close' => '13:00'],
            ]),
        ]);
    }

    /* ---------- Reconnaissance des adresses ---------- */

    public function test_facebook_and_instagram_addresses_are_recognised_in_all_their_forms(): void
    {
        foreach ([
            'https://www.facebook.com/boutique.awa' => ['facebook', 'page', 'boutique.awa'],
            'facebook.com/boutique.awa' => ['facebook', 'page', 'boutique.awa'],
            'https://m.facebook.com/boutique.awa/' => ['facebook', 'page', 'boutique.awa'],
            'https://fb.me/boutique' => ['facebook', 'page', 'boutique'],
            'https://www.facebook.com/profile.php?id=100012345' => ['facebook', 'profile', '100012345'],
            'https://www.facebook.com/groups/vendeurs.ouaga' => ['facebook', 'group', 'groups'],
            'https://www.facebook.com/boutique.awa/posts/12345' => ['facebook', 'post', 'boutique.awa'],
            'https://www.instagram.com/boutique_awa/' => ['instagram', 'profile', 'boutique_awa'],
            'https://www.instagram.com/p/Cabc123/' => ['instagram', 'post', null],
            'https://www.instagram.com/reel/Cxyz789/' => ['instagram', 'post', null],
        ] as $url => [$platform, $kind, $slug]) {
            $parsed = FacebookUrl::parse($url);
            $this->assertNotNull($parsed, $url);
            $this->assertSame([$platform, $kind, $slug], [$parsed['platform'], $parsed['kind'], $parsed['slug']], $url);
        }
    }

    public function test_look_alike_and_ordinary_addresses_are_not_mistaken_for_facebook(): void
    {
        foreach (['https://www.monsite.com', 'https://facebook.com.evil.example/page', 'https://notfacebook.com/x', 'https://evil.example/?u=facebook.com', '', 'pas une adresse'] as $url) {
            $this->assertNull(FacebookUrl::parse($url), $url);
        }
    }

    /* ---------- Lien colle par le client ---------- */

    public function test_pasting_a_facebook_link_saves_it_without_any_outgoing_request_and_invites_to_add_the_content(): void
    {
        Http::fake();

        $this->actingAs($this->owner)->post(route('sources.store', $this->bot), ['type' => 'url', 'url' => 'facebook.com/boutique.awa', 'mode' => 'page'])
            ->assertSessionHas('status')->assertSessionHas('focus_source');

        $source = $this->bot->sources()->firstOrFail();
        $this->assertSame(Source::TYPE_FACEBOOK, $source->type);
        $this->assertSame(Source::NEEDS_CONTENT, $source->status);
        $this->assertSame('facebook', $source->payload['platform']);
        Http::assertNothingSent();

        $this->actingAs($this->owner)->get(route('sources.index', $this->bot))
            ->assertOk()->assertSee('Dernière étape')->assertSee('facebook.com/boutique.awa')->assertSee('À compléter');
    }

    public function test_an_instagram_link_gets_the_same_treatment(): void
    {
        Http::fake();

        $this->actingAs($this->owner)->post(route('sources.store', $this->bot), ['type' => 'url', 'url' => 'https://www.instagram.com/boutique_awa/', 'mode' => 'page'])->assertSessionHas('status');

        $source = $this->bot->sources()->firstOrFail();
        $this->assertSame(Source::NEEDS_CONTENT, $source->status);
        $this->assertSame('instagram', $source->payload['platform']);
        Http::assertNothingSent();
    }

    public function test_the_pasted_content_completes_the_pending_link_and_can_add_the_linked_website(): void
    {
        Http::fake(['boutique-awa.example*' => Http::response('<html><head><title>Accueil</title></head><body><main><h1>Boutique Awa</h1><p>Robes en wax et boubous brodés faits main à Ouagadougou depuis 2012, livraison dans tout le pays.</p></main></body></html>', 200, ['Content-Type' => 'text/html'])]);
        $this->actingAs($this->owner)->post(route('sources.store', $this->bot), ['type' => 'url', 'url' => 'https://www.facebook.com/boutique.awa', 'mode' => 'page']);
        $pending = $this->bot->sources()->firstOrFail();

        $this->actingAs($this->owner)->put(route('sources.content', [$this->bot, $pending]), [
            'content' => "À propos : boutique de mode africaine.\nPromotion de rentrée : -10 % sur les foulards jusqu'au 30 octobre.\nHoraires : lundi au samedi, 8 h à 19 h.",
            'website' => 'boutique-awa.example',
        ])->assertSessionHas('status', fn ($m) => str_contains($m, 'site web'));

        $this->assertSame(Source::READY, $pending->fresh()->status);
        $this->assertSame(2, $this->bot->sources()->count(), 'la page Facebook et le site lie');
        $this->assertNotNull($this->bot->sources()->where('type', Source::TYPE_URL)->first());
    }

    public function test_pasted_content_is_validated_and_only_applies_to_facebook_sources(): void
    {
        Http::fake();
        $this->actingAs($this->owner)->post(route('sources.store', $this->bot), ['type' => 'url', 'url' => 'https://www.facebook.com/boutique.awa', 'mode' => 'page']);
        $pending = $this->bot->sources()->firstOrFail();

        $this->actingAs($this->owner)->put(route('sources.content', [$this->bot, $pending]), ['content' => 'trop court'])->assertSessionHasErrors('content');
        $this->assertSame(Source::NEEDS_CONTENT, $pending->fresh()->status);

        $text = $this->teach($this->bot, 'Horaires', "# Horaires\nOuvert de 8 h à 19 h.");
        $this->actingAs($this->owner)->put(route('sources.content', [$this->bot, $text]), ['content' => str_repeat('Contenu valable. ', 5)])->assertNotFound();
    }

    public function test_a_pending_link_cannot_be_re_indexed_before_the_content_is_provided(): void
    {
        Http::fake();
        $this->actingAs($this->owner)->post(route('sources.store', $this->bot), ['type' => 'url', 'url' => 'https://www.facebook.com/boutique.awa', 'mode' => 'page']);

        $this->actingAs($this->owner)->post(route('sources.resync', [$this->bot, $this->bot->sources()->firstOrFail()]))->assertStatus(422);
        Http::assertNothingSent();
    }

    public function test_another_client_cannot_complete_my_pending_link(): void
    {
        Http::fake();
        $this->actingAs($this->owner)->post(route('sources.store', $this->bot), ['type' => 'url', 'url' => 'https://www.facebook.com/boutique.awa', 'mode' => 'page']);
        $pending = $this->bot->sources()->firstOrFail();
        [, $stranger, $strangerBot] = $this->tenant('Autre', 'pro');

        $this->actingAs($stranger)->put(route('sources.content', [$strangerBot, $pending]), ['content' => str_repeat('Contenu valable. ', 5)])->assertNotFound();
        $this->assertSame(Source::NEEDS_CONTENT, $pending->fresh()->status);
    }

    /* ---------- Connexion officielle (API Graph) ---------- */

    public function test_the_connect_button_only_appears_once_the_meta_app_is_configured(): void
    {
        $this->actingAs($this->owner)->get(route('sources.index', $this->bot))->assertOk()->assertDontSee('Connecter ma page Facebook');
        $this->actingAs($this->owner)->get(route('facebook.connect', $this->bot))->assertSessionHas('error');

        $this->configureFacebook();
        $this->actingAs($this->owner)->get(route('sources.index', $this->bot))->assertOk()->assertSee('Connecter ma page Facebook');
    }

    public function test_connect_redirects_to_facebook_with_a_random_state_and_the_expected_scopes(): void
    {
        $this->configureFacebook();

        $response = $this->actingAs($this->owner)->get(route('facebook.connect', $this->bot));

        $location = $response->headers->get('Location');
        $this->assertStringStartsWith('https://www.facebook.com/', $location);
        parse_str(parse_url($location, PHP_URL_QUERY), $query);
        $this->assertSame('1234567890', $query['client_id']);
        $this->assertSame(route('facebook.callback'), $query['redirect_uri']);
        $this->assertSame(session('facebook_oauth.state'), $query['state']);
        $this->assertGreaterThanOrEqual(32, strlen($query['state']));
        $this->assertStringContainsString('pages_read_engagement', $query['scope']);
    }

    public function test_the_callback_refuses_a_missing_or_forged_state(): void
    {
        $this->configureFacebook();
        Http::fake();

        $this->actingAs($this->owner)->get(route('facebook.callback', ['code' => 'abc', 'state' => 'x']))->assertStatus(419);

        $this->actingAs($this->owner)->withSession(['facebook_oauth' => ['state' => 'le-vrai-etat', 'bot' => $this->bot->id]])
            ->get(route('facebook.callback', ['code' => 'abc', 'state' => 'un-autre-etat']))->assertStatus(419);

        Http::assertNothingSent();
    }

    public function test_a_cancelled_authorisation_returns_to_the_sources_page(): void
    {
        $this->configureFacebook();

        $this->actingAs($this->owner)->withSession(['facebook_oauth' => ['state' => 'etat', 'bot' => $this->bot->id]])
            ->get(route('facebook.callback', ['error' => 'access_denied', 'state' => 'etat']))
            ->assertRedirect(route('sources.index', $this->bot))->assertSessionHas('error');
    }

    public function test_the_full_connection_flow_imports_the_page_and_keeps_tokens_encrypted(): void
    {
        $this->configureFacebook();
        $this->fakeGraph();

        $callback = $this->actingAs($this->owner)->withSession(['facebook_oauth' => ['state' => 'etat', 'bot' => $this->bot->id]])
            ->get(route('facebook.callback', ['code' => 'abc', 'state' => 'etat']));

        // Le client choisit sa page ; les jetons de page ne transitent jamais par son navigateur.
        $callback->assertOk()->assertSee('Boutique Awa')->assertSee('Autre page')->assertDontSee('PAGE-TOKEN-111')->assertDontSee('USER-TOKEN');

        $this->post(route('facebook.select', $this->bot), ['page_id' => '111'])->assertRedirect(route('sources.index', $this->bot))->assertSessionHas('status');

        $source = $this->bot->sources()->firstOrFail();
        $this->assertSame(Source::READY, $source->fresh()->status);
        $this->assertTrue($source->payload['connected']);
        $this->assertSame('weekly', $source->resync);
        $this->assertStringContainsString('Lundi : de 08:00 à 19:00', $source->payload['content']);
        $this->assertStringContainsString('Dimanche : de 09:00 à 13:00', $source->payload['content']);
        $this->assertStringContainsString('-10 % sur les foulards', $source->payload['content']);
        $this->assertStringContainsString('Avenue Kwame N\'Krumah', $source->payload['content']);

        $connection = FacebookConnection::withoutGlobalScopes()->firstOrFail();
        $this->assertSame('PAGE-TOKEN-111', $connection->access_token);
        $this->assertStringNotContainsString('PAGE-TOKEN-111', (string) DB::table('facebook_connections')->value('access_token'));
        $this->assertArrayNotHasKey('access_token', $connection->toArray());
        $this->assertSame($source->id, $connection->source_id);
    }

    public function test_the_import_completes_a_pending_pasted_link_instead_of_duplicating_it(): void
    {
        $this->configureFacebook();
        $this->fakeGraph();
        $this->actingAs($this->owner)->post(route('sources.store', $this->bot), ['type' => 'url', 'url' => 'https://www.facebook.com/boutique.awa', 'mode' => 'page']);
        $this->assertSame(1, $this->bot->sources()->count());

        $this->actingAs($this->owner)->withSession(['facebook_oauth' => ['state' => 'etat', 'bot' => $this->bot->id]])
            ->get(route('facebook.callback', ['code' => 'abc', 'state' => 'etat']));
        $this->post(route('facebook.select', $this->bot), ['page_id' => '111'])->assertSessionHas('status');

        $this->assertSame(1, $this->bot->sources()->count());
        $this->assertSame(Source::READY, $this->bot->sources()->first()->status);
    }

    public function test_a_page_that_the_user_does_not_administer_cannot_be_selected(): void
    {
        $this->configureFacebook();
        $this->fakeGraph();
        $this->actingAs($this->owner)->withSession(['facebook_oauth' => ['state' => 'etat', 'bot' => $this->bot->id]])
            ->get(route('facebook.callback', ['code' => 'abc', 'state' => 'etat']));

        $this->post(route('facebook.select', $this->bot), ['page_id' => '999'])->assertStatus(422);
        $this->assertSame(0, $this->bot->sources()->count());
    }

    public function test_the_selection_expires_and_cannot_be_replayed(): void
    {
        $this->configureFacebook();
        $this->fakeGraph();
        $this->actingAs($this->owner)->post(route('facebook.select', $this->bot), ['page_id' => '111'])->assertStatus(419);

        $this->actingAs($this->owner)->withSession(['facebook_oauth' => ['state' => 'etat', 'bot' => $this->bot->id]])
            ->get(route('facebook.callback', ['code' => 'abc', 'state' => 'etat']));
        $this->post(route('facebook.select', $this->bot), ['page_id' => '111'])->assertSessionHas('status');
        $this->post(route('facebook.select', $this->bot), ['page_id' => '111'])->assertStatus(419);
    }

    public function test_a_facebook_error_is_shown_to_the_client_and_expired_tokens_are_explained(): void
    {
        $this->configureFacebook();
        Http::fake(['graph.facebook.com/*/oauth/access_token*' => Http::response(['error' => ['message' => 'Invalid verification code format.', 'code' => 100]], 400)]);

        $this->actingAs($this->owner)->withSession(['facebook_oauth' => ['state' => 'etat', 'bot' => $this->bot->id]])
            ->get(route('facebook.callback', ['code' => 'abc', 'state' => 'etat']))
            ->assertRedirect(route('sources.index', $this->bot))->assertSessionHas('error', fn ($m) => str_contains($m, 'Invalid verification code'));

        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'Session has expired', 'code' => 190]], 401)]);
        try {
            app(FacebookGraph::class)->pages('vieux-jeton');
            $this->fail('exception attendue');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('expirée', $e->getMessage());
        }
    }

    public function test_the_weekly_refresh_marks_a_connection_expired_when_facebook_refuses_the_token(): void
    {
        $this->configureFacebook();
        $this->fakeGraph();
        $this->actingAs($this->owner)->withSession(['facebook_oauth' => ['state' => 'etat', 'bot' => $this->bot->id]])->get(route('facebook.callback', ['code' => 'abc', 'state' => 'etat']));
        $this->post(route('facebook.select', $this->bot), ['page_id' => '111']);
        $connection = FacebookConnection::withoutGlobalScopes()->firstOrFail();
        $connection->update(['last_synced_at' => now()->subDays(8)]);
        Source::withoutGlobalScopes()->whereKey($connection->source_id)->update(['last_synced_at' => now()->subDays(8)]);

        Http::swap(new HttpFactory);
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'Error validating access token', 'code' => 190]], 401)]);
        $this->artisan('platform:resync-due')->assertSuccessful();

        $this->assertSame('expired', $connection->fresh()->status);
        $this->assertSame(Source::FAILED, Source::withoutGlobalScopes()->find($connection->source_id)->status);
    }

    public function test_the_meta_app_settings_are_super_admin_only_and_the_secret_is_stored_encrypted(): void
    {
        $this->actingAs($this->staff())->put(route('admin.settings.update'), ['brand_name' => 'Kouma', 'facebook_app_id' => '1', 'facebook_app_secret' => 'x'])->assertForbidden();

        $this->actingAs($this->admin())->put(route('admin.settings.update'), ['brand_name' => 'Kouma', 'facebook_app_id' => '1234567890', 'facebook_app_secret' => 'app-secret-facebook'])->assertSessionHas('status');

        $this->assertTrue(app(FacebookGraph::class)->isConfigured());
        $this->assertStringNotContainsString('app-secret-facebook', (string) DB::table('platform_settings')->where('key', 'facebook.app_secret')->value('value'));
        $this->actingAs($this->admin())->get(route('admin.settings.edit'))->assertOk()->assertDontSee('app-secret-facebook')->assertSee('Configurée');
    }

    public function test_pages_are_never_fetched_from_our_servers_when_a_link_is_pasted(): void
    {
        Http::fake();

        foreach (['https://www.facebook.com/boutique.awa', 'https://fb.me/boutique', 'https://www.instagram.com/boutique_awa/', 'https://m.facebook.com/profile.php?id=1'] as $url) {
            $this->actingAs($this->owner)->post(route('sources.store', $this->bot), ['type' => 'url', 'url' => $url, 'mode' => 'site']);
        }

        Http::assertNothingSent();
        Http::assertNotSent(fn (HttpRequest $r) => str_contains($r->url(), 'facebook.com') || str_contains($r->url(), 'instagram.com'));
    }
}
