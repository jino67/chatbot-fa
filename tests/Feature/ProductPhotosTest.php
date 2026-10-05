<?php

namespace Tests\Feature;

use App\Channels\WhatsApp\GatewayFactory;
use App\Channels\WhatsApp\InboundHandler;
use App\Chat\ChatService;
use App\Chat\PromptBuilder;
use App\Ingestion\Crawler\SafeUrl;
use App\Models\Bot;
use App\Models\CatalogItem;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Message;
use App\Support\Text;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesTenants;
use Tests\Concerns\ScriptsTheAssistant;
use Tests\TestCase;

/** Photos des produits : le client les voit sur le chat du site et sur WhatsApp, avec la bonne légende, sans jamais de doublon. */
class ProductPhotosTest extends TestCase
{
    use CreatesTenants;
    use RefreshDatabase;
    use ScriptsTheAssistant;

    private Bot $bot;

    private CatalogItem $duo;

    private CatalogItem $lait;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Mail::fake();
        Storage::fake('local');
        SafeUrl::useResolver(fn () => ['93.184.216.34']);
        config(['app.url' => 'https://kouma.test']);
        [, , $this->bot] = $this->tenant('Poupécosmetic', 'pro');

        $this->duo = $this->item($this->bot, 'Duo visage', '5 000 FCFA', 'https://poupecosmetic.test/img/duo.png');
        $this->lait = $this->item($this->bot, 'Lait collagène', '8 000 FCFA', 'https://poupecosmetic.test/img/lait.png');
        $this->teach($this->bot, 'Duo visage', "# Duo visage\n\nProduit : Duo visage\nPrix : 5 000 FCFA\nDisponibilité : En stock\nDescription : très efficace pour traiter l'acné du visage.\nPhoto : {$this->duo->ref()}");
        $this->teach($this->bot, 'Lait collagène', "# Lait collagène\n\nProduit : Lait collagène\nPrix : 8 000 FCFA\nDescription : lait hydratant au collagène.\nPhoto : {$this->lait->ref()}");

