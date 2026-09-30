<?php

namespace Tests\Feature;

use App\Channels\WhatsApp\MetaCloudGateway;
use App\Models\Bot;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class WhatsAppTest extends TestCase
{
    use CreatesTenants;
    use RefreshDatabase;

    private Bot $bot;

    private User $owner;

    private const PHONE_NUMBER_ID = '109876543210';

    private const CUSTOMER = '22670123456';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        [, $this->owner, $this->bot] = $this->tenant();
        $this->teach($this->bot, 'Horaires', "# Horaires\nLa boutique est ouverte du lundi au samedi de 8 h à 19 h.");
    }

    private function metaChannel(string $status = Channel::ACTIVE): Channel
    {
        return Channel::withoutGlobalScopes()->create([
            'workspace_id' => $this->bot->workspace_id, 'bot_id' => $this->bot->id, 'type' => Channel::WHATSAPP_META,
            'status' => $status, 'display_phone' => '+226 70 00 00 00', 'external_ref' => self::PHONE_NUMBER_ID,
            'credentials' => ['access_token' => 'EAAtoken-tres-secret', 'waba_id' => '555'],
        ]);
    }

    private function twilioChannel(): Channel
    {
        return Channel::withoutGlobalScopes()->create([
            'workspace_id' => $this->bot->workspace_id, 'bot_id' => $this->bot->id, 'type' => Channel::WHATSAPP_TWILIO,
            'status' => Channel::ACTIVE, 'display_phone' => '+14155238886', 'external_ref' => '14155238886',
            'credentials' => ['account_sid' => 'ACtest', 'auth_token' => 'twilio-secret', 'from' => '+14155238886'],
        ]);
    }

    private function metaPayload(string $text, string $id = 'wamid.IN1', string $type = 'text', ?string $phoneNumberId = null): array
    {
        $message = ['from' => self::CUSTOMER, 'id' => $id, 'timestamp' => (string) time(), 'type' => $type];
        $message[$type] = $type === 'text' ? ['body' => $text] : ['id' => 'media-1', 'mime_type' => 'image/jpeg'];

        return ['object' => 'whatsapp_business_account', 'entry' => [['id' => '555', 'changes' => [['field' => 'messages', 'value' => [
            'messaging_product' => 'whatsapp',
            'metadata' => ['display_phone_number' => '22670000000', 'phone_number_id' => $phoneNumberId ?? self::PHONE_NUMBER_ID],
            'contacts' => [['profile' => ['name' => 'Fatou'], 'wa_id' => self::CUSTOMER]],
            'messages' => [$message],
        ]]]]]];
    }

    private function postMeta(array $payload, ?string $signature = 'valid')
    {
        $body = json_encode($payload);
        $server = ['CONTENT_TYPE' => 'application/json'];
        if ($signature === 'valid') {
            $server['HTTP_X_HUB_SIGNATURE_256'] = 'sha256='.hash_hmac('sha256', $body, 'test-app-secret');
        } elseif ($signature !== null) {
            $server['HTTP_X_HUB_SIGNATURE_256'] = $signature;
        }

        return $this->call('POST', '/webhooks/whatsapp/meta', [], [], [], $server, $body);
    }

    private function fakeGraph(): void
    {
        // Une reponse fraiche (identifiant unique) a chaque appel, comme le ferait Meta.
        Http::fake(['graph.facebook.com/*' => fn () => Http::response(['messages' => [['id' => 'wamid.OUT'.uniqid('', true)]]], 200)]);
    }

    private function textMessagesSent(): array
    {
        return Http::recorded(fn (HttpRequest $r) => str_contains($r->url(), '/messages') && isset($r['text']))
            ->map(fn ($pair) => $pair[0])->values()->all();
    }

    // ---- Meta : verification et securite du webhook -------------------------------------------

    public function test_meta_subscription_handshake_echoes_the_challenge_only_with_the_right_token(): void
    {
        $this->get('/webhooks/whatsapp/meta?hub.mode=subscribe&hub.verify_token=test-verify-token&hub.challenge=DEFI123')
            ->assertOk()->assertSee('DEFI123', false);

        $this->get('/webhooks/whatsapp/meta?hub.mode=subscribe&hub.verify_token=mauvais&hub.challenge=DEFI123')->assertForbidden();
        $this->get('/webhooks/whatsapp/meta?hub.mode=subscribe&hub.challenge=DEFI123')->assertForbidden();
    }

    public function test_unsigned_or_wrongly_signed_meta_calls_are_rejected_and_nothing_is_processed(): void
    {
        $this->metaChannel();
        $this->fakeGraph();

        $this->postMeta($this->metaPayload('Bonjour'), null)->assertUnauthorized();
        $this->postMeta($this->metaPayload('Bonjour'), 'sha256=deadbeef')->assertUnauthorized();

        $this->assertDatabaseCount('messages', 0);
        $this->assertDatabaseCount('conversations', 0);
        Http::assertNothingSent();
    }

    // ---- Meta : traitement ---------------------------------------------------------------------

    public function test_an_incoming_meta_message_gets_an_answer_sent_back_through_the_graph_api(): void
    {
        $this->metaChannel();
        $this->fakeGraph();

        $this->postMeta($this->metaPayload("Quels sont les horaires d'ouverture de la boutique le samedi ?"))->assertOk();

        $conversation = Conversation::withoutGlobalScopes()->firstOrFail();
        $this->assertSame('whatsapp', $conversation->channel);
        $this->assertSame(self::CUSTOMER, $conversation->external_id);
        $this->assertSame('Fatou', $conversation->contact_name);
        $this->assertSame('+'.self::CUSTOMER, $conversation->contact_phone);
        $this->assertSame(['user', 'assistant'], $conversation->messages()->orderBy('id')->pluck('role')->all());

        $sent = $this->textMessagesSent();
        $this->assertCount(1, $sent);
        $this->assertStringContainsString('/'.self::PHONE_NUMBER_ID.'/messages', $sent[0]->url());
        $this->assertSame(self::CUSTOMER, $sent[0]['to']);
        $this->assertStringContainsString('8 h', $sent[0]['text']['body']);
        $this->assertTrue($sent[0]->hasHeader('Authorization', 'Bearer EAAtoken-tres-secret'));

        // Accuse de lecture envoye aussi.
        Http::assertSent(fn (HttpRequest $r) => ($r['status'] ?? null) === 'read' && $r['message_id'] === 'wamid.IN1');
    }

    public function test_a_redelivered_webhook_is_processed_only_once(): void
    {
        $this->metaChannel();
        $this->fakeGraph();
        $payload = $this->metaPayload("Quels sont les horaires d'ouverture de la boutique le samedi ?", 'wamid.MEME');

        $this->postMeta($payload)->assertOk();
        $this->postMeta($payload)->assertOk();

        $conversation = Conversation::withoutGlobalScopes()->firstOrFail();
        $this->assertSame(1, $conversation->messages()->where('role', 'user')->count());
        $this->assertCount(1, $this->textMessagesSent(), 'un seul envoi malgre la double livraison');
    }

    public function test_messages_to_an_unknown_or_inactive_number_are_ignored_with_a_200(): void
    {
        $this->metaChannel(Channel::DISABLED);
        $this->fakeGraph();

        $this->postMeta($this->metaPayload('Bonjour'))->assertOk();
        $this->postMeta($this->metaPayload('Bonjour', 'wamid.X2', 'text', '999999'))->assertOk();

        $this->assertDatabaseCount('conversations', 0);
        Http::assertNothingSent();
    }

    public function test_delivery_status_callbacks_do_not_break_the_webhook(): void
    {
        $this->metaChannel();
        $payload = ['object' => 'whatsapp_business_account', 'entry' => [['changes' => [['field' => 'messages', 'value' => [
            'metadata' => ['phone_number_id' => self::PHONE_NUMBER_ID],
            'statuses' => [['id' => 'wamid.OUT1', 'status' => 'delivered', 'recipient_id' => self::CUSTOMER]],
        ]]]]]];

        $this->postMeta($payload)->assertOk();
        $this->assertDatabaseCount('conversations', 0);
    }

    public function test_non_text_messages_get_a_polite_answer_instead_of_silence(): void
    {
        $this->metaChannel();
        $this->fakeGraph();

        $this->postMeta($this->metaPayload('', 'wamid.IMG1', 'image'))->assertOk();

        $sent = $this->textMessagesSent();
        $this->assertCount(1, $sent);
        $this->assertStringContainsString('messages écrits', $sent[0]['text']['body']);
    }

    public function test_the_bot_stays_silent_on_whatsapp_when_a_human_has_taken_over(): void
    {
        $this->metaChannel();
        $this->fakeGraph();
        $this->postMeta($this->metaPayload('Bonjour', 'wamid.A'))->assertOk();
        Conversation::withoutGlobalScopes()->firstOrFail()->update(['status' => Conversation::HUMAN]);
        $before = count($this->textMessagesSent());

        $this->postMeta($this->metaPayload('Je veux payer par Orange Money', 'wamid.B'))->assertOk();

        $this->assertCount($before, $this->textMessagesSent(), 'aucune reponse automatique');
        $this->assertSame(2, Message::withoutGlobalScopes()->where('role', 'user')->count(), 'mais le message est conserve');
    }

    public function test_a_delivery_failure_is_recorded_and_does_not_lose_the_answer(): void
    {
        $this->metaChannel();
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'Token expiré']], 401)]);

        $this->postMeta($this->metaPayload("Quels sont les horaires d'ouverture de la boutique le samedi ?"))->assertOk();

        $reply = Message::withoutGlobalScopes()->where('role', 'assistant')->firstOrFail();
        $this->assertStringContainsString('Token expiré', $reply->meta['delivery_error']);
    }

    public function test_agent_replies_from_the_dashboard_reach_the_customer_on_whatsapp(): void
    {
        $this->metaChannel();
        $this->fakeGraph();
        $this->postMeta($this->metaPayload('Bonjour', 'wamid.A'))->assertOk();
        $conversation = Conversation::withoutGlobalScopes()->firstOrFail();

        $this->actingAs($this->owner)
            ->post(route('conversations.reply', [$this->bot, $conversation]), ['content' => 'Bonjour, c\'est Awa, je vous appelle.'])
            ->assertSessionHas('status');

        $this->assertSame(Conversation::HUMAN, $conversation->fresh()->status);
        $sent = $this->textMessagesSent();
        $last = end($sent);
        $this->assertSame('Bonjour, c\'est Awa, je vous appelle.', $last['text']['body']);
    }

    public function test_free_text_is_refused_once_the_24_hour_whatsapp_window_is_closed(): void
    {
        $this->metaChannel();
        $this->fakeGraph();
        $conversation = Conversation::withoutGlobalScopes()->create([
            'workspace_id' => $this->bot->workspace_id, 'bot_id' => $this->bot->id, 'channel' => 'whatsapp',
            'external_id' => self::CUSTOMER, 'last_inbound_at' => now()->subHours(30),
        ]);

        $this->actingAs($this->owner)
            ->post(route('conversations.reply', [$this->bot, $conversation]), ['content' => 'Bonjour'])
            ->assertSessionHas('error');

        Http::assertNothingSent();
        $this->assertSame(0, $conversation->messages()->count());
    }

    public function test_channel_credentials_are_encrypted_at_rest_and_never_serialized(): void
    {
        $channel = $this->metaChannel();

        $raw = DB::table('channels')->where('id', $channel->id)->value('credentials');
        $this->assertStringNotContainsString('EAAtoken-tres-secret', $raw);
        $this->assertSame('EAAtoken-tres-secret', $channel->fresh()->credential('access_token'));
        $this->assertArrayNotHasKey('credentials', $channel->fresh()->toArray());
    }

    public function test_meta_payload_parser_normalizes_text_interactive_and_media_messages(): void
    {
        $parsed = MetaCloudGateway::parse($this->metaPayload('Salut', 'wamid.P1'));

        $this->assertCount(1, $parsed);
        $this->assertSame('text', $parsed[0]->type);
        $this->assertSame('Salut', $parsed[0]->text);
        $this->assertSame(self::PHONE_NUMBER_ID, $parsed[0]->channelRef);
        $this->assertSame('Fatou', $parsed[0]->name);

        $payload = $this->metaPayload('', 'wamid.P2');
        $payload['entry'][0]['changes'][0]['value']['messages'][0] = [
            'from' => self::CUSTOMER, 'id' => 'wamid.P2', 'type' => 'interactive',
            'interactive' => ['type' => 'button_reply', 'button_reply' => ['id' => 'b1', 'title' => 'Oui']],
        ];
        $this->assertSame('Oui', MetaCloudGateway::parse($payload)[0]->text);
    }

    // ---- Twilio ---------------------------------------------------------------------------------

    private function twilioSignature(string $url, array $params, string $token = 'twilio-secret'): string
    {
        ksort($params);
        $data = $url;
        foreach ($params as $k => $v) {
            $data .= $k.$v;
        }

        return base64_encode(hash_hmac('sha1', $data, $token, true));
    }

    private function twilioParams(string $body = "Quels sont les horaires d'ouverture de la boutique le samedi ?"): array
    {
        return ['MessageSid' => 'SMabc123', 'From' => 'whatsapp:+'.self::CUSTOMER, 'To' => 'whatsapp:+14155238886', 'Body' => $body, 'ProfileName' => 'Fatou', 'NumMedia' => '0'];
    }

    public function test_a_signed_twilio_message_is_answered_through_the_twilio_rest_api(): void
    {
        $channel = $this->twilioChannel();
        Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'SMout1'], 201)]);
        $params = $this->twilioParams();
        $url = url("/webhooks/whatsapp/twilio/{$channel->id}");

        $this->post($url, $params, ['X-Twilio-Signature' => $this->twilioSignature($url, $params)])
            ->assertOk()->assertHeader('Content-Type', 'text/xml; charset=UTF-8');

        Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), '/Accounts/ACtest/Messages.json')
            && $r['To'] === 'whatsapp:+'.self::CUSTOMER
            && $r['From'] === 'whatsapp:+14155238886'
            && str_contains($r['Body'], '8 h'));
        $this->assertSame(1, Message::withoutGlobalScopes()->where('role', 'assistant')->count());
    }

    public function test_twilio_calls_with_a_bad_signature_are_rejected(): void
    {
        $channel = $this->twilioChannel();
        Http::fake();
        $url = url("/webhooks/whatsapp/twilio/{$channel->id}");

        $this->post($url, $this->twilioParams(), ['X-Twilio-Signature' => 'faux'])->assertUnauthorized();
        $this->post($url, $this->twilioParams())->assertUnauthorized();

        $tampered = $this->twilioParams('Message modifié');
        $this->post($url, $tampered, ['X-Twilio-Signature' => $this->twilioSignature($url, $this->twilioParams())])->assertUnauthorized();

        Http::assertNothingSent();
        $this->assertDatabaseCount('conversations', 0);
    }

    public function test_a_twilio_webhook_cannot_target_a_meta_channel(): void
    {
        $meta = $this->metaChannel();

        $this->post("/webhooks/whatsapp/twilio/{$meta->id}", $this->twilioParams())->assertNotFound();
    }
}
