<?php

namespace Tests\Feature;

use App\Models\Bot;
use App\Models\Plan;
use App\Models\Source;
use App\Retrieval\Retriever;
use App\Services\LandingBot;
use App\Services\PlatformSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/** L'assistant de la page d'accueil : ses connaissances suivent les offres, et « combien ça coûte ? » trouve la bonne source. */
class LandingBotTest extends TestCase
{
    use CreatesTenants;
    use RefreshDatabase;

    private function content(Bot $bot, string $title): string
    {
        return Source::withoutGlobalScopes()->where('bot_id', $bot->id)->where('name', $title)->firstOrFail()->payload['content'];
    }

    public function test_the_command_creates_the_landing_assistant_from_the_current_offers(): void
    {
        $this->assertFalse(app(LandingBot::class)->exists());

        $this->artisan('platform:landing-bot')->expectsOutputToContain('à jour')->assertSuccessful();

        $key = app(PlatformSettings::class)->get('marketing.landing_bot_key');
        $bot = Bot::withoutGlobalScopes()->where('public_key', $key)->firstOrFail();
        $this->assertSame('Assistant Kouma', $bot->name);
        $this->assertTrue(app(LandingBot::class)->exists());

        $prices = $this->content($bot, 'Prix et tarifs');
        foreach (['Essentiel à 10', 'Bon plan à 25', 'Pro à 40', 'Business à 120', '14 jours'] as $needle) {
            $this->assertStringContainsString($needle, $prices);
        }
        $this->assertSame(0, $bot->sources()->where('status', '!=', Source::READY)->count(), 'toutes les sources sont prêtes');
        $this->assertGreaterThanOrEqual(12, $bot->sources()->count());
    }

    public function test_a_price_change_reaches_the_assistant_and_unchanged_sources_are_not_read_again(): void
    {
        $landing = app(LandingBot::class);
        $bot = $landing->sync();
        $untouched = Source::withoutGlobalScopes()->where('bot_id', $bot->id)->where('name', 'WhatsApp')->firstOrFail();
        $syncedAt = $untouched->last_synced_at;
        $this->travel(5)->minutes();

        $plan = Plan::where('slug', 'bonplan')->firstOrFail();
        $plan->prices = ['XOF' => 30000] + $plan->prices;
        $plan->limits = ['whatsapp_messages_per_month' => 1000] + $plan->limits;
        $plan->save();

        $landing->sync();

        $this->assertStringContainsString('Bon plan à 30'."\u{202F}".'000 FCFA', $this->content($bot, 'Prix et tarifs'));
        $this->assertStringNotContainsString('25'."\u{202F}".'000 FCFA', $this->content($bot, 'Prix et tarifs'));
        $this->assertStringContainsString('1000 messages WhatsApp par mois', $this->content($bot, 'Offres en détail'));
        $this->assertEquals($syncedAt, $untouched->fresh()->last_synced_at, 'une source inchangée n\'est pas relue');
        $this->assertSame(1, Source::withoutGlobalScopes()->where('bot_id', $bot->id)->where('name', 'Prix et tarifs')->count(), 'la source est mise à jour, pas dupliquée');
    }

    public function test_a_source_that_no_longer_exists_in_the_texts_stops_answering(): void
    {
        $landing = app(LandingBot::class);
        $bot = $landing->sync();
        Source::withoutGlobalScopes()->create(['workspace_id' => $bot->workspace_id, 'bot_id' => $bot->id, 'type' => Source::TYPE_TEXT, 'name' => 'Ancienne grille', 'status' => Source::READY, 'payload' => ['content' => 'Pro : 30 000 FCFA']]);

        $landing->sync();

        $this->assertSame(0, Source::withoutGlobalScopes()->where('bot_id', $bot->id)->where('name', 'Ancienne grille')->count());
    }

    public function test_the_short_pricing_questions_find_the_pricing_source(): void
    {
        $bot = app(LandingBot::class)->sync();

        foreach (['Combien ça coûte ?', 'Quels sont les tarifs ?'] as $question) {
            $found = app(Retriever::class)->retrieve($bot, $question);
            $this->assertNotEmpty($found, $question);
            // Les pages du site (guide des prix) peuvent passer devant : la réponse chiffrée doit être dans ce que l'assistant lit.
            $this->assertTrue(collect($found)->contains(fn ($chunk) => str_contains($chunk->content, 'Bon plan')), $question);
        }
    }

    public function test_a_brief_technical_failure_is_retried_so_the_assistant_is_never_left_without_knowledge(): void
    {
        $calls = [];
        $pipeline = \Mockery::mock(\App\Ingestion\IngestionPipeline::class);
        $pipeline->shouldReceive('run')->andReturnUsing(function (Source $source) use (&$calls) {
            $calls[$source->name] = ($calls[$source->name] ?? 0) + 1;
            $fails = $source->name === 'Prix et tarifs' && $calls[$source->name] < 3;
            $source->forceFill($fails ? ['status' => Source::FAILED, 'error' => 'Erreur technique : cURL error 6: Could not resolve host'] : ['status' => Source::READY, 'error' => null])->save();
        });
        $this->app->instance(\App\Ingestion\IngestionPipeline::class, $pipeline);

        $landing = app(LandingBot::class);
        $landing->retryDelay = 0;
        $bot = $landing->sync();

        $this->assertSame(3, $calls['Prix et tarifs'], 'deux échecs passagers puis la réussite');
        $this->assertSame(1, $calls['WhatsApp'], 'une source qui réussit n\'est lue qu\'une fois');
        $this->assertSame(0, $bot->sources()->where('status', Source::FAILED)->count());
    }