        Http::fake([
            'https://poupecosmetic.test/img/*' => fn () => Http::response($this->png(), 200, ['Content-Type' => 'image/png']),
            'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]]),
        ]);
    }

    protected function tearDown(): void
    {
        SafeUrl::useResolver(null);
        parent::tearDown();
    }

    private function item(Bot $bot, string $name, string $price, ?string $image): CatalogItem
    {
        return CatalogItem::withoutGlobalScopes()->create([
            'workspace_id' => $bot->workspace_id, 'bot_id' => $bot->id, 'item_key' => sha1($bot->id.$name), 'name' => $name,
            'price_text' => $price, 'image_url' => $image, 'image_status' => $image ? CatalogItem::IMAGE_PENDING : CatalogItem::IMAGE_NONE,
        ]);
    }

    private function png(int $w = 1600, int $h = 1200): string
    {
        $image = imagecreatetruecolor($w, $h);
        imagefill($image, 0, 0, imagecolorallocate($image, 190, 110, 80));
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }

    private function conversation(string $channel = 'web'): Conversation
    {
        return Conversation::withoutGlobalScopes()->create([
            'workspace_id' => $this->bot->workspace_id, 'bot_id' => $this->bot->id, 'channel' => $channel,
            'external_id' => $channel === 'whatsapp' ? '22670999999' : 'visiteur-photos-1', 'contact_phone' => $channel === 'whatsapp' ? '+22670999999' : null,
        ]);
    }

    private function lastAnswer(): Message
    {
        return Message::withoutGlobalScopes()->where('role', 'assistant')->latest('id')->firstOrFail();
    }

    private function metaChannel(): Channel
    {
        return Channel::withoutGlobalScopes()->create([
            'workspace_id' => $this->bot->workspace_id, 'bot_id' => $this->bot->id, 'type' => Channel::WHATSAPP_META, 'status' => Channel::ACTIVE,
            'display_phone' => '+226 70 00 00 00', 'external_ref' => '109876543210', 'credentials' => ['access_token' => 'EAAtoken', 'waba_id' => '555'],
        ]);
    }

    /* ---------- Le marqueur devient une photo ---------- */

    public function test_the_photo_marker_attaches_the_product_photo_and_disappears_from_the_text(): void
    {
        $this->scriptedAssistant("Le **Duo visage** est à 5 000 FCFA, très efficace contre l'acné.\n[[PHOTO: {$this->duo->ref()}]]");

        app(ChatService::class)->handleUserMessage($this->conversation(), 'Que me conseillez-vous pour mon acné du visage ?');

        $answer = $this->lastAnswer();
        $this->assertStringNotContainsString('[[', $answer->content);
        $this->assertSame($this->duo->id, $answer->meta['media'][0]['id']);

        $widget = $answer->toWidget();
        $this->assertCount(1, $widget['media']);
        $this->assertSame('Duo visage : 5 000 FCFA', $widget['media'][0]['caption']);
        $this->assertMatchesRegularExpression('#^https://kouma\.test/media/produit/'.$this->duo->id.'\.jpg\?v=\w+&signature=#', $widget['media'][0]['url']);
    }

    public function test_the_photo_address_serves_a_light_jpeg_and_refuses_a_forged_signature(): void
    {
        $url = app(\App\Services\ProductImages::class)->publicUrl($this->duo);
        $path = str_replace('https://kouma.test', '', $url);

        $response = $this->get($path)->assertOk()->assertHeader('Content-Type', 'image/jpeg');

        $bytes = $response->getContent();
        $this->assertStringStartsWith("\xFF\xD8", $bytes, 'un vrai JPEG, lisible par WhatsApp');
        [$width, $height] = getimagesizefromstring($bytes);
        $this->assertLessThanOrEqual(1000, max($width, $height), 'photo allégée pour un téléphone');
        $this->assertSame(CatalogItem::IMAGE_READY, $this->duo->fresh()->image_status);
        $this->assertTrue(Storage::disk('local')->exists($this->duo->fresh()->image_path));

        $this->get(preg_replace('/signature=\w+/', 'signature=forgee', $path))->assertForbidden();
        $this->get('/media/produit/'.$this->duo->id.'.jpg')->assertForbidden();
    }

    public function test_a_photo_that_is_not_an_image_is_marked_as_failed_and_not_offered_again(): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake(['https://poupecosmetic.test/img/*' => fn () => Http::response('<html>erreur</html>', 200, ['Content-Type' => 'text/html'])]);

        $path = str_replace('https://kouma.test', '', app(\App\Services\ProductImages::class)->publicUrl($this->lait));
        $this->get($path)->assertNotFound();

        $this->assertSame(CatalogItem::IMAGE_FAILED, $this->lait->fresh()->image_status);
        $this->assertNull(app(\App\Services\ProductImages::class)->publicUrl($this->lait->fresh()), 'plus proposée tant qu\'elle est en échec');
    }

    /* ---------- Quand une photo part ---------- */

    public function test_the_same_photo_is_not_sent_twice_unless_the_customer_asks_again(): void
    {
        $this->scriptedAssistant([
            "Voici le Duo visage.\n[[PHOTO: {$this->duo->ref()}]]",
            "Toujours le Duo visage, à 5 000 FCFA.\n[[PHOTO: {$this->duo->ref()}]]",
            "Voici de nouveau le Duo visage.\n[[PHOTO: {$this->duo->ref()}]]",
        ]);
        $conversation = $this->conversation();
        $chat = app(ChatService::class);

        $chat->handleUserMessage($conversation, 'Parlez-moi du duo visage pour mon acné');
        $this->assertCount(1, $this->lastAnswer()->meta['media'] ?? []);

        $chat->handleUserMessage($conversation->fresh(), 'Et il coûte combien le duo visage pour l\'acné ?');
        $this->assertArrayNotHasKey('media', $this->lastAnswer()->meta, 'déjà montrée dans cette conversation');

        $chat->handleUserMessage($conversation->fresh(), 'Tu peux me remontrer la photo du duo visage ?');
        $this->assertCount(1, $this->lastAnswer()->meta['media'] ?? [], 'redemandée : elle repart');
    }

    public function test_a_customer_who_asks_to_see_a_product_gets_its_photo_even_if_the_model_forgot_the_marker(): void
    {
        $this->scriptedAssistant('Le Duo visage coûte 5 000 FCFA et traite l\'acné du visage.');

        app(ChatService::class)->handleUserMessage($this->conversation(), 'Tu peux me montrer une photo du duo visage ?');

        $media = $this->lastAnswer()->meta['media'] ?? [];
        $this->assertCount(1, $media);
        $this->assertSame($this->duo->id, $media[0]['id'], 'le produit nommé dans la réponse');
    }

    public function test_at_most_two_photos_in_a_normal_reply_and_three_when_the_customer_asks(): void
    {
        $third = $this->item($this->bot, 'Savon réparateur', '7 000 FCFA', 'https://poupecosmetic.test/img/savon.png');
        $refs = implode(', ', [$this->duo->ref(), $this->lait->ref(), $third->ref()]);
        $this->scriptedAssistant(["Trois produits.\n[[PHOTO: {$refs}]]", "Les voici.\n[[PHOTO: {$refs}]]"]);
        $chat = app(ChatService::class);

        $chat->handleUserMessage($this->conversation(), 'Quels produits pour le visage avec de l\'acné ?');
        $this->assertCount(2, $this->lastAnswer()->meta['media']);

        $other = $this->conversation();
        $other->forceFill(['external_id' => 'visiteur-photos-2'])->save();
        $chat->handleUserMessage($other, 'Montre-moi les photos de ces produits du visage');
        $this->assertCount(3, $this->lastAnswer()->meta['media']);
    }

    public function test_the_owner_can_send_photos_only_on_request_or_never(): void
    {
        $this->scriptedAssistant(["Le duo.\n[[PHOTO: {$this->duo->ref()}]]"]);
        $chat = app(ChatService::class);

        $this->bot->forceFill(['profile' => ['photos' => 'ask']])->save();
        $chat->handleUserMessage($this->conversation(), 'Parlez-moi du duo visage pour mon acné');
        $this->assertArrayNotHasKey('media', $this->lastAnswer()->meta, 'sur demande seulement');

        $asking = $this->conversation();
        $asking->forceFill(['external_id' => 'visiteur-photos-2'])->save();
        $chat->handleUserMessage($asking, 'Montre-moi une photo du duo visage');
        $this->assertCount(1, $this->lastAnswer()->meta['media'] ?? []);

        $this->bot->forceFill(['profile' => ['photos' => 'off']])->save();
        $never = $this->conversation();
        $never->forceFill(['external_id' => 'visiteur-photos-3'])->save();
        $chat->handleUserMessage($never, 'Montre-moi une photo du duo visage');
        $this->assertArrayNotHasKey('media', $this->lastAnswer()->meta, 'jamais');
    }

    public function test_a_reference_from_another_assistant_is_never_used(): void
    {
        [, , $otherBot] = $this->tenant('Autre boutique', 'pro');
        $foreign = $this->item($otherBot, 'Produit secret', '99 000 FCFA', 'https://poupecosmetic.test/img/secret.png');
        $this->scriptedAssistant("Voici.\n[[PHOTO: {$foreign->ref()}]]");

        app(ChatService::class)->handleUserMessage($this->conversation(), 'Montre-moi une photo du duo visage');

        $this->assertArrayNotHasKey('media', $this->lastAnswer()->meta);
    }

    /* ---------- Le prompt ---------- */

    public function test_the_prompt_only_promises_photos_when_the_assistant_has_some(): void
    {
        $prompts = app(PromptBuilder::class);
        $this->assertStringContainsString('[[PHOTO: Pn]]', $prompts->system($this->bot->fresh(), 'web'));
        $this->assertStringContainsString('Ne joins pas deux fois la même photo', $prompts->system($this->bot->fresh(), 'whatsapp'));

        $this->bot->forceFill(['profile' => ['photos' => 'ask']])->save();
        $this->assertStringContainsString('seulement quand le client demande à voir', $prompts->system($this->bot->fresh(), 'web'));

        $this->bot->forceFill(['profile' => ['photos' => 'off']])->save();
        $this->assertStringNotContainsString('[[PHOTO', $prompts->system($this->bot->fresh(), 'web'));

        [, , $empty] = $this->tenant('Sans photos', 'pro');
        $this->assertStringNotContainsString('[[PHOTO', $prompts->system($empty, 'web'), 'pas de photo, pas de promesse');
    }

    public function test_only_real_photo_requests_trigger_the_automatic_photo(): void
    {
        foreach (['Tu peux me montrer une photo du duo ?', 'Montrez-moi le produit', 'Je voudrais voir le savon', 'Vous avez des photos ?', 'À quoi ça ressemble ?', 'Envoie-moi la photo du lait', 'Show me a picture'] as $yes) {
            $this->assertTrue(Text::wantsPhoto($yes), $yes);
        }
        foreach (['Combien coûte le duo visage ?', 'Voici la photo de mon visage', 'Je vous envoie ma photo', 'Merci beaucoup', 'Quels sont vos horaires ?'] as $no) {
            $this->assertFalse(Text::wantsPhoto($no), $no);
        }
    }

    /* ---------- WhatsApp ---------- */

    public function test_on_whatsapp_the_photo_leaves_first_with_its_caption_then_the_text(): void
    {
        $channel = $this->metaChannel();
        $conversation = $this->conversation('whatsapp');
        $message = Message::withoutGlobalScopes()->create([
            'workspace_id' => $conversation->workspace_id, 'conversation_id' => $conversation->id, 'role' => Message::ASSISTANT,
            'content' => 'Le Duo visage est à 5 000 FCFA.', 'meta' => ['media' => [['id' => $this->duo->id, 'ref' => $this->duo->ref(), 'name' => 'Duo visage', 'caption' => 'Duo visage : 5 000 FCFA']]],
        ]);

        app(InboundHandler::class)->deliver(app(GatewayFactory::class)->for($channel), $conversation, $message);

        $sent = Http::recorded(fn (HttpRequest $r) => str_contains($r->url(), '/messages'))->map(fn ($pair) => $pair[0])->values();
        $this->assertCount(2, $sent);
        $this->assertSame('image', $sent[0]['type']);
        $this->assertSame('Duo visage : 5 000 FCFA', $sent[0]['image']['caption']);
        $this->assertStringStartsWith('https://kouma.test/media/produit/'.$this->duo->id.'.jpg?', $sent[0]['image']['link']);
        $this->assertSame('text', $sent[1]['type']);
        $this->assertSame(1, $message->fresh()->meta['media_sent']);
    }

    public function test_a_photo_that_cannot_be_sent_never_blocks_the_written_answer(): void
    {
        $channel = $this->metaChannel();
        $conversation = $this->conversation('whatsapp');
        $message = Message::withoutGlobalScopes()->create([
            'workspace_id' => $conversation->workspace_id, 'conversation_id' => $conversation->id, 'role' => Message::ASSISTANT,
            'content' => 'Le Duo visage est à 5 000 FCFA.', 'meta' => ['media' => [['id' => $this->duo->id, 'ref' => 'P1', 'name' => 'Duo visage', 'caption' => 'Duo visage']]],
        ]);
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake(['graph.facebook.com/*' => fn (HttpRequest $r) => ($r['type'] ?? '') === 'image'
            ? Http::response(['error' => ['message' => 'Media upload error']], 400)
            : Http::response(['messages' => [['id' => 'wamid.OUT']]])]);

        app(InboundHandler::class)->deliver(app(GatewayFactory::class)->for($channel), $conversation, $message);

        $this->assertSame(0, $message->fresh()->meta['media_sent']);
        $this->assertNotNull($message->fresh()->provider_message_id, 'le texte est parti');
    }

    public function test_twilio_receives_the_photo_as_a_media_url_with_its_caption(): void
    {
        $channel = Channel::withoutGlobalScopes()->create([
            'workspace_id' => $this->bot->workspace_id, 'bot_id' => $this->bot->id, 'type' => Channel::WHATSAPP_TWILIO, 'status' => Channel::ACTIVE,
            'display_phone' => '+14155238886', 'external_ref' => '14155238886', 'credentials' => ['account_sid' => 'ACtest', 'auth_token' => 'secret', 'from' => '+14155238886'],
        ]);
        Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'SM1'], 201)]);

        app(GatewayFactory::class)->for($channel)->sendImage('22670999999', 'https://kouma.test/media/produit/1.jpg?v=a&signature=b', 'Duo visage : 5 000 FCFA');

        Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), 'Messages.json')
            && $r['MediaUrl'] === 'https://kouma.test/media/produit/1.jpg?v=a&signature=b' && $r['Body'] === 'Duo visage : 5 000 FCFA');
    }

    public function test_the_command_prepares_pending_photos_in_advance(): void
    {
        $this->artisan('catalog:warm-images')->assertSuccessful();

        $this->assertSame(CatalogItem::IMAGE_READY, $this->duo->fresh()->image_status);
        $this->assertSame(CatalogItem::IMAGE_READY, $this->lait->fresh()->image_status);
    }

    public function test_the_sources_page_lists_the_recognised_products_with_their_photos(): void
    {
        $owner = \App\Models\User::where('workspace_id', $this->bot->workspace_id)->firstOrFail();

        $this->actingAs($owner)->get(route('sources.index', $this->bot))->assertOk()
            ->assertSee('Produits reconnus (2)')->assertSee('Duo visage')->assertSee('5 000 FCFA')->assertSee('/media/produit/'.$this->duo->id.'.jpg', false);
    }

    /* ---------- Réglages et widget ---------- */

    public function test_the_widget_script_draws_the_photos_under_the_answer(): void
    {
        $script = (string) file_get_contents(public_path('widget/widget.js'));

        $this->assertStringContainsString('msg.media', $script);
        $this->assertStringContainsString('.ph img', $script);
    }

    public function test_the_owner_chooses_the_photo_policy_in_the_assistant_settings(): void
    {
        Cache::flush();
        $owner = \App\Models\User::where('workspace_id', $this->bot->workspace_id)->firstOrFail();

        $this->actingAs($owner)->get(route('bots.edit', $this->bot))->assertOk()->assertSee('Photos de vos produits dans les réponses')->assertSee('lit les photos envoyées par vos clients');

        $this->actingAs($owner)->put(route('bots.update', $this->bot), [
            'name' => $this->bot->name, 'language' => 'fr', 'color' => '#2340D9', 'position' => 'right', 'voice_out' => 'mirror',
            'photos_shown' => 1, 'photos' => 'ask', 'image_brief' => 'Relève le montant des captures de paiement Wave.',
        ])->assertSessionHasNoErrors();

        $bot = $this->bot->fresh();
        $this->assertSame('ask', $bot->photoPolicy());
        $this->assertFalse($bot->acceptsImages(), 'case décochée = photos des clients coupées');
        $this->assertSame('Relève le montant des captures de paiement Wave.', $bot->profile('image_brief'));
    }
}
