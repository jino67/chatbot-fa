<?php

namespace Tests\Feature;

use App\Models\Bot;
use App\Models\Channel;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/** Le lien de discussion à partager : page publique légère, QR code, affiche, menu Partager. */
class ShareChatTest extends TestCase
{
    use CreatesTenants;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function whatsapp(Bot $bot): void
    {
        Channel::withoutGlobalScopes()->create([
            'workspace_id' => $bot->workspace_id, 'bot_id' => $bot->id, 'type' => Channel::WHATSAPP_META, 'status' => Channel::ACTIVE,
            'display_phone' => '+226 70 00 00 00', 'external_ref' => 'pn-1', 'credentials' => ['access_token' => 'x', 'waba_id' => '1'],
        ]);
    }

    public function test_the_public_chat_page_shows_the_company_and_loads_only_the_widget(): void
    {
        [, , $bot] = $this->tenant('Boutique Awa');
        $bot->update(['welcome_message' => 'Bonjour, bienvenue chez Awa !', 'theme' => ['color' => '#12A574']]);

        $page = $this->get($bot->chatUrl())->assertOk();

        $page->assertSee('Boutique Awa')->assertSee('Bonjour, bienvenue chez Awa !')->assertSee('Discuter maintenant')
            ->assertSee('/widget/widget.js', false)->assertSee('data-bot="'.$bot->public_key.'"', false)->assertSee('#12A574', false);
        $page->assertHeader('X-Robots-Tag', 'noindex, nofollow')->assertSee('noindex', false);
        // Aucune mesure d'audience sur les pages vues par les clients de nos clients.
        $page->assertDontSee('kouma-analytics', false);
        $this->assertStringNotContainsString('app.js', $page->getContent());
    }

    public function test_it_shows_the_powered_by_line_unless_the_plan_removes_it(): void
    {
        [$workspace, , $bot] = $this->tenant('Boutique Awa', 'essentiel');
        $this->get($bot->chatUrl())->assertSee('Propulsé par');

        $this->assertFalse($workspace->hasFeature('remove_branding'));
        [, , $pro] = $this->tenant('Atelier Pro', 'pro');
        $this->assertTrue($pro->workspace->hasFeature('remove_branding'));
        $this->get($pro->chatUrl())->assertDontSee('Propulsé par');
    }

    public function test_the_whatsapp_button_needs_an_active_number(): void
    {
        [, , $bot] = $this->tenant('Boutique Awa');
        $this->get($bot->chatUrl())->assertDontSee('Continuer sur WhatsApp');

        $this->whatsapp($bot);
        $this->get($bot->chatUrl())->assertSee('Continuer sur WhatsApp')->assertSee('https://wa.me/22670000000', false);
    }

    public function test_an_unavailable_assistant_shows_a_message_and_no_widget(): void
    {
        [$workspace, , $bot] = $this->tenant('Boutique Awa');
        $bot->update(['is_active' => false]);
        $this->get($bot->chatUrl())->assertOk()->assertSee('pas disponible')->assertDontSee('/widget/widget.js', false);

        $bot->update(['is_active' => true]);
        $workspace->update(['is_suspended' => true]);
        $this->get($bot->chatUrl())->assertSee('pas disponible')->assertDontSee('Discuter maintenant');

        $workspace->update(['is_suspended' => false, 'subscription_status' => Workspace::EXPIRED]);
        $this->get($bot->chatUrl())->assertSee('pas disponible');

        $this->get('/chat/pk_inexistant')->assertNotFound();
        $this->get('/chat/n-importe-quoi')->assertNotFound();
    }

    public function test_the_platforms_own_pages_work_even_when_the_site_is_restricted(): void
    {
        [, , $bot] = $this->tenant('Boutique Awa');
        $bot->update(['allowed_origins' => ['https://www.boutique.com']]);
        $own = rtrim((string) config('app.url'), '/');

        // La page de discussion partagée vit sur la plateforme : sa propre origine passe toujours, les autres sites non.
        $this->withHeaders(['Origin' => $own])->getJson('/api/v1/widget/'.$bot->public_key.'/config')->assertOk();
        $this->withHeaders(['Origin' => 'https://evil.example'])->getJson('/api/v1/widget/'.$bot->public_key.'/config')->assertForbidden();
    }

    public function test_the_owner_gets_the_qr_code_and_it_points_to_the_chat_page(): void
    {
        [, $user, $bot] = $this->tenant('Boutique Awa');

        $response = $this->actingAs($user)->get(route('share.qr', $bot))->assertOk();
        $this->assertStringStartsWith('image/svg+xml', $response->headers->get('Content-Type'));
        $this->assertSame(\App\Support\QrCode::svg($bot->chatUrl()), $response->getContent());
        $this->assertNull($response->headers->get('Content-Disposition'));

        $download = $this->actingAs($user)->get(route('share.qr', [$bot, 'telecharger' => 1]))->assertOk();
        $this->assertStringContainsString('attachment; filename="qr-boutique-awa.svg"', $download->headers->get('Content-Disposition'));
    }

