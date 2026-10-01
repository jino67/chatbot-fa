<?php

namespace Tests\Feature;

use App\Channels\WhatsApp\MetaCloudGateway;
use App\Models\Bot;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Models\WhatsAppTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/** Modeles de messages WhatsApp (Meta et Twilio) et reponses rapides sous forme de boutons. */
class WhatsAppTemplatesTest extends TestCase
{
    use CreatesTenants;
    use RefreshDatabase;

    private Bot $bot;

    private User $owner;

    private const CUSTOMER = '22670123456';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        [, $this->owner, $this->bot] = $this->tenant('Boutique Test', 'pro');
    }

    private function metaChannel(): Channel
    {
        return Channel::withoutGlobalScopes()->create([
            'workspace_id' => $this->bot->workspace_id, 'bot_id' => $this->bot->id, 'type' => Channel::WHATSAPP_META,
            'status' => Channel::ACTIVE, 'display_phone' => '+226 70 00 00 00', 'external_ref' => '109876543210',
            'credentials' => ['access_token' => 'EAAtoken', 'waba_id' => '555'],
        ]);
    }

    private function twilioChannel(): Channel
    {
        return Channel::withoutGlobalScopes()->create([
            'workspace_id' => $this->bot->workspace_id, 'bot_id' => $this->bot->id, 'type' => Channel::WHATSAPP_TWILIO,
            'status' => Channel::ACTIVE, 'display_phone' => '+14155238886', 'external_ref' => '14155238886',
            'credentials' => ['account_sid' => 'ACtest', 'auth_token' => 'secret', 'from' => '+14155238886'],
        ]);
    }

    private function template(Channel $channel, array $overrides = []): WhatsAppTemplate
    {
        return WhatsAppTemplate::withoutGlobalScopes()->create($overrides + [
            'workspace_id' => $channel->workspace_id, 'channel_id' => $channel->id, 'external_id' => 'tpl-1', 'name' => 'suivi_commande',
            'language' => 'fr', 'category' => 'UTILITY', 'status' => WhatsAppTemplate::APPROVED,
            'body' => 'Bonjour {{1}}, votre commande {{2}} est en route.', 'variables_count' => 2,
        ]);
    }

    private function conversation(int $hoursSinceLastMessage = 30): Conversation
    {
        return Conversation::withoutGlobalScopes()->create([
            'workspace_id' => $this->bot->workspace_id, 'bot_id' => $this->bot->id, 'channel' => 'whatsapp',
            'external_id' => self::CUSTOMER, 'last_inbound_at' => now()->subHours($hoursSinceLastMessage),
        ]);
    }

    private function form(array $overrides = []): array
    {
        return $overrides + [
            'name' => 'suivi_commande', 'language' => 'fr', 'category' => 'UTILITY',
            'body' => 'Bonjour {{1}}, votre commande {{2}} est en route. Merci de votre confiance.',
            'body_examples' => ['Awa', 'CMD-12'],
        ];
    }

    /* ---------- Gestion des modeles par le client ---------- */

    public function test_a_pro_client_creates_a_template_that_is_submitted_to_meta(): void
    {
        $this->metaChannel();
        Http::fake(['graph.facebook.com/*/555/message_templates' => Http::response(['id' => 'meta-tpl-9', 'status' => 'PENDING', 'category' => 'UTILITY'])]);

        $this->actingAs($this->owner)->post(route('templates.store', $this->bot), $this->form(['footer' => 'Boutique Test', 'quick_replies' => "Suivre\nParler à quelqu'un"]))
            ->assertSessionHas('status');

        $template = WhatsAppTemplate::withoutGlobalScopes()->firstOrFail();
        $this->assertSame('PENDING', $template->status);
        $this->assertSame('meta-tpl-9', $template->external_id);
        $this->assertSame(2, $template->variables_count);

        Http::assertSent(function (HttpRequest $r) {
            $body = collect($r['components'])->firstWhere('type', 'BODY');
            $buttons = collect($r['components'])->firstWhere('type', 'BUTTONS');

            return $r['name'] === 'suivi_commande'
                && $r['category'] === 'UTILITY'
                && $body['example']['body_text'] === [['Awa', 'CMD-12']]
                && count($buttons['buttons']) === 2
                && collect($r['components'])->firstWhere('type', 'FOOTER')['text'] === 'Boutique Test';
        });
    }

    public function test_template_validation_follows_the_rules_meta_enforces(): void
    {
        $this->metaChannel();
        Http::fake();
        $client = $this->actingAs($this->owner);

        $client->post(route('templates.store', $this->bot), $this->form(['name' => 'Suivi Commande']))->assertSessionHasErrors('name');
        $client->post(route('templates.store', $this->bot), $this->form(['category' => 'AUTHENTICATION']))->assertSessionHasErrors('category');
        $client->post(route('templates.store', $this->bot), $this->form(['body' => '{{1}} votre commande est en route', 'body_examples' => ['Awa']]))->assertSessionHasErrors('body');
        $client->post(route('templates.store', $this->bot), $this->form(['body' => 'Votre commande est arrivée {{1}}', 'body_examples' => ['Awa']]))->assertSessionHasErrors('body');
        $client->post(route('templates.store', $this->bot), $this->form(['body' => 'Bonjour {{1}}, commande {{3}} en route.', 'body_examples' => ['A', 'B', 'C']]))->assertSessionHasErrors('body');
        $client->post(route('templates.store', $this->bot), $this->form(['body_examples' => ['Awa']]))->assertSessionHasErrors('body_examples');
        $client->post(route('templates.store', $this->bot), $this->form(['phone' => '70123456', 'phone_text' => 'Appeler']))->assertSessionHasErrors('phone');

        Http::assertNothingSent();
        $this->assertSame(0, WhatsAppTemplate::withoutGlobalScopes()->count());
    }

    public function test_templates_are_a_paid_option(): void
    {
        [, $essential, $bot] = $this->tenant('Petit', 'essentiel');
        Channel::withoutGlobalScopes()->create([
            'workspace_id' => $bot->workspace_id, 'bot_id' => $bot->id, 'type' => Channel::WHATSAPP_META, 'status' => Channel::ACTIVE,
            'external_ref' => '1', 'credentials' => ['access_token' => 'x', 'waba_id' => '9'],
        ]);
        Http::fake();

        $this->actingAs($essential)->get(route('templates.index', $bot))->assertOk()->assertSee('Voir les offres');
        $this->actingAs($essential)->post(route('templates.store', $bot), $this->form())->assertForbidden();
        $this->actingAs($essential)->post(route('templates.sync', $bot))->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_without_an_active_whatsapp_channel_the_page_invites_to_request_it(): void
    {
        $this->actingAs($this->owner)->get(route('templates.index', $this->bot))->assertOk()->assertSee("WhatsApp n'est pas encore actif", false);
        $this->actingAs($this->owner)->post(route('templates.store', $this->bot), $this->form())->assertStatus(422);
    }

    public function test_sync_copies_meta_templates_with_their_approval_status(): void
    {
        $channel = $this->metaChannel();
        Http::fake(['graph.facebook.com/*/message_templates*' => Http::response(['data' => [
            ['id' => '1', 'name' => 'rappel_rdv', 'status' => 'APPROVED', 'category' => 'UTILITY', 'language' => 'fr', 'components' => [['type' => 'BODY', 'text' => 'Rappel : rendez-vous le {{1}} à {{2}}.']]],
            ['id' => '2', 'name' => 'promo_rentree', 'status' => 'REJECTED', 'category' => 'MARKETING', 'language' => 'fr', 'rejected_reason' => 'INVALID_FORMAT', 'components' => [['type' => 'BODY', 'text' => 'Promo !']]],
        ]])]);

        $this->actingAs($this->owner)->post(route('templates.sync', $this->bot))->assertSessionHas('status', fn ($m) => str_contains($m, '2 modèle'));

        $rappel = WhatsAppTemplate::withoutGlobalScopes()->where('name', 'rappel_rdv')->firstOrFail();
        $this->assertTrue($rappel->isApproved());
        $this->assertSame(2, $rappel->variables_count);
        $this->assertSame('Rappel : rendez-vous le lundi à 10 h.', $rappel->preview(['lundi', '10 h']));

        $promo = WhatsAppTemplate::withoutGlobalScopes()->where('name', 'promo_rentree')->firstOrFail();
        $this->assertSame('REJECTED', $promo->status);
        $this->assertSame('INVALID_FORMAT', $promo->rejected_reason);
        $this->assertSame('red', $promo->statusTone());

        // Une seconde synchronisation met a jour au lieu de dupliquer.
        $this->actingAs($this->owner)->post(route('templates.sync', $this->bot));
        $this->assertSame(2, WhatsAppTemplate::withoutGlobalScopes()->where('channel_id', $channel->id)->count());

        $this->actingAs($this->owner)->get(route('templates.index', $this->bot))->assertOk()->assertSee('rappel_rdv')->assertSee('INVALID_FORMAT');
    }

    public function test_a_meta_error_is_reported_instead_of_a_server_error(): void
    {
        $this->metaChannel();
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'Invalid OAuth access token', 'error_user_msg' => 'Le jeton a expiré.']], 401)]);

        $this->actingAs($this->owner)->post(route('templates.sync', $this->bot))->assertSessionHas('error', fn ($m) => str_contains($m, 'Le jeton a expiré.'));
        $this->actingAs($this->owner)->post(route('templates.store', $this->bot), $this->form())->assertSessionHas('error');
    }

    public function test_a_missing_waba_id_gives_an_explicit_message(): void
    {
        $channel = $this->metaChannel();
        $channel->update(['credentials' => ['access_token' => 'EAAtoken']]);
        Http::fake();

        $this->actingAs($this->owner)->post(route('templates.sync', $this->bot))->assertSessionHas('error', fn ($m) => str_contains($m, 'WABA'));
        Http::assertNothingSent();
    }

    public function test_deleting_a_template_removes_it_at_meta_and_locally_and_only_for_its_owner(): void
    {
        $channel = $this->metaChannel();
        $template = $this->template($channel);
        Http::fake(['graph.facebook.com/*' => Http::response(['success' => true])]);

        [, $stranger, $strangerBot] = $this->tenant('Autre boutique', 'pro');
        $this->actingAs($stranger)->delete(route('templates.destroy', [$strangerBot, $template]))->assertNotFound();
        $this->assertNotNull($template->fresh());

        $this->actingAs($this->owner)->delete(route('templates.destroy', [$this->bot, $template]))->assertSessionHas('status');

        $this->assertNull(WhatsAppTemplate::withoutGlobalScopes()->find($template->id));
        Http::assertSent(fn (HttpRequest $r) => $r->method() === 'DELETE' && str_contains($r->url(), 'name=suivi_commande'));
    }

    public function test_a_client_never_sees_another_clients_templates(): void
    {
        $channel = $this->metaChannel();
        $this->template($channel, ['name' => 'secret_du_client_a']);
        [, $stranger, $strangerBot] = $this->tenant('Autre boutique', 'pro');

        $this->actingAs($stranger)->get(route('templates.index', $strangerBot))->assertOk()->assertDontSee('secret_du_client_a');
    }

    /* ---------- Envoi depuis une conversation ---------- */

    public function test_an_approved_template_reopens_a_conversation_after_24_hours(): void
    {
        $channel = $this->metaChannel();
        $template = $this->template($channel);
        $conversation = $this->conversation();
        Http::fake(['graph.facebook.com/*/messages' => Http::response(['messages' => [['id' => 'wamid.OUT1']]])]);

        // Le message libre est refuse, le modele est accepte.
        $this->actingAs($this->owner)->post(route('conversations.reply', [$this->bot, $conversation]), ['content' => 'Bonjour'])->assertSessionHas('error');

        $this->actingAs($this->owner)->post(route('conversations.template', [$this->bot, $conversation]), [
            'template_id' => $template->id, 'variables' => ["Awa\nOuédraogo", 'CMD-12'],
        ])->assertSessionHas('status');

        Http::assertSent(function (HttpRequest $r) {
            $params = $r['template']['components'][0]['parameters'] ?? [];

            return $r['type'] === 'template'
                && $r['to'] === self::CUSTOMER
                && $r['template']['name'] === 'suivi_commande'
                && $r['template']['language']['code'] === 'fr'
                && $params[0]['text'] === 'Awa Ouédraogo' // les retours a la ligne sont refuses par Meta
                && $params[1]['text'] === 'CMD-12';
        });

        $message = $conversation->messages()->firstOrFail();
        $this->assertSame(Message::AGENT, $message->role);
        $this->assertStringContainsString('CMD-12', $message->content);
        $this->assertSame('suivi_commande', $message->meta['template']);
        $this->assertSame('wamid.OUT1', $message->provider_message_id);
        $this->assertSame(Conversation::HUMAN, $conversation->fresh()->status);
    }

    public function test_a_template_cannot_be_sent_when_unapproved_or_incomplete(): void
    {
        $channel = $this->metaChannel();
        $pending = $this->template($channel, ['name' => 'en_attente', 'status' => WhatsAppTemplate::PENDING]);
        $approved = $this->template($channel, ['name' => 'approuve', 'external_id' => 'tpl-2']);
        $conversation = $this->conversation();
        Http::fake();
        $client = $this->actingAs($this->owner);

        $client->post(route('conversations.template', [$this->bot, $conversation]), ['template_id' => $pending->id, 'variables' => ['A', 'B']])->assertSessionHas('error', fn ($m) => str_contains($m, 'approuvé'));
        $client->post(route('conversations.template', [$this->bot, $conversation]), ['template_id' => $approved->id, 'variables' => ['A', '']])->assertSessionHas('error', fn ($m) => str_contains($m, 'variables'));
        $client->post(route('conversations.template', [$this->bot, $conversation]), ['template_id' => $approved->id])->assertSessionHas('error');

        Http::assertNothingSent();
        $this->assertSame(0, $conversation->messages()->count());
    }

    public function test_a_template_of_another_client_cannot_be_used(): void
    {
        [, , $otherBot] = $this->tenant('Autre boutique', 'pro');
        $otherChannel = Channel::withoutGlobalScopes()->create([
            'workspace_id' => $otherBot->workspace_id, 'bot_id' => $otherBot->id, 'type' => Channel::WHATSAPP_META, 'status' => Channel::ACTIVE,
            'external_ref' => '2', 'credentials' => ['access_token' => 'x', 'waba_id' => '8'],
        ]);
        $foreign = $this->template($otherChannel, ['name' => 'etranger']);
        $this->metaChannel();
        Http::fake();

        $this->actingAs($this->owner)->post(route('conversations.template', [$this->bot, $this->conversation()]), ['template_id' => $foreign->id, 'variables' => ['A', 'B']])->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_the_conversation_page_offers_approved_templates_once_the_window_is_closed(): void
    {
        $channel = $this->metaChannel();
        $this->template($channel, ['name' => 'suivi_commande']);
        $this->template($channel, ['name' => 'brouillon_refuse', 'status' => WhatsAppTemplate::REJECTED, 'external_id' => 'x']);
        $closed = $this->conversation(30);
        $open = $this->conversation(1);
        $open->update(['external_id' => '22671111111']);

        $this->actingAs($this->owner)->get(route('conversations.show', [$this->bot, $closed]))
            ->assertOk()->assertSee('suivi_commande')->assertDontSee('brouillon_refuse')->assertSee('WhatsApp n\'autorise plus de message libre', false);

        $this->actingAs($this->owner)->get(route('conversations.show', [$this->bot, $open]))
            ->assertOk()->assertSee('Répondre en tant que conseiller');
    }

    /* ---------- Twilio ---------- */

    public function test_twilio_templates_are_synced_and_sent_by_content_sid(): void
    {
        $channel = $this->twilioChannel();
        Http::fake([
            // Création : le contenu, puis la demande d'approbation WhatsApp (avant le motif général, le premier qui correspond gagne).
            'content.twilio.com/v1/Content/*/ApprovalRequests/whatsapp' => Http::response(['status' => 'received'], 201),
            'content.twilio.com/v1/Content' => Http::response(['sid' => 'HXnew'], 201),
            'content.twilio.com/*' => Http::response(['contents' => [[
                'sid' => 'HX123', 'friendly_name' => 'suivi', 'language' => 'fr', 'types' => ['twilio/text' => ['body' => 'Bonjour {{1}}, colis {{2}} expédié.']],
                'approval_requests' => ['whatsapp' => ['name' => 'suivi_colis', 'category' => 'utility', 'status' => 'approved']],
            ]]]),
            'api.twilio.com/*' => Http::response(['sid' => 'SM999']),
        ]);

        $this->actingAs($this->owner)->post(route('templates.sync', $this->bot))->assertSessionHas('status');
        $template = WhatsAppTemplate::withoutGlobalScopes()->where('channel_id', $channel->id)->firstOrFail();
        $this->assertSame('suivi_colis', $template->name);
        $this->assertSame('HX123', $template->external_id);
        $this->assertTrue($template->isApproved());

        // La creation passe par l'API Content de Twilio, puis une demande d'approbation WhatsApp.
        $this->actingAs($this->owner)->post(route('templates.store', $this->bot), $this->form())->assertSessionHas('status');
        $created = WhatsAppTemplate::withoutGlobalScopes()->where('channel_id', $channel->id)->where('external_id', 'HXnew')->firstOrFail();
        $this->assertSame('PENDING', $created->status);
        Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), '/Content/HXnew/ApprovalRequests/whatsapp') && $r['name'] === $created->name);

        $response = $this->actingAs($this->owner)->post(route('conversations.template', [$this->bot, $this->conversation()]), ['template_id' => $template->id, 'variables' => ['Awa', 'CMD-7']]);
        $this->assertNull(session('error'), (string) session('error'));
        $response->assertSessionHas('status');
        Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), 'Messages.json')
            && $r['ContentSid'] === 'HX123'
            && json_decode($r['ContentVariables'], true) === ['1' => 'Awa', '2' => 'CMD-7']
            && $r['To'] === 'whatsapp:+'.self::CUSTOMER);
    }

    /* ---------- Reponses rapides : boutons interactifs ---------- */

    public function test_quick_replies_become_at_most_three_short_whatsapp_buttons(): void
    {
        $channel = $this->metaChannel();
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.B1']]])]);

        (new MetaCloudGateway($channel))->sendText(self::CUSTOMER, 'Voulez-vous voir nos tarifs ?', ['Voir les tarifs', 'Commander', 'Voir les tarifs', 'Parler à quelqu\'un de l\'équipe technique', 'Quatrième choix']);

        Http::assertSent(function (HttpRequest $r) {
            $buttons = $r['interactive']['action']['buttons'] ?? [];

            return $r['type'] === 'interactive'
                && $r['interactive']['type'] === 'button'
                && count($buttons) === 3
                && collect($buttons)->every(fn ($b) => mb_strlen($b['reply']['title']) <= 20)
                && $buttons[0]['reply']['title'] === 'Voir les tarifs'
                && collect($buttons)->pluck('reply.id')->unique()->count() === 3;
        });
    }

    public function test_without_quick_replies_or_with_a_very_long_text_a_plain_message_is_sent(): void
    {
        $channel = $this->metaChannel();
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.T1']]])]);
        $gateway = new MetaCloudGateway($channel);

        $gateway->sendText(self::CUSTOMER, 'Bonjour');
        $gateway->sendText(self::CUSTOMER, str_repeat('a', 1100), ['Oui']);

        Http::assertSentCount(2);
        Http::assertSent(fn (HttpRequest $r) => $r['type'] === 'text');
        Http::assertNotSent(fn (HttpRequest $r) => $r['type'] === 'interactive');
    }

    public function test_the_ai_replies_suggestions_are_delivered_as_buttons_on_the_last_part_only(): void
    {
        $channel = $this->metaChannel();
        $conversation = $this->conversation(1);
        $message = $conversation->messages()->create([
            'workspace_id' => $this->bot->workspace_id, 'role' => Message::ASSISTANT, 'content' => 'La livraison coûte **2 000 FCFA**.',
            'meta' => ['grounded' => true, 'llm' => true, 'suggestions' => ['Commander', 'Autres villes']],
        ]);
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT2']]])]);

        app(\App\Channels\WhatsApp\InboundHandler::class)->deliver(app(\App\Channels\WhatsApp\GatewayFactory::class)->for($channel), $conversation, $message);

        Http::assertSent(fn (HttpRequest $r) => $r['type'] === 'interactive' && str_contains($r['interactive']['body']['text'], '2 000 FCFA') && count($r['interactive']['action']['buttons']) === 2);
        $this->assertSame('wamid.OUT2', $message->fresh()->provider_message_id);
    }
}
