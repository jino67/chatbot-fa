<?php

namespace Tests\Feature;

use App\Models\Bot;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\FacebookConnection;
use App\Models\WhatsAppTemplate;
use App\Retrieval\Retriever;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class TenantIsolationTest extends TestCase
{
    use CreatesTenants;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_a_user_only_sees_the_bots_of_their_own_workspace(): void
    {
        [, $userA, $botA] = $this->tenant('Client A');
        [, , $botB] = $this->tenant('Client B');

        $this->actingAs($userA);

        $this->assertSame([$botA->id], Bot::pluck('id')->all());
        $this->assertNull(Bot::find($botB->id));
    }

    public function test_another_tenants_pages_are_not_found(): void
    {
        [, $userA] = $this->tenant('Client A');
        [, , $botB] = $this->tenant('Client B');

        $this->actingAs($userA);

        foreach (['sources.index', 'playground.show', 'conversations.index', 'analytics.show', 'channels.show'] as $route) {
            $this->get(route($route, $botB))->assertNotFound();
        }
        $this->get(route('bots.edit', $botB))->assertNotFound();
        $this->delete(route('bots.destroy', $botB))->assertNotFound();
        $this->assertNotNull(Bot::withoutGlobalScopes()->find($botB->id), "le bot d'un autre client ne doit pas etre supprime");
    }

    public function test_a_source_cannot_be_deleted_through_another_bots_url(): void
    {
        [, $userA, $botA] = $this->tenant('Client A');
        [, , $botB] = $this->tenant('Client B');
        $sourceB = $this->teach($botB, 'Prix', 'Le boubou brodé coûte 35 000 FCFA pour les clients de B.');

        $this->actingAs($userA)
            ->delete(route('sources.destroy', [$botA, $sourceB]))
            ->assertNotFound();

        $this->assertDatabaseHas('sources', ['id' => $sourceB->id]);
    }

    public function test_new_records_are_attached_to_the_authenticated_users_workspace(): void
    {
        [$workspace, $user] = $this->tenant('Client A');

        $this->actingAs($user)->post(route('bots.store'), [
            'name' => 'Nouveau', 'language' => 'fr', 'sector' => 'commerce',
            'tone' => 'chaleureux', 'formality' => 'vous', 'emojis' => 'light', 'length' => 'balanced', 'languages' => ['fr'],
        ])->assertRedirect();

        $this->assertSame($workspace->id, Bot::where('name', 'Nouveau')->firstOrFail()->workspace_id);
    }

    public function test_retrieval_never_returns_chunks_from_another_bot(): void
    {
        [, , $botA] = $this->tenant('Client A');
        [, , $botB] = $this->tenant('Client B');
        $this->teach($botA, 'Tarifs', 'Le boubou brodé pour homme coûte 35 000 FCFA.');

        $retriever = app(Retriever::class);

        $this->assertNotEmpty($retriever->retrieve($botA, 'prix du boubou brodé'));
        $this->assertSame([], $retriever->retrieve($botB, 'prix du boubou brodé'));
    }
    public function test_templates_instructions_and_facebook_pages_of_another_tenant_are_not_found(): void
    {
        [, $userA] = $this->tenant('Client A');
        [$workspaceB, , $botB] = $this->tenant('Client B');
        $channelB = Channel::withoutGlobalScopes()->create([
            'workspace_id' => $workspaceB->id, 'bot_id' => $botB->id, 'type' => Channel::WHATSAPP_META, 'status' => Channel::ACTIVE,
            'external_ref' => '42', 'credentials' => ['access_token' => 'jeton-de-B', 'waba_id' => '7'],
        ]);
        $templateB = WhatsAppTemplate::withoutGlobalScopes()->create([
            'workspace_id' => $workspaceB->id, 'channel_id' => $channelB->id, 'external_id' => 'tpl-b', 'name' => 'modele_prive_de_b',
            'language' => 'fr', 'category' => 'UTILITY', 'status' => WhatsAppTemplate::APPROVED, 'body' => 'Bonjour {{1}}', 'variables_count' => 1,
        ]);
        $conversationB = Conversation::withoutGlobalScopes()->create([
            'workspace_id' => $workspaceB->id, 'bot_id' => $botB->id, 'channel' => 'whatsapp', 'external_id' => '22670000000', 'last_inbound_at' => now()->subHours(30),
        ]);
        Http::fake();

        $this->actingAs($userA);

        foreach (['templates.index', 'instructions.edit', 'facebook.connect'] as $route) {
            $this->get(route($route, $botB))->assertNotFound();
        }
        $this->post(route('templates.sync', $botB))->assertNotFound();
        $this->put(route('instructions.update', $botB), ['instructions' => 'piratée'])->assertNotFound();
        $this->post(route('instructions.reset', $botB))->assertNotFound();
        $this->post(route('conversations.template', [$botB, $conversationB]), ['template_id' => $templateB->id, 'variables' => ['A']])->assertNotFound();
        $this->delete(route('templates.destroy', [$botB, $templateB]))->assertNotFound();

        $this->assertNotSame('piratée', $botB->fresh()->instructions);
        $this->assertNotNull(WhatsAppTemplate::withoutGlobalScopes()->find($templateB->id));
        Http::assertNothingSent();
    }

    public function test_template_and_facebook_records_are_filtered_by_workspace_when_authenticated(): void
    {
        [, $userA] = $this->tenant('Client A');
        [$workspaceB, , $botB] = $this->tenant('Client B');
        $channelB = Channel::withoutGlobalScopes()->create([
            'workspace_id' => $workspaceB->id, 'bot_id' => $botB->id, 'type' => Channel::WHATSAPP_META, 'status' => Channel::ACTIVE,
            'external_ref' => '42', 'credentials' => ['access_token' => 'jeton-de-B', 'waba_id' => '7'],
        ]);
        $templateB = WhatsAppTemplate::withoutGlobalScopes()->create([
            'workspace_id' => $workspaceB->id, 'channel_id' => $channelB->id, 'external_id' => 'tpl-b', 'name' => 'modele_prive_de_b',
            'language' => 'fr', 'category' => 'UTILITY', 'status' => WhatsAppTemplate::APPROVED, 'body' => 'Bonjour', 'variables_count' => 0,
        ]);
        $connectionB = FacebookConnection::withoutGlobalScopes()->create([
            'workspace_id' => $workspaceB->id, 'bot_id' => $botB->id, 'page_id' => '999', 'page_name' => 'Page de B', 'access_token' => 'jeton-page-de-B',
        ]);

        $this->actingAs($userA);

        $this->assertNull(WhatsAppTemplate::find($templateB->id));
        $this->assertNull(FacebookConnection::find($connectionB->id));
        $this->assertSame(0, WhatsAppTemplate::count());
        $this->assertSame(0, FacebookConnection::count());
    }
}