    public function test_the_whatsapp_qr_only_exists_with_an_active_number(): void
    {
        [, $user, $bot] = $this->tenant('Boutique Awa');

        $without = $this->actingAs($user)->get(route('share.qr', [$bot, 'cible' => 'whatsapp']))->assertOk()->getContent();
        $this->assertSame(\App\Support\QrCode::svg($bot->chatUrl()), $without, 'sans numéro actif, le QR reste celui du lien de discussion');

        $this->whatsapp($bot);
        $with = $this->actingAs($user)->get(route('share.qr', [$bot, 'cible' => 'whatsapp']))->assertOk()->getContent();
        $this->assertNotSame($without, $with);
        $this->assertStringStartsWith('<svg', $with);
    }

    public function test_the_poster_is_printable_and_shows_the_link(): void
    {
        [, $user, $bot] = $this->tenant('Boutique Awa');

        $this->actingAs($user)->get(route('share.poster', $bot))->assertOk()
            ->assertSee('Boutique Awa')->assertSee('Scannez pour discuter avec nous')->assertSee('<svg', false)
            ->assertSee(preg_replace('#^https?://#', '', $bot->chatUrl()))->assertSee('window.print()', false);

        $this->whatsapp($bot);
        $this->actingAs($user)->get(route('share.poster', [$bot, 'cible' => 'whatsapp']))->assertOk()
            ->assertSee('nous écrire')->assertSee('wa.me/22670000000');
    }

    public function test_sharing_pages_never_work_for_another_clients_assistant(): void
    {
        [, $userA] = $this->tenant('Client A');
        [, , $botB] = $this->tenant('Client B');

        $this->actingAs($userA)->get(route('share.qr', $botB))->assertNotFound();
        $this->actingAs($userA)->get(route('share.poster', $botB))->assertNotFound();
    }

    public function test_the_assistant_pages_offer_the_share_menu_and_card(): void
    {
        [, $user, $bot] = $this->tenant('Boutique Awa');

        $this->actingAs($user)->get(route('sources.index', $bot))->assertOk()->assertSee('Partager le lien')->assertSee($bot->chatUrl());

        $this->actingAs($user)->get(route('channels.show', $bot))->assertOk()
            ->assertSee('Partager votre assistant')->assertSee('Affiche à imprimer')->assertSee('QR en PNG')
            ->assertSee('wa.me', false)->assertSee('facebook.com', false);
    }

    public function test_the_shared_link_and_the_qr_follow_the_public_address_of_the_site(): void
    {
        [, $user, $bot] = $this->tenant('Boutique Awa');

        // En production, les adresses absolues viennent de APP_URL (voir AppServiceProvider) : jamais l'adresse locale.
        \Illuminate\Support\Facades\URL::forceRootUrl('https://kouma.site');
        \Illuminate\Support\Facades\URL::forceScheme('https');

        $this->assertSame('https://kouma.site/chat/'.$bot->public_key, $bot->chatUrl());
        $this->assertSame(\App\Support\QrCode::svg('https://kouma.site/chat/'.$bot->public_key), $this->actingAs($user)->get(route('share.qr', $bot))->getContent());
        $this->actingAs($user)->get(route('share.poster', $bot))->assertSee('kouma.site/chat/'.$bot->public_key);
        $this->actingAs($user)->get(route('channels.show', $bot))->assertSee('https://kouma.site/chat/'.$bot->public_key, false);
    }

    public function test_the_hook_puts_whatsapp_first(): void
    {
        $page = $this->get('/')->assertOk();
        $page->assertSee('Vos clients vous écrivent sur WhatsApp')->assertSee('Chatbot WhatsApp pour entreprises');
        $this->assertStringContainsString('Le chatbot WhatsApp qui répond à vos clients à toute heure', (string) $page->getContent());
    }

    public function test_an_old_saved_tagline_is_replaced_by_the_new_one(): void
    {
        $settings = app(\App\Services\PlatformSettings::class);

        $settings->set('brand.tagline', "L'assistant qui répond à vos clients, sur votre site et sur WhatsApp");
        $this->assertSame(config('brand.tagline'), $settings->brand()['tagline']);

        $settings->set('brand.tagline', 'Mon accroche à moi');
        $this->assertSame('Mon accroche à moi', $settings->brand()['tagline']);
    }
}
