<?php

namespace Tests\Feature;

use App\Models\Bot;
use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class WidgetApiTest extends TestCase
{
    use CreatesTenants;
    use RefreshDatabase;

    private Bot $bot;

    protected function setUp(): void
    {
        parent::setUp();
        [, , $this->bot] = $this->tenant();
        $this->teach($this->bot, 'Horaires', "# Horaires\nLa boutique est ouverte du lundi au samedi de 8 h à 19 h.");
    }

    private function url(string $path = '', ?Bot $bot = null): string
    {
        return '/api/v1/widget/'.($bot ?? $this->bot)->public_key.$path;
    }

    private function start(string $visitor = 'visiteur-12345678', array $headers = []): array
    {
        return $this->withHeaders($headers)->postJson($this->url('/conversations'), ['visitor_id' => $visitor])->assertOk()->json();
    }

    public function test_public_config_exposes_only_what_the_widget_needs(): void
    {
        $this->bot->update(['instructions' => 'CONSIGNE SECRETE', 'handoff_email' => 'prive@boutique.test']);

        $response = $this->getJson($this->url('/config'))->assertOk();

        $response->assertJsonStructure(['name', 'title', 'welcome', 'suggested', 'color', 'position', 'language', 'rtl', 'collect_contact']);
        $this->assertStringNotContainsString('CONSIGNE SECRETE', $response->getContent());
        $this->assertStringNotContainsString('prive@boutique.test', $response->getContent());
    }

    public function test_a_visitor_can_chat_and_get_a_grounded_answer(): void
    {
        $token = $this->start()['token'];

        $reply = $this->postJson($this->url("/conversations/{$token}/messages"), ['content' => "Quels sont les horaires d'ouverture de la boutique le samedi ?"])
            ->assertOk()->json();

        $this->assertSame('assistant', $reply['message']['role']);
        $this->assertStringContainsString('8 h', $reply['message']['content']);
        $this->assertSame('bot', $reply['status']);
    }

    public function test_the_same_visitor_resumes_the_same_conversation_with_its_history(): void
    {
        $first = $this->start('visiteur-abcdef12');
        $this->postJson($this->url("/conversations/{$first['token']}/messages"), ['content' => 'Bonjour']);

        $again = $this->start('visiteur-abcdef12');

        $this->assertSame($first['token'], $again['token']);
        $this->assertCount(2, $again['messages']);
    }

    public function test_origin_allowlist_blocks_other_sites_but_not_the_owner(): void
    {
        $this->bot->update(['allowed_origins' => ['https://www.boutique.com', 'https://*.boutique.com']]);

        $this->withHeaders(['Origin' => 'https://www.boutique.com'])->getJson($this->url('/config'))->assertOk();
        $this->withHeaders(['Origin' => 'https://shop.boutique.com'])->getJson($this->url('/config'))->assertOk();
        $this->withHeaders(['Origin' => 'https://evil.example'])->getJson($this->url('/config'))->assertForbidden()->assertJson(['error' => 'origin_not_allowed']);
        $this->withHeaders(['Origin' => 'https://boutique.com.evil.example'])->getJson($this->url('/config'))->assertForbidden();
        $this->withHeaders(['Origin' => 'https://evilboutique.com'])->getJson($this->url('/config'))->assertForbidden();
    }

    public function test_the_allowlist_also_protects_sending_messages(): void
    {
        $this->bot->update(['allowed_origins' => ['https://www.boutique.com']]);
        $token = $this->start('visiteur-12345678', ['Origin' => 'https://www.boutique.com'])['token'];

        $this->withHeaders(['Origin' => 'https://evil.example'])
            ->postJson($this->url("/conversations/{$token}/messages"), ['content' => 'Bonjour'])
            ->assertForbidden();

        $this->assertSame(0, Message::withoutGlobalScopes()->where('role', 'user')->count());
    }

    public function test_unknown_or_inactive_bots_are_not_found(): void
    {
        $this->getJson('/api/v1/widget/pk_inexistant/config')->assertNotFound();

        $this->bot->update(['is_active' => false]);
        $this->getJson($this->url('/config'))->assertNotFound();
    }

    public function test_input_is_validated(): void
    {
        $this->postJson($this->url('/conversations'), ['visitor_id' => 'x'])->assertUnprocessable();
        $this->postJson($this->url('/conversations'), ['visitor_id' => 'valide-12345678', 'email' => 'pas-un-email'])->assertUnprocessable();

        $token = $this->start()['token'];
        $this->postJson($this->url("/conversations/{$token}/messages"), ['content' => str_repeat('a', 2001)])->assertUnprocessable();
        $this->postJson($this->url("/conversations/{$token}/messages"), ['content' => ''])->assertUnprocessable();
    }

    public function test_a_conversation_token_only_works_with_its_own_bot(): void
    {
        [, , $otherBot] = $this->tenant('Autre client');
        $token = $this->start()['token'];

        $this->postJson($this->url("/conversations/{$token}/messages", $otherBot), ['content' => 'Bonjour'])->assertNotFound();
        $this->getJson($this->url("/conversations/{$token}/messages", $otherBot))->assertNotFound();
    }

    public function test_polling_returns_only_new_bot_or_agent_messages(): void
    {
        $started = $this->start();
        $conversation = Conversation::withoutGlobalScopes()->where('token', $started['token'])->firstOrFail();
        $mk = fn (string $role, string $text) => $conversation->messages()->create(['workspace_id' => $conversation->workspace_id, 'role' => $role, 'content' => $text]);

        $mk('user', 'Bonjour');
        $seen = $mk('assistant', 'Bienvenue');
        $mk('user', 'Je veux un humain');
        $agent = $mk('agent', 'Je suis Awa, je vous réponds.');
        $conversation->update(['status' => Conversation::HUMAN]);

        $poll = $this->getJson($this->url("/conversations/{$started['token']}/messages?after={$seen->id}"))->assertOk()->json();

        $this->assertSame('human', $poll['status']);
        $this->assertCount(1, $poll['messages']);
        $this->assertSame($agent->id, $poll['messages'][0]['id']);
        $this->assertSame('agent', $poll['messages'][0]['role']);
    }

    public function test_the_api_is_rate_limited_per_ip(): void
    {
        $limit = config('platform.widget.rate_per_minute_ip');

        for ($i = 0; $i < $limit; $i++) {
            $this->getJson($this->url('/config'))->assertOk();
        }

        $this->getJson($this->url('/config'))->assertStatus(429);
    }

    public function test_cross_origin_preflight_is_answered(): void
    {
        $this->call('OPTIONS', $this->url('/conversations'), [], [], [], [
            'HTTP_ORIGIN' => 'https://www.boutique.com',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
            'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'content-type',
        ])->assertSuccessful()->assertHeader('Access-Control-Allow-Origin');
    }

    public function test_the_widget_script_is_served_and_contains_no_secret(): void
    {
        $script = file_get_contents(public_path('widget/widget.js'));

        $this->assertStringContainsString('attachShadow', $script);
        $this->assertStringNotContainsString('sk-ant', $script);
        $this->assertStringNotContainsString('innerHTML = text', $script, 'jamais de HTML injecte depuis un message');
    }
}
