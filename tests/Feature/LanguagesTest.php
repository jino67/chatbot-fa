<?php

namespace Tests\Feature;

use App\Chat\InstructionGenerator;
use App\Chat\PromptBuilder;
use App\Models\Bot;
use App\Models\Message;
use App\Models\User;
use App\Support\Languages;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/** Langues parlées par l'assistant : plusieurs, modifiables à tout moment, avec un niveau de fiabilité annoncé. */
class LanguagesTest extends TestCase
{
    use CreatesTenants;
    use RefreshDatabase;

    private Bot $bot;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        [, $this->owner, $this->bot] = $this->tenant();
    }

    private function update(array $data)
    {
        return $this->actingAs($this->owner)->put(route('bots.update', $this->bot), $data + [
            'name' => 'Awa Bot', 'language' => 'fr', 'color' => '#2340D9', 'position' => 'right',
        ]);
    }

    /* ---------- Catalogue ---------- */

    public function test_the_catalog_tells_the_truth_about_each_language(): void
    {
        $this->assertSame('native', Languages::tier('fr'));
        $this->assertSame('good', Languages::tier('sw'));
        $this->assertSame('assisted', Languages::tier('bm'));
        $this->assertSame('experimental', Languages::tier('mos'));
        $this->assertTrue(Languages::isRtl('ar'));
        $this->assertFalse(Languages::isRtl('fr'));

        // Aucun modèle de transcription libre n'existe pour le shikomori : il ne promet pas le vocal.
        $this->assertNull(Languages::all()['zdj']['stt']);
        // Les langues locales exigent le serveur libre pour être écoutées.
        $this->assertSame('local', Languages::all()['dyu']['stt']);
    }

    public function test_normalize_keeps_known_languages_once_with_the_primary_first(): void
    {
        $this->assertSame(['dyu', 'fr', 'en'], Languages::normalize(['fr', 'en', 'fr', 'inconnue', 'dyu'], 'dyu'));
        $this->assertSame(['fr'], Languages::normalize([], null));
        $this->assertSame(['fr'], Languages::normalize(['klingon'], 'klingon'));
    }

    /* ---------- Réglages de l'assistant ---------- */

    public function test_the_client_picks_several_languages_and_a_primary_one(): void
    {
        $this->update(['language' => 'bm', 'languages' => ['fr', 'bm', 'dyu']])->assertSessionHasNoErrors();

        $bot = $this->bot->fresh();
        $this->assertSame('bm', $bot->language);
        $this->assertSame(['bm', 'fr', 'dyu'], $bot->languages, 'la langue principale passe en tête');
        $this->assertSame(['bm', 'fr', 'dyu'], $bot->spokenLanguages());
    }

    public function test_languages_can_be_changed_later_at_any_time(): void
    {
        $this->update(['language' => 'fr', 'languages' => ['fr', 'en']]);
        $this->assertSame(['fr', 'en'], $this->bot->fresh()->languages);

        $this->update(['language' => 'ar', 'languages' => ['ar']]);
        $this->assertSame(['ar'], $this->bot->fresh()->languages);
        $this->assertSame('ar', $this->bot->fresh()->language);
    }

    public function test_an_unknown_language_is_refused(): void
    {
        $this->update(['language' => 'fr', 'languages' => ['fr', 'klingon']])->assertSessionHasErrors('languages.1');
        $this->update(['language' => 'klingon'])->assertSessionHasErrors('language');
    }

    public function test_the_old_form_without_a_list_keeps_the_primary_language_only(): void
    {
        $this->update(['language' => 'en'])->assertSessionHasNoErrors();

        $this->assertSame(['en'], $this->bot->fresh()->languages);
    }

    public function test_the_creation_form_stores_the_chosen_languages(): void
    {
        $this->actingAs($this->owner)->post(route('bots.store'), [
            'name' => 'Nouvel assistant', 'language' => 'dyu', 'sector' => 'commerce',
            'tone' => 'chaleureux', 'formality' => 'vous', 'emojis' => 'light', 'length' => 'balanced',
            'languages' => ['fr', 'dyu'],
        ])->assertSessionHasNoErrors();

        $bot = Bot::withoutGlobalScopes()->where('name', 'Nouvel assistant')->firstOrFail();
        $this->assertSame(['dyu', 'fr'], $bot->languages);
        $this->assertSame('dyu', $bot->language);
        $this->assertSame(['dyu', 'fr'], $bot->profile['languages']);
    }

    public function test_the_settings_page_shows_every_language_with_its_reliability(): void
    {
        $this->actingAs($this->owner)->get(route('bots.edit', $this->bot))->assertOk()
            ->assertSee('Langues et voix')
            ->assertSee('Bamanankan')->assertSee('Julakan')->assertSee('Shikomori')
            ->assertSee('Assisté')->assertSee('Expérimental')->assertSee('Très bon')
            ->assertSee('name="languages[]"', false);
    }

    /* ---------- Prompt ---------- */

    public function test_a_single_language_bot_answers_in_that_language_only(): void
    {
        $rule = Languages::promptRule(['en']);

        $this->assertSame('- Réponds en anglais.', $rule);
    }

    public function test_a_multilingual_bot_follows_the_language_of_the_customer_within_its_list(): void
    {
        $rule = Languages::promptRule(['fr', 'en', 'ar']);

        $this->assertStringContainsString('Tu parles le français (langue principale), puis l\'anglais, l\'arabe', $rule);
        $this->assertStringContainsString('langue du dernier message', $rule);
        $this->assertStringNotContainsString('phrases courtes', $rule, 'aucune mise en garde pour des langues bien maîtrisées');
    }

    public function test_local_languages_come_with_a_caution_that_avoids_invented_words(): void
    {
        $rule = Languages::promptRule(['fr', 'bm', 'mos']);

        $this->assertStringContainsString('le bambara, le mooré', $rule);
        $this->assertStringContainsString("n'invente pas de mots", $rule);
        $this->assertStringContainsString('prix, chiffres et noms propres', $rule);
    }

    public function test_the_system_prompt_uses_the_current_languages_of_the_bot(): void
    {
        $this->bot->update(['language' => 'wo', 'languages' => ['wo', 'fr']]);

        $prompt = app(PromptBuilder::class)->system($this->bot->fresh());

        $this->assertStringContainsString('Tu parles le wolof (langue principale), puis le français', $prompt);
        $this->assertStringContainsString("n'invente pas de mots", $prompt);

        $this->bot->update(['language' => 'fr', 'languages' => ['fr']]);
        $this->assertStringContainsString('- Réponds en français.', app(PromptBuilder::class)->system($this->bot->fresh()));
    }

    public function test_the_generated_instructions_never_list_languages_so_they_cannot_contradict_the_settings(): void
    {
        $text = app(InstructionGenerator::class)->generate('Awa Bot', 'Boutique Awa', 'commerce', ['languages' => ['fr', 'en', 'bm']]);

        $this->assertStringNotContainsString('bambara', $text);
        $this->assertStringNotContainsString('anglais', $text);
        $this->assertStringContainsString('dans la langue principale du message', $text);
    }

    public function test_saving_the_personality_page_keeps_the_languages_of_the_settings(): void
    {
        $this->bot->update(['language' => 'fr', 'languages' => ['fr', 'dyu']]);

        $this->actingAs($this->owner)->put(route('instructions.profile', $this->bot), [
            'sector' => 'commerce', 'tone' => 'chaleureux', 'formality' => 'tu', 'emojis' => 'light', 'length' => 'balanced',
            'languages' => ['fr'],
        ])->assertSessionHasNoErrors();

        $this->assertSame(['fr', 'dyu'], $this->bot->fresh()->languages);
        $this->assertSame(['fr', 'dyu'], $this->bot->fresh()->profile['languages']);
    }

    /* ---------- Widget ---------- */

    public function test_the_public_config_lists_the_spoken_languages_with_their_direction(): void
    {
        $this->bot->update(['language' => 'fr', 'languages' => ['fr', 'ar', 'dyu']]);

        $config = $this->getJson('/api/v1/widget/'.$this->bot->public_key.'/config')->assertOk()->json();

        $this->assertSame(['fr', 'ar', 'dyu'], array_column($config['languages'], 'code'));
        $this->assertSame([false, true, false], array_column($config['languages'], 'rtl'));
        $this->assertSame('Julakan', $config['languages'][2]['name']);
        $this->assertFalse($config['rtl'], 'la direction du widget suit la langue principale');
    }

    public function test_the_language_picked_in_the_widget_is_validated_kept_and_given_to_the_model(): void
    {
        $this->bot->update(['language' => 'fr', 'languages' => ['fr', 'dyu']]);
        $base = '/api/v1/widget/'.$this->bot->public_key;
        $token = $this->postJson($base.'/conversations', ['visitor_id' => 'visiteur-12345678'])->assertOk()->json('token');

        $this->postJson($base."/conversations/{$token}/messages", ['content' => 'Bonjour', 'lang' => 'dyu'])->assertOk();
        $this->assertSame('dyu', Message::withoutGlobalScopes()->where('role', 'user')->firstOrFail()->meta['lang']);

        // Une langue que l'assistant ne parle pas est refusée.
        $this->postJson($base."/conversations/{$token}/messages", ['content' => 'Bonjour', 'lang' => 'en'])->assertStatus(422)->assertJsonValidationErrors('lang');
    }

    public function test_the_chosen_language_is_a_platform_instruction_placed_before_the_question(): void
    {
        $turn = app(PromptBuilder::class)->userTurn('Combien coûte une robe ?', [], false, 'dyu');

        $this->assertStringContainsString('Langue choisie par le visiteur : dioula. Réponds en dioula.', $turn);
        $this->assertLessThan(strpos($turn, 'Question du visiteur'), strpos($turn, 'Langue choisie'));

        $this->assertStringNotContainsString('Langue choisie', app(PromptBuilder::class)->userTurn('Bonjour', [], false, null));
        $this->assertStringNotContainsString('Langue choisie', app(PromptBuilder::class)->userTurn('Bonjour', [], false, 'klingon'));
    }

    public function test_another_clients_languages_cannot_be_changed(): void
    {
        [, $stranger] = $this->tenant('Autre entreprise');

        $this->actingAs($stranger)->put(route('bots.update', $this->bot), [
            'name' => 'Pirate', 'language' => 'en', 'languages' => ['en'], 'color' => '#2340D9', 'position' => 'right',
        ])->assertNotFound();

        $this->assertSame('fr', $this->bot->fresh()->language);
    }
}
