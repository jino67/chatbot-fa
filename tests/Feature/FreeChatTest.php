<?php

namespace Tests\Feature;

use App\Models\Bot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/** Réglage « conversation libre » d'un assistant : actif par défaut, coupable par le client, jamais perdu en route. */
class FreeChatTest extends TestCase
{
    use CreatesTenants;
    use RefreshDatabase;

    private Bot $bot;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        [, $this->owner, $this->bot] = $this->tenant('Boutique Awa', 'pro');
    }

    private function save(array $data)
    {
        return $this->actingAs($this->owner)->put(route('bots.update', $this->bot), $data + [
            'name' => 'Awa Bot', 'language' => 'fr', 'color' => '#2340D9', 'position' => 'right',
        ]);
    }

    public function test_a_new_assistant_chats_freely_and_the_settings_page_shows_the_box_checked(): void
    {
        $this->assertTrue($this->bot->allowsFreeChat());

        $page = $this->actingAs($this->owner)->get(route('bots.edit', $this->bot))->assertOk();
        $page->assertSee('Autoriser la conversation libre');
        $this->assertMatchesRegularExpression('/name="open_chat"[^>]*checked/', $page->getContent());
    }

    public function test_the_owner_can_turn_free_chat_off_and_on_again(): void
    {
        $this->save(['open_chat_shown' => 1])->assertSessionHas('status');
        $this->assertFalse($this->bot->fresh()->allowsFreeChat());

        $this->save(['open_chat_shown' => 1, 'open_chat' => 1])->assertSessionHas('status');
        $this->assertTrue($this->bot->fresh()->allowsFreeChat());
    }

    public function test_a_form_without_the_box_leaves_the_setting_alone(): void
    {
        $this->bot->update(['profile' => ['open_chat' => false]]);

        $this->save([])->assertSessionHas('status');

        $this->assertFalse($this->bot->fresh()->allowsFreeChat(), "un ancien formulaire n'allume pas la conversation libre");
    }

    public function test_saving_the_business_profile_keeps_the_free_chat_choice_and_the_imported_style(): void
    {
        $this->bot->update(['profile' => ['open_chat' => false, 'imported' => ['enabled' => true, 'style' => 'Phrases courtes.']]]);

        $this->actingAs($this->owner)->put(route('instructions.profile', $this->bot), [
            'sector' => 'commerce', 'hours' => 'Ouvert tous les jours', 'tone' => 'chaleureux', 'formality' => 'vous', 'emojis' => 'none', 'length' => 'balanced', 'languages' => ['fr'],
        ])->assertSessionHas('status');

        $fresh = $this->bot->fresh();
        $this->assertSame('Ouvert tous les jours', $fresh->profile['hours']);
        $this->assertFalse($fresh->allowsFreeChat());
        $this->assertSame('Phrases courtes.', $fresh->profile['imported']['style']);
    }
}
