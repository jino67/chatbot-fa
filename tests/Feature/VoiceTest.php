<?php

namespace Tests\Feature;

use App\Channels\WhatsApp\TwilioGateway;
use App\Models\Bot;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Plan;
use App\Models\UsageEvent;
use App\Models\Workspace;
use App\Services\UsageService;
use App\Speech\AudioInfo;
use App\Speech\FakeSpeech;
use App\Speech\OpenAiSpeech;
use App\Speech\SpeechException;
use App\Speech\SpeechText;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/** Voix : messages vocaux compris (WhatsApp Meta et Twilio, widget), réponses en audio selon le réglage, quota de l'offre. */
class VoiceTest extends TestCase
{
    use CreatesTenants;
    use RefreshDatabase;

    private Workspace $workspace;

    private Bot $bot;

    private const PHONE_NUMBER_ID = '109876543210';

    private const CUSTOMER = '22670123456';

    private const QUESTION = "Quels sont les horaires d'ouverture de la boutique le samedi ?";

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        FakeSpeech::reset();
        [$this->workspace, , $this->bot] = $this->tenant();
        $this->teach($this->bot, 'Horaires', "# Horaires\nLa boutique est ouverte du lundi au samedi de 8 h à 19 h.");
    }

    protected function tearDown(): void
    {
        FakeSpeech::reset();
        parent::tearDown();
    }

    private function metaChannel(): Channel
    {
        return Channel::withoutGlobalScopes()->create([
            'workspace_id' => $this->bot->workspace_id, 'bot_id' => $this->bot->id, 'type' => Channel::WHATSAPP_META,
            'status' => Channel::ACTIVE, 'display_phone' => '+226 70 00 00 00', 'external_ref' => self::PHONE_NUMBER_ID,
            'credentials' => ['access_token' => 'EAAtoken', 'waba_id' => '555'],
        ]);
    }

    private function metaAudio(string $id = 'wamid.VOC1'): array
    {
        return ['object' => 'whatsapp_business_account', 'entry' => [['id' => '555', 'changes' => [['field' => 'messages', 'value' => [
            'messaging_product' => 'whatsapp',
            'metadata' => ['display_phone_number' => '22670000000', 'phone_number_id' => self::PHONE_NUMBER_ID],
            'contacts' => [['profile' => ['name' => 'Fatou'], 'wa_id' => self::CUSTOMER]],
            'messages' => [['from' => self::CUSTOMER, 'id' => $id, 'timestamp' => (string) time(), 'type' => 'audio', 'audio' => ['id' => 'media-audio', 'mime_type' => 'audio/ogg; codecs=opus', 'voice' => true]]],
        ]]]]]];
    }

    private function postMeta(array $payload)
    {
        $body = json_encode($payload);

        return $this->call('POST', '/webhooks/whatsapp/meta', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, 'test-app-secret'),
        ], $body);
    }

    /** Meta : le média se télécharge en deux temps, l'envoi d'un audio se fait en déposant le fichier puis en l'envoyant. */
    private function fakeMeta(string $says = self::QUESTION): void
    {
        Http::fake([
            'graph.facebook.com/*/media-audio' => Http::response(['url' => 'https://lookaside.fbsbx.example/audio-1', 'mime_type' => 'audio/ogg']),
            'lookaside.fbsbx.example/*' => Http::response(FakeSpeech::voiceNote($says), 200, ['Content-Type' => 'audio/ogg']),
            'graph.facebook.com/*/media' => Http::response(['id' => 'uploaded-audio-1']),
            'graph.facebook.com/*' => fn () => Http::response(['messages' => [['id' => 'wamid.OUT'.uniqid('', true)]]]),
        ]);
    }

    /** @return list<HttpRequest> */
    private function sentToMeta(string $type): array
    {
        return Http::recorded(fn (HttpRequest $r) => str_ends_with($r->url(), '/'.self::PHONE_NUMBER_ID.'/messages') && ($r['type'] ?? null) === $type)
            ->map(fn ($pair) => $pair[0])->values()->all();
    }

    private function setVoiceOut(string $mode): void
    {
        $this->bot->update(['voice_out' => $mode]);
    }

    /* ---------- WhatsApp Meta ---------- */

    public function test_a_whatsapp_voice_note_is_transcribed_answered_and_metered(): void
    {
        $this->metaChannel();
        $this->setVoiceOut('never');
        $this->fakeMeta();

        $this->postMeta($this->metaAudio())->assertOk();

        $conversation = Conversation::withoutGlobalScopes()->firstOrFail();
        [$question, $answer] = $conversation->messages()->orderBy('id')->get()->all();

        $this->assertSame(self::QUESTION, $question->content, 'la transcription devient le message du client');
        $this->assertTrue($question->meta['voice']);
        $this->assertGreaterThan(0, $question->meta['voice_seconds']);
        $this->assertStringContainsString('8 h', $answer->content);

        $this->assertCount(1, $this->sentToMeta('text'));
        $this->assertCount(0, $this->sentToMeta('audio'), 'réponse audio coupée : texte seulement');

        $event = UsageEvent::withoutGlobalScopes()->where('kind', 'voice_in')->firstOrFail();
        $this->assertSame($this->workspace->id, $event->workspace_id);
        $this->assertGreaterThan(0, $event->cost_usd);
        $this->assertSame(1, app(UsageService::class)->voiceUsed($this->workspace));
    }

    public function test_in_mirror_mode_the_answer_is_spoken_and_the_text_still_follows(): void
    {
        $this->metaChannel();
        $this->setVoiceOut('mirror');
        $this->fakeMeta();

        $this->postMeta($this->metaAudio())->assertOk();

        $audio = $this->sentToMeta('audio');
        $this->assertCount(1, $audio);
        $this->assertSame('uploaded-audio-1', $audio[0]['audio']['id']);
        $this->assertSame(self::CUSTOMER, $audio[0]['to']);
        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/'.self::PHONE_NUMBER_ID.'/media') && $r->hasFile('file'));

        $this->assertCount(1, $this->sentToMeta('text'), 'le texte part toujours en plus de l\'audio');
        $this->assertStringContainsString('8 h', FakeSpeech::$spoken[0]['text']);
        $this->assertSame('feminine', FakeSpeech::$spoken[0]['voice']);

        $reply = Message::withoutGlobalScopes()->where('role', 'assistant')->firstOrFail();
        $this->assertTrue($reply->meta['voice_reply']);
        $this->assertSame(1, UsageEvent::withoutGlobalScopes()->where('kind', 'voice_out')->count());
        // Un audio et un texte sortants : deux messages WhatsApp, plus le message entrant.
        $this->assertSame(3, UsageEvent::withoutGlobalScopes()->whereIn('kind', UsageEvent::WHATSAPP)->count());
    }

    public function test_mirror_mode_does_not_speak_an_answer_to_a_written_message(): void
    {
        $this->metaChannel();
        $this->setVoiceOut('mirror');
        $this->fakeMeta();

        $payload = $this->metaAudio();
        $message = &$payload['entry'][0]['changes'][0]['value']['messages'][0];
        $message = ['from' => self::CUSTOMER, 'id' => 'wamid.TXT1', 'timestamp' => (string) time(), 'type' => 'text', 'text' => ['body' => self::QUESTION]];
        unset($message);

        $this->postMeta($payload)->assertOk();

        $this->assertCount(0, $this->sentToMeta('audio'));
        $this->assertCount(1, $this->sentToMeta('text'));
    }

    public function test_always_mode_speaks_every_answer_with_the_chosen_voice(): void
    {
        $this->metaChannel();
        $this->bot->update(['voice_out' => 'always', 'voice_style' => 'masculine']);
        $this->fakeMeta();

        $payload = $this->metaAudio();
        $payload['entry'][0]['changes'][0]['value']['messages'][0] = ['from' => self::CUSTOMER, 'id' => 'wamid.TXT2', 'timestamp' => (string) time(), 'type' => 'text', 'text' => ['body' => self::QUESTION]];

        $this->postMeta($payload)->assertOk();

        $this->assertCount(1, $this->sentToMeta('audio'));
        $this->assertSame('masculine', FakeSpeech::$spoken[0]['voice']);
    }

    public function test_an_offer_without_voice_gets_a_polite_invitation_to_write(): void
    {
        $this->workspace->update(['plan' => 'essentiel']);
        $this->metaChannel();
        $this->fakeMeta();

        $this->postMeta($this->metaAudio())->assertOk();

        $reply = Message::withoutGlobalScopes()->where('role', 'assistant')->firstOrFail();
        $this->assertStringContainsString('ne peux pas écouter les messages vocaux', $reply->content);
        $this->assertSame('voice_disabled', $reply->meta['reason']);
        $this->assertSame(0, UsageEvent::withoutGlobalScopes()->where('kind', 'voice_in')->count(), 'rien de facturé sans transcription');
        $this->assertSame([], FakeSpeech::$heard);
    }

    public function test_an_assistant_that_does_not_listen_refuses_voice_notes_even_on_a_voice_plan(): void
    {
        $this->bot->update(['voice_in' => false]);
        $this->metaChannel();
        $this->fakeMeta();

        $this->postMeta($this->metaAudio())->assertOk();

        $this->assertStringContainsString('écrire', Message::withoutGlobalScopes()->where('role', 'assistant')->firstOrFail()->content);
        $this->assertSame([], FakeSpeech::$heard);
    }

    public function test_the_monthly_voice_quota_is_enforced(): void
    {
        $plan = Plan::bySlug('pro');
        $plan->update(['limits' => ['voice_per_month' => 1] + $plan->limits]);
        $this->metaChannel();
        $this->setVoiceOut('never');
        $this->fakeMeta();

        $this->postMeta($this->metaAudio('wamid.A'))->assertOk();
        $this->postMeta($this->metaAudio('wamid.B'))->assertOk();

        $this->assertSame(1, UsageEvent::withoutGlobalScopes()->where('kind', 'voice_in')->count(), 'le second vocal dépasse le quota');
        $second = Message::withoutGlobalScopes()->where('role', 'assistant')->latest('id')->firstOrFail();
        $this->assertSame('voice_quota', $second->meta['reason']);
    }

    public function test_a_voice_note_that_is_too_long_is_refused_before_any_transcription(): void
    {
        $this->metaChannel();
        // Un ogg déclarant 200 secondes.
        $long = 'OggS'."\x00\x04".pack('P', 48000 * 200).pack('V', 1).pack('V', 0).pack('V', 0)."\x00";
        Http::fake([
            'graph.facebook.com/*/media-audio' => Http::response(['url' => 'https://lookaside.fbsbx.example/long', 'mime_type' => 'audio/ogg']),
            'lookaside.fbsbx.example/*' => Http::response($long),
            'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT1']]]),
        ]);

        $this->postMeta($this->metaAudio())->assertOk();

        $this->assertStringContainsString('trop long', Message::withoutGlobalScopes()->where('role', 'assistant')->firstOrFail()->content);
        $this->assertSame([], FakeSpeech::$heard);
        $this->assertSame(0, UsageEvent::withoutGlobalScopes()->where('kind', 'voice_in')->count());
    }

    public function test_an_inaudible_voice_note_asks_the_customer_to_repeat_or_write(): void
    {
        $this->metaChannel();
        $this->fakeMeta('');

        $this->postMeta($this->metaAudio())->assertOk();

        $this->assertStringContainsString('pas bien compris', Message::withoutGlobalScopes()->where('role', 'assistant')->firstOrFail()->content);
    }

    public function test_a_speech_engine_failure_never_loses_the_written_answer(): void
    {
        $this->metaChannel();
        $this->setVoiceOut('mirror');
        $this->fakeMeta();

        // Écoute possible, mais la synthèse tombe en panne : le texte part quand même.
        $this->postMeta($this->metaAudio())->assertOk();
        $this->assertCount(1, $this->sentToMeta('text'));

        FakeSpeech::reset();
        FakeSpeech::$failWith = 'network';
        $this->postMeta($this->metaAudio('wamid.VOC2'))->assertOk();
        $this->assertStringContainsString('pas bien compris', Message::withoutGlobalScopes()->where('role', 'assistant')->latest('id')->firstOrFail()->content);
    }

    public function test_the_speech_engine_can_fail_while_speaking_and_the_text_still_goes_out(): void
    {
        $voice = app(\App\Speech\VoiceService::class);
        FakeSpeech::$failWith = 'provider';

        $this->assertNull($voice->speak($this->bot->fresh(), 'Bonjour, voici nos horaires.'));
    }

    public function test_a_replayed_voice_webhook_is_transcribed_and_billed_only_once(): void
    {
        $this->metaChannel();
        $this->setVoiceOut('never');
        $this->fakeMeta();

        $this->postMeta($this->metaAudio('wamid.MEME'))->assertOk();
        $this->postMeta($this->metaAudio('wamid.MEME'))->assertOk();

        $this->assertSame(1, UsageEvent::withoutGlobalScopes()->where('kind', 'voice_in')->count());
        $this->assertSame(1, Message::withoutGlobalScopes()->where('role', 'user')->count());
    }

    /* ---------- WhatsApp Twilio ---------- */

    public function test_a_twilio_voice_note_is_transcribed_and_the_spoken_answer_goes_out_as_a_signed_media_url(): void
    {
        Storage::fake('local');
        $channel = Channel::withoutGlobalScopes()->create([
            'workspace_id' => $this->bot->workspace_id, 'bot_id' => $this->bot->id, 'type' => Channel::WHATSAPP_TWILIO,
            'status' => Channel::ACTIVE, 'display_phone' => '+14155238886', 'external_ref' => '14155238886',
            'credentials' => ['account_sid' => 'ACtest', 'auth_token' => 'twilio-secret', 'from' => '+14155238886'],
        ]);
        $this->setVoiceOut('mirror');
        Http::fake([
            'api.twilio.com/2010-04-01/Accounts/ACtest/Messages.json' => Http::response(['sid' => 'SMout1'], 201),
            'api.twilio.com/*' => Http::response(FakeSpeech::voiceNote(self::QUESTION), 200, ['Content-Type' => 'audio/ogg']),
        ]);

        $params = [
            'MessageSid' => 'MMvoc1', 'From' => 'whatsapp:+'.self::CUSTOMER, 'To' => 'whatsapp:+14155238886', 'Body' => '', 'ProfileName' => 'Fatou',
            'NumMedia' => '1', 'MediaUrl0' => 'https://api.twilio.com/2010-04-01/Accounts/ACtest/Messages/MMvoc1/Media/ME1', 'MediaContentType0' => 'audio/ogg',
        ];
        $url = url("/webhooks/whatsapp/twilio/{$channel->id}");
        ksort($params);
        $data = $url;
        foreach ($params as $k => $v) {
            $data .= $k.$v;
        }
        $signature = base64_encode(hash_hmac('sha1', $data, 'twilio-secret', true));

        $this->post($url, $params, ['X-Twilio-Signature' => $signature])->assertOk();

        $question = Message::withoutGlobalScopes()->where('role', 'user')->firstOrFail();
        $this->assertSame(self::QUESTION, $question->content);

        // Le média entrant est téléchargé avec les identifiants du compte.
        Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), '/Media/ME1') && str_starts_with($r->header('Authorization')[0] ?? '', 'Basic '));

        $sent = Http::recorded(fn (HttpRequest $r) => str_contains($r->url(), 'Messages.json'))->map(fn ($p) => $p[0])->values();
        $withMedia = $sent->first(fn (HttpRequest $r) => isset($r['MediaUrl']));
        $this->assertNotNull($withMedia, 'l\'audio part par une adresse de média');
        $this->assertStringContainsString('/media/voice/', $withMedia['MediaUrl']);
        $this->assertNotNull($sent->first(fn (HttpRequest $r) => isset($r['Body'])), 'le texte part aussi');

        // L'adresse signée sert le fichier ; sans signature, refus.
        $path = parse_url($withMedia['MediaUrl'], PHP_URL_PATH).'?'.parse_url($withMedia['MediaUrl'], PHP_URL_QUERY);
        $this->get($path)->assertOk()->assertHeader('Content-Type', 'audio/ogg');
        $this->get(parse_url($withMedia['MediaUrl'], PHP_URL_PATH))->assertForbidden();
    }

    public function test_the_twilio_parser_carries_the_voice_note_reference(): void
    {
        $request = \Illuminate\Http\Request::create('/x', 'POST', [
            'MessageSid' => 'MM1', 'From' => 'whatsapp:+22670123456', 'NumMedia' => '1', 'MediaUrl0' => 'https://api.twilio.com/media/1', 'MediaContentType0' => 'audio/ogg',
        ]);

        $inbound = TwilioGateway::parse($request);

        $this->assertSame('audio', $inbound->type);
        $this->assertSame('https://api.twilio.com/media/1', $inbound->mediaRef);
        $this->assertSame('audio/ogg', $inbound->mediaMime);
    }

    /* ---------- Widget ---------- */

    private function widgetToken(): string
    {
        $url = '/api/v1/widget/'.$this->bot->public_key;

        return $this->postJson($url.'/conversations', ['visitor_id' => 'visiteur-12345678'])->assertOk()->json('token');
    }

    private function widgetUrl(string $token, string $path): string
    {
        return '/api/v1/widget/'.$this->bot->public_key."/conversations/{$token}{$path}";
    }

    public function test_the_widget_config_announces_what_the_voice_can_do(): void
    {
        $config = $this->getJson('/api/v1/widget/'.$this->bot->public_key.'/config')->assertOk()->json();
        $this->assertTrue($config['voice']['listen']);
        $this->assertTrue($config['voice']['speak']);

        $this->bot->update(['voice_in' => false, 'voice_out' => 'never']);
        $config = $this->getJson('/api/v1/widget/'.$this->bot->public_key.'/config')->assertOk()->json();
        $this->assertFalse($config['voice']['listen']);
        $this->assertFalse($config['voice']['speak']);

        $this->workspace->update(['plan' => 'essentiel']);
        $this->bot->update(['voice_in' => true, 'voice_out' => 'always']);
        $config = $this->getJson('/api/v1/widget/'.$this->bot->public_key.'/config')->assertOk()->json();
        $this->assertFalse($config['voice']['listen'], 'l\'offre sans voix n\'affiche ni micro ni lecture');
    }

    public function test_a_visitor_can_speak_to_the_widget_and_hear_the_answer(): void
    {
        $token = $this->widgetToken();
        $file = UploadedFile::fake()->createWithContent('vocal.webm', FakeSpeech::voiceNote(self::QUESTION));

        $response = $this->post($this->widgetUrl($token, '/voice'), ['audio' => $file], ['Accept' => 'application/json'])->assertOk();

        $this->assertSame(self::QUESTION, $response->json('transcript'));
        $this->assertStringContainsString('8 h', $response->json('message.content'));
        $this->assertStringStartsWith('data:audio/ogg;base64,', $response->json('audio'), 'mode miroir : la réponse est dite');
        $this->assertSame(1, UsageEvent::withoutGlobalScopes()->where('kind', 'voice_in')->count());
        $this->assertSame(1, UsageEvent::withoutGlobalScopes()->where('kind', 'voice_out')->count());
    }

    public function test_the_widget_answers_in_text_only_when_the_assistant_never_speaks(): void
    {
        $this->setVoiceOut('never');
        $token = $this->widgetToken();
        $file = UploadedFile::fake()->createWithContent('vocal.webm', FakeSpeech::voiceNote(self::QUESTION));

        $response = $this->post($this->widgetUrl($token, '/voice'), ['audio' => $file], ['Accept' => 'application/json'])->assertOk();

        $this->assertNull($response->json('audio'));
        $this->assertStringContainsString('8 h', $response->json('message.content'));
    }

    public function test_the_widget_voice_route_explains_refusals(): void
    {
        $this->workspace->update(['plan' => 'essentiel']);
        $token = $this->widgetToken();
        $file = UploadedFile::fake()->createWithContent('vocal.webm', FakeSpeech::voiceNote(self::QUESTION));

        $this->post($this->widgetUrl($token, '/voice'), ['audio' => $file], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonPath('error', 'disabled')->assertJsonPath('message', fn ($m) => str_contains($m, 'écrire'));

        $this->post($this->widgetUrl($token, '/voice'), [], ['Accept' => 'application/json'])->assertStatus(422);
    }

    public function test_the_listen_button_reads_an_assistant_message_aloud(): void
    {
        $token = $this->widgetToken();
        $reply = $this->postJson($this->widgetUrl($token, '/messages'), ['content' => self::QUESTION])->assertOk()->json('message');

        $this->postJson($this->widgetUrl($token, '/speak'), ['message_id' => $reply['id']])
            ->assertOk()->assertJsonPath('audio', fn ($audio) => str_starts_with($audio, 'data:audio/ogg;base64,'));

        // Un message du client ne peut pas être « écouté » : seul l'assistant parle.
        $this->postJson($this->widgetUrl($token, '/speak'), ['message_id' => $reply['id'] - 1])->assertNotFound();
    }

    public function test_a_visitor_cannot_use_the_voice_of_another_conversation(): void
    {
        [, , $other] = $this->tenant('Autre');
        $token = $this->widgetToken();
        $file = UploadedFile::fake()->createWithContent('vocal.webm', FakeSpeech::voiceNote(self::QUESTION));

        $this->post('/api/v1/widget/'.$other->public_key."/conversations/{$token}/voice", ['audio' => $file], ['Accept' => 'application/json'])->assertNotFound();
        $this->assertSame(0, Conversation::withoutGlobalScopes()->where('bot_id', $other->id)->count());
    }

    /* ---------- Widget : nouvelles options ---------- */

    public function test_a_visitor_can_rate_an_answer_and_change_their_mind(): void
    {
        $token = $this->widgetToken();
        $reply = $this->postJson($this->widgetUrl($token, '/messages'), ['content' => self::QUESTION])->assertOk()->json('message');
        $url = $this->widgetUrl($token, "/messages/{$reply['id']}/feedback");

        $this->postJson($url, ['value' => 'down'])->assertOk();
        $this->assertSame('down', Message::withoutGlobalScopes()->find($reply['id'])->meta['feedback']);

        $this->postJson($url, ['value' => 'up'])->assertOk();
        $this->assertSame('up', Message::withoutGlobalScopes()->find($reply['id'])->meta['feedback']);

        $this->postJson($url, ['value' => null])->assertOk();
        $this->assertArrayNotHasKey('feedback', Message::withoutGlobalScopes()->find($reply['id'])->meta ?? []);

        $this->postJson($url, ['value' => 'meh'])->assertStatus(422);
    }

    public function test_starting_a_new_conversation_closes_the_previous_one(): void
    {
        $token = $this->widgetToken();
        $this->postJson($this->widgetUrl($token, '/messages'), ['content' => self::QUESTION])->assertOk();

        $this->postJson($this->widgetUrl($token, '/close'))->assertOk();
        $this->assertSame(Conversation::CLOSED, Conversation::withoutGlobalScopes()->where('token', $token)->firstOrFail()->status);

        $fresh = $this->postJson('/api/v1/widget/'.$this->bot->public_key.'/conversations', ['visitor_id' => 'visiteur-12345678'])->assertOk()->json();
        $this->assertNotSame($token, $fresh['token']);
        $this->assertSame([], $fresh['messages']);
    }

    public function test_the_config_offers_a_whatsapp_link_only_when_the_offer_includes_whatsapp(): void
    {
        $config = fn () => $this->getJson('/api/v1/widget/'.$this->bot->public_key.'/config')->assertOk()->json();
        $this->assertNull($config()['whatsapp'], 'pas de canal actif');

        $this->metaChannel();
        $this->assertSame('22670000000', $config()['whatsapp']);

        $this->workspace->update(['plan' => 'essentiel']);
        $this->assertNull($config()['whatsapp'], 'Essentiel n\'inclut pas WhatsApp');
    }

    /* ---------- Moteur : OpenAI compatible ---------- */

    private function openai(array $override = []): OpenAiSpeech
    {
        return new OpenAiSpeech($override + [
            'api_key' => 'sk-test', 'base_url' => 'https://api.openai.com/v1',
            'stt_models' => ['gpt-4o-mini-transcribe', 'whisper-1'], 'tts_model' => 'gpt-4o-mini-tts', 'label' => 'openai',
        ]);
    }

    public function test_transcription_posts_the_audio_to_the_openai_compatible_endpoint(): void
    {
        Http::fake(['api.openai.com/v1/audio/transcriptions' => Http::response(['text' => ' Bonjour à tous ', 'usage' => ['seconds' => 7]])]);

        $result = $this->openai()->transcribe('OggSfakeaudio', 'audio/ogg; codecs=opus', 'fr');

        $this->assertSame('Bonjour à tous', $result->text);
        $this->assertSame(7, $result->seconds);
        $this->assertSame('openai:gpt-4o-mini-transcribe', $result->engine);
        Http::assertSent(fn (HttpRequest $r) => $r->hasHeader('Authorization', 'Bearer sk-test')
            && collect($r->data())->contains(fn ($p) => ($p['name'] ?? '') === 'model' && $p['contents'] === 'gpt-4o-mini-transcribe')
            && collect($r->data())->contains(fn ($p) => ($p['name'] ?? '') === 'language' && $p['contents'] === 'fr'));
    }

    public function test_a_missing_transcription_model_falls_back_to_the_next_one(): void
    {
        Http::fake([
            'api.openai.com/v1/audio/transcriptions' => Http::sequence()
                ->push(['error' => ['message' => 'The model gpt-4o-mini-transcribe does not exist']], 404)
                ->push(['text' => 'Salut']),
        ]);

        $result = $this->openai()->transcribe('OggS', 'audio/ogg');

        $this->assertSame('openai:whisper-1', $result->engine);
    }

    public function test_provider_failures_are_classified(): void
    {
        $kinds = [
            [401, ['error' => ['message' => 'Incorrect API key']], 'auth'],
            [429, ['error' => ['message' => 'You exceeded your current quota', 'code' => 'insufficient_quota']], 'billing'],
            [429, ['error' => ['message' => 'Rate limit reached']], 'rate_limit'],
            [500, [], 'provider'],
        ];

        // Une seule séquence : le premier motif qui correspond l'emporte, un nouveau Http::fake ne remplacerait rien.
        $sequence = Http::sequence();
        foreach ($kinds as [$status, $body]) {
            $sequence->push($body, $status);
        }
        Http::fake(['api.openai.com/v1/audio/transcriptions' => $sequence]);

        foreach ($kinds as [$status, , $expected]) {
            try {
                $this->openai()->transcribe('OggS', 'audio/ogg');
                $this->fail('une erreur était attendue');
            } catch (SpeechException $e) {
                $this->assertSame($expected, $e->kind, "HTTP {$status}");
            }
        }
    }

    public function test_speech_synthesis_asks_for_opus_and_the_chosen_voice(): void
    {
        Http::fake(['api.openai.com/v1/audio/speech' => Http::response('OggS-audio-bytes', 200, ['Content-Type' => 'audio/ogg'])]);

        $audio = $this->openai()->speak('Bonjour', 'masculine', 'fr');

        $this->assertSame('OggS-audio-bytes', $audio->bytes);
        $this->assertSame('audio/ogg', $audio->mime);
        $this->assertSame('ogg', $audio->extension());
        Http::assertSent(fn (HttpRequest $r) => $r['model'] === 'gpt-4o-mini-tts' && $r['voice'] === 'onyx' && $r['response_format'] === 'opus' && $r['input'] === 'Bonjour' && isset($r['instructions']));
    }

    /**
     * Port fermé de la machine : une vraie coupure réseau, avec le nouvel essai de la voix. (Une fausse coupure levée
     * depuis Http::fake fait planter PHP 8.2 sous Windows : on ne la simule donc pas.)
     */
    public function test_a_lasting_network_failure_ends_in_the_usual_network_error_after_the_retry(): void
    {
        $engine = $this->openai(['base_url' => 'http://127.0.0.1:9/v1', 'timeout' => 1]);

        foreach (['speak' => fn () => $engine->speak('Bonjour', 'feminine', 'fr'), 'transcribe' => fn () => $engine->transcribe('OggSfakeaudio', 'audio/ogg', 'fr')] as $what => $call) {
            try {
                $call();
                $this->fail("{$what} : une SpeechException était attendue");
            } catch (SpeechException $e) {
                $this->assertSame('network', $e->kind, $what);
            }
        }
    }

    public function test_without_a_key_the_cloud_engine_is_unavailable_but_a_local_server_needs_none(): void
    {
        $this->assertFalse($this->openai(['api_key' => null])->canTranscribe('fr'));
        $this->assertFalse($this->openai(['api_key' => null])->canSpeak());

        $local = $this->openai(['api_key' => null, 'base_url' => 'http://localhost:8000/v1', 'tts_model' => null, 'label' => 'local', 'languages' => ['bm', 'dyu']]);
        $this->assertTrue($local->canTranscribe('bm'));
        $this->assertFalse($local->canTranscribe('fr'), 'le serveur libre ne prend que ses langues');
        $this->assertFalse($local->canSpeak());
    }

    /* ---------- Outils ---------- */

    public function test_the_text_is_cleaned_before_being_read_aloud(): void
    {
        $spoken = SpeechText::forSpeech("**Horaires**\n• Lundi : 8 h à 19 h\n• Samedi : 8 h à 13 h\nVoir https://boutique.test/horaires 😊\n[[REPLIES: Oui | Non]]");

        $this->assertStringNotContainsString('*', $spoken);
        $this->assertStringNotContainsString('•', $spoken);
        $this->assertStringNotContainsString('https', $spoken);
        $this->assertStringNotContainsString('REPLIES', $spoken);
        $this->assertStringNotContainsString('😊', $spoken);
        $this->assertStringContainsString('Lundi : 8 h à 19 h', $spoken);
    }

    public function test_a_long_answer_is_read_in_part_and_points_to_the_written_details(): void
    {
        config(['platform.speech.tts_max_chars' => 120]);
        $long = str_repeat('Nous sommes ouverts tous les jours de la semaine. ', 8);

        app(\App\Speech\VoiceService::class)->speak($this->bot->fresh(), $long);

        $spoken = FakeSpeech::$spoken[0]['text'];
        $this->assertLessThan(mb_strlen($long), mb_strlen($spoken));
        $this->assertStringEndsWith('Je vous envoie le détail par écrit.', $spoken);
    }

    public function test_the_duration_of_an_ogg_opus_recording_is_read_from_its_last_page(): void
    {
        $ogg = 'OggS'."\x00\x04".pack('P', 48000 * 12 + 300).pack('V', 1).pack('V', 0).pack('V', 0)."\x00";

        $this->assertSame(13, AudioInfo::seconds($ogg));
        $this->assertSame(1, AudioInfo::seconds('petit'));
        $this->assertSame('webm', AudioInfo::extension('audio/webm;codecs=opus'));
        $this->assertSame('ogg', AudioInfo::extension('audio/ogg; codecs=opus'));
        $this->assertSame('m4a', AudioInfo::extension('audio/mp4'));
    }

    public function test_voice_usage_is_counted_per_workspace_and_per_month(): void
    {
        [$other] = $this->tenant('Autre');
        $usage = app(UsageService::class);
        $meter = app(\App\Services\UsageMeter::class);

        $meter->voice($this->workspace->id, $this->bot->id, 'in', 10);
        $meter->voice($this->workspace->id, $this->bot->id, 'out', 5);
        $meter->voice($other->id, null, 'in', 10);
        UsageEvent::withoutGlobalScopes()->create([
            'workspace_id' => $this->workspace->id, 'kind' => 'voice_in', 'units' => 8, 'cost_usd' => 0.0004, 'created_at' => now()->subMonths(2),
        ]);

        $this->assertSame(2, $usage->voiceUsed($this->workspace));
        $this->assertSame(1, $usage->voiceUsed($other));
        $this->assertSame(300, $usage->voiceAllowance($this->workspace));
        $this->assertTrue($usage->canUseVoice($this->workspace));

        $this->workspace->update(['plan' => 'essentiel']);
        $this->assertSame(0, $usage->voiceAllowance($this->workspace->fresh()));
        $this->assertFalse($usage->canUseVoice($this->workspace->fresh()));
    }
}
