<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/** Rend chaque page du tableau de bord : detecte les erreurs de gabarit ou de requete avant les clients. */
class PagesSmokeTest extends TestCase
{
    use CreatesTenants;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_every_dashboard_page_renders_for_a_client_with_data(): void
    {
        [, $user, $bot] = $this->tenant();
        $this->teach($bot, 'Livraison', "# Livraison\nGratuite dès 25 000 FCFA.");

        $conversation = Conversation::withoutGlobalScopes()->create([
            'workspace_id' => $bot->workspace_id, 'bot_id' => $bot->id, 'channel' => 'web', 'external_id' => 'visiteur-12345678',
            'status' => Conversation::NEEDS_HUMAN, 'last_message_at' => now(),
        ]);
        foreach ([['user', 'Vous acceptez la carte bancaire ?'], ['assistant', 'Je n\'ai pas cette information.']] as $i => [$role, $text]) {
            $conversation->messages()->create([
                'workspace_id' => $bot->workspace_id, 'role' => $role, 'content' => $text,
                'meta' => $role === 'assistant' ? ['grounded' => false, 'llm' => false] : null,
            ]);
        }

        $urls = [
            route('dashboard'), route('bots.index'), route('bots.create'), route('bots.edit', $bot),
            route('sources.index', $bot), route('playground.show', $bot), route('conversations.index', $bot),
            route('conversations.index', [$bot, 'status' => 'needs_human']), route('conversations.show', [$bot, $conversation]),
            route('analytics.show', $bot), route('channels.show', $bot), route('profile.edit'),
        ];

        foreach ($urls as $url) {
            $this->actingAs($user)->get($url)->assertOk();
        }

        // La question restee sans reponse apparait dans l'analytique.
        $this->actingAs($user)->get(route('analytics.show', $bot))->assertSee('Vous acceptez la carte bancaire ?');
    }

    public function test_public_pages_render(): void
    {
        [, , $bot] = $this->tenant();

        $this->get('/')->assertOk()->assertSee('Kouma');
        $this->get('/login')->assertOk();
        $this->get('/register')->assertOk()->assertSee('Nom de votre entreprise');
        $this->get(route('demo', $bot->public_key))->assertOk()->assertSee('data-bot="'.$bot->public_key.'"', false);
    }

    public function test_the_demo_page_of_an_inactive_bot_is_not_public(): void
    {
        [, , $bot] = $this->tenant();
        $bot->update(['is_active' => false]);

        $this->get(route('demo', $bot->public_key))->assertNotFound();
    }

    public function test_a_super_admin_without_workspace_is_sent_to_the_back_office(): void
    {
        $this->actingAs($this->admin())->get(route('dashboard'))->assertRedirect(route('admin.overview'));
    }

    public function test_answering_an_unanswered_question_creates_a_qa_source_and_resolves_it(): void
    {
        [, $user, $bot] = $this->tenant();
        $conversation = Conversation::withoutGlobalScopes()->create([
            'workspace_id' => $bot->workspace_id, 'bot_id' => $bot->id, 'channel' => 'web', 'external_id' => 'visiteur-12345678',
        ]);
        $conversation->messages()->create(['workspace_id' => $bot->workspace_id, 'role' => 'user', 'content' => 'Acceptez-vous Coris Money ?']);
        $conversation->messages()->create(['workspace_id' => $bot->workspace_id, 'role' => 'assistant', 'content' => 'Je ne sais pas.', 'meta' => ['grounded' => false, 'llm' => false]]);

        $this->actingAs($user)->post(route('analytics.answer', $bot), [
            'question' => 'Acceptez-vous Coris Money ?', 'answer' => 'Oui, Coris Money est accepté à la livraison.',
        ])->assertSessionHas('status');

        $this->assertSame(1, $bot->sources()->where('type', 'qa')->count());
        $this->actingAs($user)->get(route('analytics.show', $bot))->assertDontSee('Acceptez-vous Coris Money ?');
        $this->assertTrue(Message::withoutGlobalScopes()->where('role', 'assistant')->first()->meta['resolved']);
    }

    public function test_conversation_status_actions(): void
    {
        [, $user, $bot] = $this->tenant();
        $conversation = Conversation::withoutGlobalScopes()->create([
            'workspace_id' => $bot->workspace_id, 'bot_id' => $bot->id, 'channel' => 'web', 'external_id' => 'visiteur-12345678', 'status' => 'needs_human',
        ]);

        foreach (['take' => 'human', 'release' => 'bot', 'close' => 'closed'] as $action => $expected) {
            $this->actingAs($user)->post(route('conversations.status', [$bot, $conversation]), ['action' => $action])->assertRedirect();
            $this->assertSame($expected, $conversation->fresh()->status);
        }

        $this->actingAs($user)->post(route('conversations.status', [$bot, $conversation]), ['action' => 'exploser'])->assertSessionHasErrors('action');
    }

    public function test_playground_answers_and_does_not_pollute_real_statistics(): void
    {
        [, $user, $bot] = $this->tenant();
        $this->teach($bot, 'Horaires', "# Horaires\nOuvert de 8 h à 19 h du lundi au samedi.");

        $this->actingAs($user)->postJson(route('playground.ask', $bot), ['message' => "Quels sont les horaires d'ouverture de la boutique le samedi ?"])
            ->assertOk()->assertJson(['grounded' => true])->assertJsonStructure(['reply', 'top_score', 'sources']);

        $this->assertSame(0, $bot->conversations()->real()->count());
        $this->assertSame(1, $bot->conversations()->count());
    }

    public function test_bot_settings_are_saved_and_origins_are_normalized(): void
    {
        [, $user, $bot] = $this->tenant();

        $this->actingAs($user)->put(route('bots.update', $bot), [
            'name' => 'Awa Bot', 'language' => 'ar', 'color' => '#0f766e', 'position' => 'left', 'title' => 'Boutique Awa',
            'allowed_origins' => "https://www.boutique.com/page/x\nboutique.com\n\nhttps://*.boutique.com",
            'suggested_questions' => "Horaires ?\nLivraison ?\n\nPaiement ?",
            'instructions' => 'Vouvoie.', 'is_active' => '1',
        ])->assertRedirect(route('bots.edit', $bot));

        $bot->refresh();
        $this->assertSame(['https://www.boutique.com', 'https://boutique.com', 'https://*.boutique.com'], $bot->allowed_origins);
        $this->assertSame(['Horaires ?', 'Livraison ?', 'Paiement ?'], $bot->suggested_questions);
        $this->assertSame('ar', $bot->publicConfig()['language']);
        $this->assertTrue($bot->publicConfig()['rtl']);
    }

    public function test_invalid_colors_are_rejected_so_they_cannot_break_the_widget_css(): void
    {
        [, $user, $bot] = $this->tenant();

        $this->actingAs($user)->put(route('bots.update', $bot), [
            'name' => 'X', 'language' => 'fr', 'color' => 'red;}body{display:none', 'position' => 'right',
        ])->assertSessionHasErrors('color');
    }
}
