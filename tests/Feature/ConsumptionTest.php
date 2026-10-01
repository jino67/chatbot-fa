<?php

namespace Tests\Feature;

use App\Channels\WhatsApp\GatewayException;
use App\Channels\WhatsApp\TemplateManager;
use App\Models\Bot;
use App\Models\Channel;
use App\Models\ChannelRequest;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\UsageEvent;
use App\Models\User;
use App\Models\WhatsAppTemplate;
use App\Models\Workspace;
use App\Services\CostCalculator;
use App\Services\PlatformSettings;
use App\Services\TwilioWallet;
use App\Services\UsageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * Les messages WhatsApp sont payes par la plateforme (Meta direct ou Twilio, comptes centralises) : chaque message,
 * chaque reponse de l'IA est compte avec son cout, le volume de l'offre limite l'envoi, et le super admin voit tout en direct.
 */
class ConsumptionTest extends TestCase
{
    use CreatesTenants;
    use RefreshDatabase;

    private Workspace $workspace;

    private User $owner;

    private Bot $bot;

    private const PHONE_NUMBER_ID = '109876543210';

    private const CUSTOMER = '22670123456';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        [$this->workspace, $this->owner, $this->bot] = $this->tenant('Boutique Test', 'pro');
        $this->teach($this->bot, 'Horaires', "# Horaires\nLa boutique est ouverte du lundi au samedi de 8 h à 19 h.");
    }

    /* ---------- Helpers ---------- */

    private function metaChannel(array $credentials = ['access_token' => 'EAAtoken', 'waba_id' => '555']): Channel
    {
        return Channel::withoutGlobalScopes()->create([
            'workspace_id' => $this->workspace->id, 'bot_id' => $this->bot->id, 'type' => Channel::WHATSAPP_META, 'status' => Channel::ACTIVE,
            'display_phone' => '+226 70 00 00 00', 'external_ref' => self::PHONE_NUMBER_ID, 'credentials' => $credentials,
        ]);
    }

    private function twilioChannel(array $credentials = ['account_sid' => 'ACtest', 'auth_token' => 'twilio-secret', 'from' => '+14155238886']): Channel
    {
        return Channel::withoutGlobalScopes()->create([
            'workspace_id' => $this->workspace->id, 'bot_id' => $this->bot->id, 'type' => Channel::WHATSAPP_TWILIO, 'status' => Channel::ACTIVE,
            'display_phone' => '+14155238886', 'external_ref' => '14155238886', 'credentials' => $credentials,
        ]);
    }

    private function fakeGraph(): void
    {
        Http::fake(['graph.facebook.com/*' => fn () => Http::response(['messages' => [['id' => 'wamid.OUT'.uniqid('', true)]]], 200)]);
    }

    private function metaPayload(string $text, string $id): array
    {
        return ['object' => 'whatsapp_business_account', 'entry' => [['id' => '555', 'changes' => [['field' => 'messages', 'value' => [
            'messaging_product' => 'whatsapp',
            'metadata' => ['display_phone_number' => '22670000000', 'phone_number_id' => self::PHONE_NUMBER_ID],
            'contacts' => [['profile' => ['name' => 'Fatou'], 'wa_id' => self::CUSTOMER]],
            'messages' => [['from' => self::CUSTOMER, 'id' => $id, 'timestamp' => (string) time(), 'type' => 'text', 'text' => ['body' => $text]]],
        ]]]]]];
    }

    private function sendMeta(string $text = "Quels sont les horaires d'ouverture de la boutique le samedi ?", string $id = 'wamid.IN1')
    {
        $body = json_encode($this->metaPayload($text, $id));

        return $this->call('POST', '/webhooks/whatsapp/meta', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, 'test-app-secret'),
        ], $body);
    }

    private function sendTwilio(Channel $channel, string $token = 'twilio-secret', string $sid = 'SMin1')
    {
        $params = ['MessageSid' => $sid, 'From' => 'whatsapp:+'.self::CUSTOMER, 'To' => 'whatsapp:+14155238886', 'Body' => "Quels sont les horaires d'ouverture de la boutique le samedi ?", 'ProfileName' => 'Fatou', 'NumMedia' => '0'];
        $url = url("/webhooks/whatsapp/twilio/{$channel->id}");
        ksort($params);
        $data = $url;
        foreach ($params as $key => $value) {
            $data .= $key.$value;
        }

        return $this->post($url, $params, ['X-Twilio-Signature' => base64_encode(hash_hmac('sha1', $data, $token, true))]);
    }

    private function events(string $kind): \Illuminate\Support\Collection
    {
        return UsageEvent::withoutGlobalScopes()->where('kind', $kind)->get();
    }

    /** @return list<string> */
    private function subjects(): array
    {
        return collect(app('mailer')->getSymfonyTransport()->messages())->map(fn ($m) => $m->getOriginalMessage()->getSubject())->all();
    }

    private function setAllowance(int $messages): void
    {
        $plan = Plan::bySlug('pro');
        $plan->update(['limits' => ['whatsapp_messages_per_month' => $messages] + $plan->limits]);
    }

    /* ---------- Couts unitaires ---------- */

    public function test_a_whatsapp_message_costs_the_intermediary_fee_plus_the_meta_category(): void
    {
        $costs = app(CostCalculator::class);

        $this->assertSame(0.0, $costs->whatsapp('meta', 'in'), 'Meta direct : aucun frais d\'intermediaire');
        $this->assertSame(0.0, $costs->whatsapp('meta', 'out'), 'un message de service est gratuit dans la fenetre');
        $this->assertSame(0.005, $costs->whatsapp('twilio', 'in'));
        $this->assertSame(0.005, $costs->whatsapp('twilio', 'out'));
        $this->assertSame(0.0225, $costs->whatsapp('meta', 'template', 'MARKETING'));
        $this->assertSame(0.0127, $costs->whatsapp('twilio', 'template', 'UTILITY'), '0,005 Twilio + 0,0077 Meta utilitaire');
    }

    public function test_unit_costs_can_be_changed_by_the_super_admin(): void
    {
        $settings = app(PlatformSettings::class);
        $settings->set('costs.twilio_fee', 0.008);
        $settings->set('costs.meta_service', 0.004);

        $costs = app(CostCalculator::class);
        $this->assertSame(0.008, $costs->whatsapp('twilio', 'in'));
        $this->assertSame(0.012, $costs->whatsapp('twilio', 'out'));
    }

    public function test_ai_answers_are_priced_from_the_model_rates_and_the_offline_mode_is_free(): void
    {
        $costs = app(CostCalculator::class);

        $this->assertEqualsWithDelta(0.0038, $costs->ai('anthropic', 'claude-haiku-4-5', 2800, 200), 0.000001);
        $this->assertEqualsWithDelta(0.00054, $costs->ai('openai', 'gpt-4o-mini', 2800, 200), 0.000001);
        $this->assertSame(0.0, $costs->ai('fake', 'fake', 2800, 200));
        $this->assertEqualsWithDelta(0.0038, $costs->ai('fournisseur-inconnu', 'x', 2800, 200), 0.000001, 'repli sur le tarif par defaut');
    }

    public function test_currency_conversions_follow_the_fixed_parities(): void
    {
        $costs = app(CostCalculator::class);

        $this->assertEqualsWithDelta(655.957, $costs->usdTo(1.1339, 'XOF'), 0.001, '1 euro = 655,957 FCFA');
        $this->assertEqualsWithDelta(1.1339, $costs->toUsd(655.957, 'XOF'), 0.0001);
        $this->assertEqualsWithDelta(491.968, $costs->usdTo(1.1339, 'KMF'), 0.001);
    }

    /* ---------- Comptage a chaque message ---------- */

    public function test_a_meta_conversation_is_counted_message_by_message_at_no_intermediary_cost(): void
    {
        $this->metaChannel();
        $this->fakeGraph();

        $this->sendMeta()->assertOk();

        $this->assertCount(1, $this->events('wa_in'));
        $this->assertCount(1, $this->events('wa_out'));
        $this->assertCount(1, $this->events('ai_answer'));
        $this->assertSame('meta', $this->events('wa_in')->first()->provider);
        $this->assertSame(0.0, (float) $this->events('wa_out')->sum('cost_usd'));
        $this->assertSame($this->workspace->id, $this->events('wa_out')->first()->workspace_id);
        $this->assertSame(2, app(UsageService::class)->whatsappUsed($this->workspace), 'un message reçu et un envoyé');
    }

    public function test_a_twilio_conversation_costs_the_platform_the_twilio_fee_on_each_message(): void
    {
        $channel = $this->twilioChannel();
        Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'SMout1'], 201)]);

        $this->sendTwilio($channel)->assertOk();

        $this->assertSame('twilio', $this->events('wa_in')->first()->provider);
        $this->assertEqualsWithDelta(0.01, (float) UsageEvent::withoutGlobalScopes()->whereIn('kind', ['wa_in', 'wa_out'])->sum('cost_usd'), 0.000001);
    }

    public function test_a_redelivered_webhook_is_not_counted_twice(): void
    {
        $this->metaChannel();
        $this->fakeGraph();

        $this->sendMeta(id: 'wamid.SAME')->assertOk();
        $this->sendMeta(id: 'wamid.SAME')->assertOk();

        $this->assertCount(1, $this->events('wa_in'));
    }

    public function test_usage_is_isolated_between_clients(): void
    {
        $this->metaChannel();
        $this->fakeGraph();
        $this->sendMeta()->assertOk();

        [$otherWorkspace, $otherOwner] = $this->tenant('Autre client', 'pro');

        $this->assertSame(0, app(UsageService::class)->whatsappUsed($otherWorkspace));
        $this->actingAs($otherOwner);
        $this->assertSame(0, UsageEvent::count(), 'le filtre par entreprise s\'applique au journal d\'usage');
    }

    /* ---------- Volume inclus dans l'offre, credit ---------- */

    public function test_sending_stops_when_the_plan_volume_is_used_up_and_the_owner_is_warned_once(): void
    {
        $this->metaChannel();
        $this->fakeGraph();
        $this->setAllowance(2);

        $this->sendMeta(id: 'wamid.A')->assertOk();   // reçu + envoyé = 2 : volume atteint
        $this->sendMeta(id: 'wamid.B')->assertOk();   // reçu (3), reponse bloquee
        $this->sendMeta(id: 'wamid.C')->assertOk();

        $blocked = Message::withoutGlobalScopes()->where('role', 'assistant')->get()->filter(fn ($m) => isset($m->meta['delivery_error']));
        $this->assertCount(2, $blocked);
        $this->assertStringContainsString('volume de messages WhatsApp', $blocked->first()->meta['delivery_error']);
        $this->assertCount(1, $this->events('wa_out'), 'rien n\'est envoye, donc rien n\'est compte');
        $this->assertCount(1, Http::recorded(fn (HttpRequest $r) => isset($r['text'])), 'un seul message est parti chez Meta');
        $this->assertCount(1, array_filter($this->subjects(), fn ($s) => $s === 'Volume de messages WhatsApp atteint'), 'le proprietaire est prevenu une seule fois par jour');
    }

    public function test_bought_credit_extends_the_volume_and_is_consumed_after_the_included_messages(): void
    {
        $this->metaChannel();
        $this->fakeGraph();
        $this->setAllowance(2);
        $this->workspace->update(['wa_credit' => 3]);

        $this->sendMeta(id: 'wamid.A')->assertOk();   // 2 messages : compris dans l'offre
        $this->assertSame(3, $this->workspace->fresh()->wa_credit, 'le credit n\'est pas touche tant que l\'offre suffit');

        $this->sendMeta(id: 'wamid.B')->assertOk();   // reçu (3) et envoye (4) : deux messages de credit

        $this->assertSame(1, $this->workspace->fresh()->wa_credit);
        $this->assertCount(2, $this->events('wa_out'));
    }

    public function test_the_usage_summary_exposes_the_whatsapp_volume_for_the_client(): void
    {
        $this->metaChannel();
        $this->fakeGraph();
        $this->workspace->update(['wa_credit' => 40]);
        $this->sendMeta()->assertOk();

        $summary = app(UsageService::class)->summary($this->workspace->fresh());

        $this->assertSame(['used' => 2, 'limit' => 2500, 'credit' => 40], $summary['whatsapp']);
        $this->actingAs($this->owner)->get(route('billing.show'))->assertOk()->assertSee('Messages WhatsApp')->assertSee('40 messages de crédit', false);
    }

    public function test_a_template_message_is_counted_with_its_category_and_refused_when_the_volume_is_used_up(): void
    {
        $channel = $this->metaChannel();
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.T1']]])]);
        $template = WhatsAppTemplate::withoutGlobalScopes()->create([
            'workspace_id' => $this->workspace->id, 'channel_id' => $channel->id, 'external_id' => 'tpl-1', 'name' => 'suivi', 'language' => 'fr',
            'category' => 'UTILITY', 'status' => WhatsAppTemplate::APPROVED, 'body' => 'Bonjour {{1}}', 'variables_count' => 1,
        ]);
        $conversation = Conversation::withoutGlobalScopes()->create([
            'workspace_id' => $this->workspace->id, 'bot_id' => $this->bot->id, 'channel' => 'whatsapp', 'external_id' => self::CUSTOMER, 'last_inbound_at' => now()->subHours(30),
        ]);

        app(TemplateManager::class)->sendTo($conversation, $template, ['Awa']);

        $event = $this->events('wa_template')->first();
        $this->assertSame('utility', $event->detail);
        $this->assertSame(0.0077, (float) $event->cost_usd);

        $this->setAllowance(1);
        $this->expectException(GatewayException::class);
        app(TemplateManager::class)->sendTo($conversation, $template, ['Awa']);
    }

    /* ---------- Comptes centralises de la plateforme ---------- */

    public function test_a_meta_channel_without_its_own_token_uses_the_platform_token(): void
    {
        app(PlatformSettings::class)->set('whatsapp.meta.system_token', 'PLATFORM-META-TOKEN', secret: true);
        $this->metaChannel(credentials: []);
        $this->fakeGraph();

        $this->sendMeta()->assertOk();

        Http::assertSent(fn (HttpRequest $r) => isset($r['text']) && $r->hasHeader('Authorization', 'Bearer PLATFORM-META-TOKEN'));
    }

    public function test_a_twilio_channel_without_its_own_account_uses_the_platform_account(): void
    {
        $settings = app(PlatformSettings::class);
        $settings->set('whatsapp.twilio.account_sid', 'ACplatform');
        $settings->set('whatsapp.twilio.auth_token', 'platform-secret', secret: true);
        $channel = $this->twilioChannel(credentials: ['from' => '+14155238886']);
        Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'SMout1'], 201)]);

        $this->sendTwilio($channel, token: 'platform-secret')->assertOk();

        Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), '/Accounts/ACplatform/Messages.json')
            && $r->hasHeader('Authorization', 'Basic '.base64_encode('ACplatform:platform-secret')));
    }

    public function test_the_platform_signature_key_is_required_when_the_channel_has_none(): void
    {
        $channel = $this->twilioChannel(credentials: ['from' => '+14155238886']);
        Http::fake();

        $this->sendTwilio($channel, token: 'quelconque')->assertUnauthorized();
    }

    public function test_an_admin_activates_a_channel_with_the_platform_accounts_and_no_credentials_of_its_own(): void
    {
        $request = ChannelRequest::withoutGlobalScopes()->create([
            'workspace_id' => $this->workspace->id, 'bot_id' => $this->bot->id, 'requester_id' => $this->owner->id,
            'business_name' => 'Boutique Test', 'phone_number' => '+226 70 00 00 00', 'country' => 'Burkina Faso',
        ]);
        $form = ['provider' => Channel::WHATSAPP_META, 'status' => 'active', 'display_phone' => '+226 70 00 00 00', 'request_status' => 'active', 'phone_number_id' => '109876543210'];

        $this->actingAs($this->admin())->put(route('admin.requests.update', $request->id), $form)->assertSessionHas('error');

        app(PlatformSettings::class)->set('whatsapp.meta.system_token', 'PLATFORM-META-TOKEN', secret: true);
        $this->actingAs($this->admin())->put(route('admin.requests.update', $request->id), $form)->assertSessionHas('status');

        $channel = Channel::withoutGlobalScopes()->where('bot_id', $this->bot->id)->firstOrFail();
        $this->assertTrue($channel->isActive());
        $this->assertNull($channel->credential('access_token'), 'aucun jeton du client : la plateforme paie');
    }

    public function test_the_super_admin_saves_platform_accounts_and_secrets_are_never_shown_again(): void
    {
        $this->actingAs($this->admin())->put(route('admin.settings.update'), [
            'brand_name' => 'Kouma', 'whatsapp_provider' => 'meta', 'meta_waba_id' => '555', 'meta_system_token' => 'SECRET-META-TOKEN',
            'twilio_account_sid' => 'ACplatform', 'twilio_auth_token' => 'SECRET-TWILIO', 'wallet_alert_below' => 30, 'cost_twilio_fee' => 0.006,
        ])->assertSessionHas('status');

        $settings = app(PlatformSettings::class);
        $this->assertSame('SECRET-META-TOKEN', $settings->get('whatsapp.meta.system_token'));
        $this->assertSame(0.006, (float) $settings->get('costs.twilio_fee'));
        $this->assertStringNotContainsString('SECRET-META-TOKEN', (string) \DB::table('platform_settings')->where('key', 'whatsapp.meta.system_token')->value('value'));

        $page = $this->actingAs($this->admin())->get(route('admin.settings.edit'));
        $page->assertOk()->assertSee('Meta connecté')->assertSee('Twilio connecté')->assertDontSee('SECRET-META-TOKEN')->assertDontSee('SECRET-TWILIO');

        // Un secret laisse vide est conserve.
        $this->actingAs($this->admin())->put(route('admin.settings.update'), ['brand_name' => 'Kouma', 'meta_system_token' => '', 'twilio_auth_token' => ''])->assertSessionHas('status');
        $settings->forget();
        $this->assertSame('SECRET-META-TOKEN', $settings->get('whatsapp.meta.system_token'));
    }

    /* ---------- Solde Twilio ---------- */

    public function test_the_twilio_wallet_balance_is_read_live_and_missing_credentials_are_explained(): void
    {
        $wallet = app(TwilioWallet::class);
        $this->assertFalse($wallet->balance()['configured']);
        $this->assertStringContainsString('non renseigné', $wallet->balance()['error']);

        $settings = app(PlatformSettings::class);
        $settings->set('whatsapp.twilio.account_sid', 'ACplatform');
        $settings->set('whatsapp.twilio.auth_token', 'platform-secret', secret: true);
        Http::fake(['api.twilio.com/*' => Http::response(['balance' => '42.5000', 'currency' => 'USD'])]);

        $balance = $wallet->balance(fresh: true);

        $this->assertTrue($balance['ok']);
        $this->assertSame(42.5, $balance['balance']);
        Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), '/Accounts/ACplatform/Balance.json'));
    }

    public function test_a_refused_twilio_account_is_reported_not_thrown(): void
    {
        $settings = app(PlatformSettings::class);
        $settings->set('whatsapp.twilio.account_sid', 'ACplatform');
        $settings->set('whatsapp.twilio.auth_token', 'mauvais', secret: true);
        Http::fake(['api.twilio.com/*' => Http::response(['message' => 'Authenticate'], 401)]);

        $balance = app(TwilioWallet::class)->balance(fresh: true);

        $this->assertFalse($balance['ok']);
        $this->assertStringContainsString('401', $balance['error']);
    }

    public function test_the_wallet_command_warns_the_admin_when_the_balance_is_low_and_a_client_near_the_limit(): void
    {
        $settings = app(PlatformSettings::class);
        $settings->set('whatsapp.twilio.account_sid', 'ACplatform');
        $settings->set('whatsapp.twilio.auth_token', 'platform-secret', secret: true);
        $settings->set('brand.email', 'contact@kouma.test');
        Http::fake(['api.twilio.com/*' => Http::response(['balance' => '5.00', 'currency' => 'USD'])]);
        $this->setAllowance(10);
        $channel = $this->metaChannel();
        for ($i = 0; $i < 9; $i++) {
            app(\App\Services\UsageMeter::class)->whatsapp($channel, 'out');
        }

        $this->artisan('platform:check-wallets')->assertSuccessful();
        $this->artisan('platform:check-wallets')->assertSuccessful();

        $subjects = $this->subjects();
        $this->assertCount(1, array_filter($subjects, fn ($s) => $s === 'Solde Twilio bas'), 'une alerte de solde par jour');
        $this->assertCount(1, array_filter($subjects, fn ($s) => str_contains($s, 'arrivent à leur limite')), 'une alerte de volume par mois et par client');
    }

    /* ---------- Page « Consommation » du super admin ---------- */

    public function test_the_consumption_page_is_reserved_to_the_super_admin(): void
    {
        $this->actingAs($this->owner)->get(route('admin.consumption.index'))->assertForbidden();
        $this->actingAs($this->staff())->get(route('admin.consumption.index'))->assertForbidden();
        $this->actingAs($this->owner)->getJson(route('admin.consumption.live'))->assertForbidden();
        $this->actingAs($this->admin())->get(route('admin.consumption.index'))->assertOk()->assertSee('Consommation en direct');
    }

    public function test_the_live_report_computes_cost_revenue_and_margin_per_client(): void
    {
        $channel = $this->twilioChannel();
        $meter = app(\App\Services\UsageMeter::class);
        $meter->whatsapp($channel, 'in');
        $meter->whatsapp($channel, 'out');
        $meter->whatsapp($channel, 'template', 'MARKETING');
        $meter->ai($this->workspace->id, $this->bot->id, 'anthropic', 'claude-haiku-4-5', 2800, 200);

        $report = $this->actingAs($this->admin())->getJson(route('admin.consumption.live'))->assertOk()->json();
        $row = collect($report['rows'])->firstWhere('id', $this->workspace->id);

        // 0,005 + 0,005 + (0,005 + 0,0225) + 0,0038 = 0,0413 dollar.
        $this->assertEqualsWithDelta(0.0413, $row['cost_month'], 0.0001);
        $this->assertSame(3, $row['wa']['used']);
        $this->assertSame(1, $row['wa']['in']);
        $this->assertSame(1, $row['wa']['out']);
        $this->assertSame(1, $row['wa']['template']);
        $this->assertSame(2500, $row['wa']['allowance']);
        $this->assertSame(1, $row['ai']['used']);
        $this->assertEqualsWithDelta(69.1, $row['revenue'], 0.1, 'offre Pro à 40 000 FCFA, en dollars');
        $this->assertGreaterThan(99, $row['margin_pct']);
        $this->assertSame(3, $report['providers']['twilio']['messages']);
        $this->assertEqualsWithDelta(0.015, $report['savings']['month_so_far'], 0.0001, '3 messages x 0,005 $ que Meta direct ne facturerait pas');
        $this->assertArrayHasKey('wallet', $report);
        $this->assertNotEmpty($report['feed']);
    }

    public function test_the_consumption_page_shows_the_balance_alert_and_the_saving_hint(): void
    {
        $settings = app(PlatformSettings::class);
        $settings->set('whatsapp.twilio.account_sid', 'ACplatform');
        $settings->set('whatsapp.twilio.auth_token', 'platform-secret', secret: true);
        Http::fake(['api.twilio.com/*' => Http::response(['balance' => '12.00', 'currency' => 'USD'])]);
        app(\App\Services\UsageMeter::class)->whatsapp($this->twilioChannel(), 'in');

        $report = $this->actingAs($this->admin())->getJson(route('admin.consumption.live'))->json();

        $this->assertTrue($report['wallet']['ok']);
        $this->assertEqualsWithDelta(12.0, $report['wallet']['balance'], 0.001);
        $this->assertTrue($report['wallet']['low'], 'sous le seuil de 20 dollars par defaut');
    }

    public function test_the_wallet_can_be_refreshed_from_the_page(): void
    {
        $this->actingAs($this->admin())->post(route('admin.consumption.wallet'))->assertSessionHas('error');

        $settings = app(PlatformSettings::class);
        $settings->set('whatsapp.twilio.account_sid', 'ACplatform');
        $settings->set('whatsapp.twilio.auth_token', 'platform-secret', secret: true);
        Http::fake(['api.twilio.com/*' => Http::response(['balance' => '99.00', 'currency' => 'USD'])]);
        $this->actingAs($this->admin())->post(route('admin.consumption.wallet'))->assertSessionHas('status', fn ($m) => str_contains($m, '99'));
    }

    /* ---------- Recharge de messages (Mobile Money) ---------- */

    public function test_staff_credits_messages_after_a_mobile_money_payment(): void
    {
        $this->actingAs($this->staff())->post(route('admin.wallet.topup', $this->workspace), [
            'messages' => 1000, 'amount' => 5000, 'method' => 'orange_money', 'reference' => 'OM-777',
        ])->assertSessionHas('status');

        $this->assertSame(1000, $this->workspace->fresh()->wa_credit);
        $payment = Payment::withoutGlobalScopes()->firstOrFail();
        $this->assertSame('recharge_whatsapp', $payment->plan);
        $this->assertSame(5000, $payment->amount);
        $this->assertSame('OM-777', $payment->reference);

        $this->actingAs($this->staff())->post(route('admin.wallet.topup', $this->workspace), ['messages' => 500, 'amount' => 2500, 'method' => 'wave']);
        $this->assertSame(1500, $this->workspace->fresh()->wa_credit, 'les recharges s\'additionnent');

        $this->actingAs($this->staff())->get(route('admin.workspaces.show', $this->workspace))->assertOk()->assertSee('Recharger des messages WhatsApp')->assertSee("1\u{202F}500", false);
    }

    public function test_a_topup_is_validated_and_reserved_to_staff(): void
    {
        $this->actingAs($this->staff())->post(route('admin.wallet.topup', $this->workspace), ['messages' => 0, 'amount' => 100, 'method' => 'wave'])->assertSessionHasErrors('messages');
        $this->actingAs($this->staff())->post(route('admin.wallet.topup', $this->workspace), ['messages' => 100, 'amount' => 100, 'method' => 'carte-bleue'])->assertSessionHasErrors('method');
        $this->actingAs($this->owner)->post(route('admin.wallet.topup', $this->workspace), ['messages' => 100, 'amount' => 100, 'method' => 'wave'])->assertForbidden();

        $this->assertSame(0, $this->workspace->fresh()->wa_credit);
    }
}
