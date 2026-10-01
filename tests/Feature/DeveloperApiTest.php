<?php

namespace Tests\Feature;

use App\Models\ApiKey;
use App\Models\Bot;
use App\Models\Conversation;
use App\Models\Plan;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/** Offre développeurs : clés d'API, point d'accès de chat, quota, page publique. */
class DeveloperApiTest extends TestCase
{
    use CreatesTenants;
    use RefreshDatabase;

    private Workspace $workspace;

    private User $owner;

    private Bot $bot;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        [$this->workspace, $this->owner, $this->bot] = $this->tenant('Studio Dev', 'api');
        $this->teach($this->bot, 'Horaires', "# Horaires\nLa boutique est ouverte du lundi au samedi de 8 h à 19 h.");
        [, $this->token] = ApiKey::issue($this->workspace->id, $this->bot->id, 'Application mobile');
    }

    private function auth(?string $token = null): array
    {
        return ['Authorization' => 'Bearer '.($token ?? $this->token)];
    }

    private function ask(string $message = "Quels sont les horaires d'ouverture de la boutique le samedi ?", array $extra = [], ?string $token = null)
    {
        return $this->postJson('/api/v1/chat', ['message' => $message] + $extra, $this->auth($token));
    }

    /* ---------- Cles ---------- */

    public function test_a_key_is_shown_once_and_only_its_hash_is_stored(): void
    {
        $response = $this->actingAs($this->owner)->post(route('api-keys.store'), ['name' => 'Back-office', 'bot_id' => $this->bot->id]);
        $response->assertSessionHas('status')->assertSessionHas('new_api_key');

        $plain = session('new_api_key');
        $this->assertStringStartsWith('kma_', $plain);
        $this->assertGreaterThanOrEqual(44, strlen($plain));

        $stored = ApiKey::withoutGlobalScopes()->where('name', 'Back-office')->firstOrFail();
        $this->assertSame(hash('sha256', $plain), $stored->key_hash);
        $this->assertStringNotContainsString($plain, json_encode($stored->toArray()), 'la cle n\'est jamais serialisee');
        $this->assertSame(substr($plain, 0, 8), $stored->prefix);

        $this->actingAs($this->owner)->withSession(['new_api_key' => $plain])->get(route('api-keys.index'))->assertOk()->assertSee($plain);
        $this->actingAs($this->owner)->get(route('api-keys.index'))->assertOk()->assertDontSee($plain)->assertSee($stored->prefix);
    }

    public function test_only_workspaces_with_the_api_option_can_create_keys(): void
    {
        [, $owner, $bot] = $this->tenant('Boutique', 'pro');

        $this->actingAs($owner)->get(route('api-keys.index'))->assertRedirect(route('billing.show'));
        $this->actingAs($owner)->post(route('api-keys.store'), ['name' => 'x', 'bot_id' => $bot->id])->assertRedirect(route('billing.show'))->assertSessionHas('error');
        $this->assertSame(0, ApiKey::withoutGlobalScopes()->where('workspace_id', $bot->workspace_id)->count());
    }

    public function test_a_key_cannot_be_bound_to_another_clients_assistant(): void
    {
        [, , $otherBot] = $this->tenant('Autre', 'api');

        $this->actingAs($this->owner)->post(route('api-keys.store'), ['name' => 'Intrus', 'bot_id' => $otherBot->id])->assertNotFound();
        $this->assertSame(1, ApiKey::withoutGlobalScopes()->where('workspace_id', $this->workspace->id)->count());
    }

    public function test_at_most_ten_active_keys(): void
    {
        for ($i = 0; $i < 9; $i++) {
            ApiKey::issue($this->workspace->id, $this->bot->id, "Clé {$i}");
        }

        $this->actingAs($this->owner)->post(route('api-keys.store'), ['name' => 'Onzième', 'bot_id' => $this->bot->id])->assertSessionHas('error');
        $this->assertSame(10, ApiKey::withoutGlobalScopes()->where('workspace_id', $this->workspace->id)->count());
    }

    public function test_revoking_a_key_stops_it_immediately_and_only_its_owner_can_do_it(): void
    {
        $key = ApiKey::withoutGlobalScopes()->firstOrFail();
        [, $stranger] = $this->tenant('Autre', 'api');

        $this->actingAs($stranger)->delete(route('api-keys.destroy', $key))->assertNotFound();
        $this->app['auth']->forgetGuards();
        $this->ask()->assertOk();

        $this->actingAs($this->owner)->delete(route('api-keys.destroy', $key))->assertSessionHas('status');

        $this->ask()->assertUnauthorized()->assertJsonPath('error.code', 'invalid_key');
        $this->assertNotNull($key->fresh()->revoked_at);
    }

    /* ---------- Authentification ---------- */

    public function test_every_authentication_error_has_a_stable_code_and_a_clear_message(): void
    {
        $this->postJson('/api/v1/chat', ['message' => 'Bonjour'])->assertUnauthorized()->assertJsonPath('error.code', 'missing_key');
        $this->postJson('/api/v1/chat', ['message' => 'Bonjour'], $this->auth('kma_inconnue'))->assertUnauthorized()->assertJsonPath('error.code', 'invalid_key');
        $this->postJson('/api/v1/chat', ['message' => 'Bonjour'], $this->auth('sans-prefixe'))->assertUnauthorized()->assertJsonPath('error.code', 'invalid_key');

        $this->workspace->update(['is_suspended' => true]);
        $this->ask()->assertForbidden()->assertJsonPath('error.code', 'account_suspended');
        $this->workspace->update(['is_suspended' => false, 'plan' => 'pro']);
        $this->ask()->assertForbidden()->assertJsonPath('error.code', 'plan_required');
    }

    public function test_the_key_is_scoped_to_its_assistant_and_workspace(): void
    {
        [$otherWorkspace, , $otherBot] = $this->tenant('Autre', 'api');
        $this->teach($otherBot, 'Secret', "# Secret\nLe code secret de l'autre client est 4321.");

        $answer = $this->ask('Quel est le code secret ?')->assertOk()->json('answer');

        $this->assertStringNotContainsString('4321', (string) $answer);
        $this->assertSame(0, Conversation::withoutGlobalScopes()->where('bot_id', $otherBot->id)->count());
        $this->assertSame(1, Conversation::withoutGlobalScopes()->where('bot_id', $this->bot->id)->where('channel', 'api')->count());
    }

    /* ---------- Chat ---------- */

    public function test_a_question_gets_a_sourced_answer_and_the_consumption_of_the_month(): void
    {
        $response = $this->ask(extra: ['conversation_id' => 'client-4821', 'user' => 'Fatou'])->assertOk();

        $response->assertJsonStructure(['conversation_id', 'answer', 'grounded', 'handoff', 'suggestions', 'sources', 'usage' => ['answers_used', 'answers_limit', 'resets_at']]);
        $this->assertSame('client-4821', $response->json('conversation_id'));
        $this->assertStringContainsString('8 h', $response->json('answer'));
        $this->assertTrue($response->json('grounded'));
        $this->assertFalse($response->json('handoff'));
        $this->assertSame(3000, $response->json('usage.answers_limit'));
        $this->assertSame(1, $response->json('usage.answers_used'));

        $this->assertNotNull(ApiKey::withoutGlobalScopes()->firstOrFail()->last_used_at);
    }

    public function test_the_same_conversation_id_keeps_the_thread_and_a_missing_one_creates_a_new_thread(): void
    {
        $this->ask(extra: ['conversation_id' => 'client-4821']);
        $this->ask('Et le dimanche ?', ['conversation_id' => 'client-4821']);
        $created = $this->ask()->json('conversation_id');

        $this->assertNotEmpty($created);
        $this->assertSame(2, Conversation::withoutGlobalScopes()->where('channel', 'api')->count());
        $this->assertSame(4, Conversation::withoutGlobalScopes()->where('external_id', 'client-4821')->firstOrFail()->messages()->count(), 'deux questions et deux reponses');
    }

    public function test_a_handoff_request_is_reported(): void
    {
        $this->ask('Je veux parler à un conseiller humain, maintenant')->assertOk()->assertJsonPath('handoff', true);
    }

    public function test_the_request_is_validated(): void
    {
        $this->postJson('/api/v1/chat', [], $this->auth())->assertUnprocessable()->assertJsonValidationErrors('message');
        $this->postJson('/api/v1/chat', ['message' => str_repeat('a', 2001)], $this->auth())->assertUnprocessable();
        $this->postJson('/api/v1/chat', ['message' => 'Bonjour', 'conversation_id' => 'court'], $this->auth())->assertUnprocessable()->assertJsonValidationErrors('conversation_id');
        $this->postJson('/api/v1/chat', ['message' => 'Bonjour', 'conversation_id' => 'id avec espaces !'], $this->auth())->assertUnprocessable();
    }

    public function test_the_monthly_quota_ends_with_a_clear_429_and_the_usage(): void
    {
        $plan = Plan::bySlug('api');
        $plan->update(['limits' => ['messages_per_month' => 2] + $plan->limits]);

        $this->ask()->assertOk();
        $this->ask()->assertOk();
        $this->ask()->assertStatus(429)->assertJsonPath('error.code', 'quota_exceeded')->assertJsonPath('usage.answers_limit', 2)->assertJsonPath('usage.answers_used', 2);
    }

    public function test_the_usage_endpoint_reports_the_plan_and_the_assistant(): void
    {
        $this->ask();

        $this->getJson('/api/v1/usage', $this->auth())->assertOk()
            ->assertJsonPath('plan', 'API Développeur')->assertJsonPath('usage.answers_used', 1)->assertJsonPath('assistant.name', $this->bot->name);
    }

    public function test_requests_are_limited_per_key(): void
    {
        for ($i = 0; $i < 60; $i++) {
            $this->getJson('/api/v1/usage', $this->auth())->assertOk();
        }

        $this->getJson('/api/v1/usage', $this->auth())->assertStatus(429);
    }

    /* ---------- Page publique ---------- */

    public function test_the_public_developer_page_shows_the_offer_in_the_visitors_currency(): void
    {
        $this->get(route('developers'))->assertOk()
            ->assertSee('Votre assistant, dans votre application.')->assertSee('API Développeur')->assertSee('/api/v1/chat', false)->assertSee('Authorization: Bearer kma_', false)
            ->assertSeeText("20\u{202F}000 FCFA");

        $this->get(route('developers', ['devise' => 'EUR']))->assertOk()->assertSeeText('30 €');
    }

    public function test_the_business_pages_never_list_the_developer_offer(): void
    {
        [, $proOwner] = $this->tenant('Boutique', 'pro');

        $this->get('/')->assertOk()->assertDontSee('API Développeur');
        $this->actingAs($proOwner)->get(route('billing.show'))->assertOk()->assertDontSee('API Développeur');

        // Un client développeur, lui, voit l'offre développeurs pour la renouveler.
        $this->actingAs($this->owner)->get(route('billing.show'))->assertOk()->assertSee('API Développeur')->assertDontSee('Choisir Pro');
    }

    public function test_the_menu_and_sitemap_lead_to_the_developer_page(): void
    {
        $this->get('/')->assertOk()->assertSee(route('developers'), false);
        $this->get('/sitemap.xml')->assertOk()->assertSee(route('developers'), false);
    }

    public function test_the_sidebar_shows_developers_only_for_the_api_option(): void
    {
        $this->actingAs($this->owner)->get(route('dashboard'))->assertOk()->assertSee(route('api-keys.index'), false);

        [, $proOwner] = $this->tenant('Pro', 'pro');
        $this->actingAs($proOwner)->get(route('dashboard'))->assertOk()->assertDontSee(route('api-keys.index'), false);
    }
}