    public function test_an_expected_failure_is_not_retried(): void
    {
        $calls = 0;
        $pipeline = \Mockery::mock(\App\Ingestion\IngestionPipeline::class);
        $pipeline->shouldReceive('run')->andReturnUsing(function (Source $source) use (&$calls) {
            $calls++;
            $source->forceFill(['status' => Source::FAILED, 'error' => "Aucun contenu exploitable n'a été trouvé."])->save();
        });
        $this->app->instance(\App\Ingestion\IngestionPipeline::class, $pipeline);

        $landing = app(LandingBot::class);
        $landing->retryDelay = 0;
        $count = count($landing->knowledge());
        $landing->sync();

        $this->assertSame($count, $calls, 'un échec attendu (contenu vide) ne se rejoue pas');
    }
    public function test_the_assistant_knows_the_content_pages_of_the_site_with_current_prices(): void
    {
        $landing = app(LandingBot::class);
        $bot = $landing->sync();

        $pages = Source::withoutGlobalScopes()->where('bot_id', $bot->id)->where('name', 'like', 'Page : %')->pluck('name');
        $this->assertCount(count(\App\Support\SeoPages::all()), $pages, 'une source par page de contenu');

        $guide = $this->content($bot, 'Page : Combien coûte un chatbot WhatsApp ?');
        $this->assertStringContainsString('Combien coûte un chatbot WhatsApp pour une petite entreprise ?', $guide);
        $this->assertStringContainsString('Bon plan, 25'."\u{202F}".'000 FCFA par mois', $guide);
        $this->assertStringContainsString('Page du site : '.url('guides/combien-coute-un-chatbot-whatsapp'), $guide);
        $this->assertStringNotContainsString('{price:', $guide);

        // Un prix qui change se retrouve dans la page, donc dans l'assistant.
        $plan = Plan::where('slug', 'bonplan')->firstOrFail();
        $plan->prices = ['XOF' => 30000] + $plan->prices;
        $plan->save();
        $landing->sync();
        $this->assertStringContainsString('Bon plan, 30'."\u{202F}".'000 FCFA par mois', $this->content($bot, 'Page : Combien coûte un chatbot WhatsApp ?'));

        // Une question sur un métier retrouve la page du métier.
        $found = app(Retriever::class)->retrieve($bot, 'chatbot whatsapp pour un restaurant menu et réservation');
        $this->assertNotEmpty($found);
        $this->assertStringContainsString('restaurant', mb_strtolower($found[0]->content));
    }
    public function test_the_contact_and_languages_come_from_the_settings_and_the_language_sheet(): void
    {
        app(PlatformSettings::class)->set('brand.email', 'contac@kouma.site');
        app(PlatformSettings::class)->set('brand.whatsapp', '+226 70 00 00 00');

        $bot = app(LandingBot::class)->sync();

        $this->assertStringContainsString('e-mail contac@kouma.site, WhatsApp +22670000000', $this->content($bot, 'Accompagnement et contact'));
        $languages = $this->content($bot, 'Langues et messages vocaux');
        $this->assertStringContainsString('Wolof', $languages);
        $this->assertStringContainsString('expérimentales', $languages);
        $this->assertStringNotContainsString("\u{2014}", implode("\n", app(LandingBot::class)->knowledge()), 'aucun tiret cadratin');
    }

    public function test_saving_a_plan_in_the_admin_refreshes_the_landing_assistant_only_when_it_exists(): void
    {
        $super = \App\Models\User::factory()->create(['role' => \App\Models\User::SUPER_ADMIN]);
        $plan = Plan::where('slug', 'pro')->firstOrFail();
        $data = ['name' => $plan->name, 'tagline' => 'Offre mise à jour', 'prices' => ['XOF' => 45000], 'period_months' => 1, 'sort' => $plan->sort, 'limits' => $plan->limits, 'is_public' => 1];

        // Sans assistant de vitrine, enregistrer une offre n'en crée pas.
        $this->actingAs($super)->put(route('admin.plans.update', $plan), $data);
        $this->assertFalse(app(LandingBot::class)->exists());

        // Avec un assistant de vitrine, la grille est relue une fois la réponse envoyée.
        $bot = app(LandingBot::class)->sync();
        $this->actingAs($super)->put(route('admin.plans.update', $plan), $data);

        $this->assertStringContainsString('Pro à 45'."\u{202F}".'000 FCFA', $this->content($bot, 'Prix et tarifs'));
    }
}
