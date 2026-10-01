<?php

namespace Tests\Feature;

use App\Mail\AnalyticsDigest;
use App\Models\AnalyticsEvent;
use App\Models\AnalyticsSession;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\Analytics\Stats;
use App\Services\Analytics\Tracker;
use App\Services\PlatformSettings;
use App\Support\Analytics;
use App\Support\CustomerRhythm;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/** La mesure d'audience, suite : actions de l'application, réglages, confidentialité, fiche client, résumé du lundi. */
class AnalyticsExtrasTest extends TestCase
{
    use CreatesTenants;
    use RefreshDatabase;

    private const VISITOR = 'AbCdEfGhIjKlMnOpQrSt';

    private const SESSION = 'ZyXwVuTsRqPoNmLkJiHg';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function beacon(array $events)
    {
        return $this->call('POST', '/a/e', [], [], [], [
            'CONTENT_TYPE' => 'text/plain;charset=UTF-8',
            'HTTP_USER_AGENT' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Mobile/15E148 Safari/604.1',
        ], json_encode(['v' => self::VISITOR, 's' => self::SESSION, 'e' => $events]));
    }

    private function pageview(string $path = '/'): array
    {
        return ['t' => 'pageview', 'n' => 'Page', 'p' => $path];
    }

    private function seedTraffic(): void
    {
        $day = now(Tracker::timezone());

        foreach (['a', 'b', 'c'] as $i => $key) {
            AnalyticsSession::create([
                'session_key' => str_pad('sess'.$key, 20, 'x'), 'visitor_key' => str_pad('vis'.$key, 20, 'x'), 'audience' => 'visitor',
                'started_at' => now(), 'last_seen_at' => now(), 'date' => $day->toDateString(), 'hour' => 10, 'dow' => 1,
                'pageviews' => 2, 'duration_seconds' => 60, 'is_bounce' => $i === 1, 'entry_path' => '/', 'exit_path' => '/', 'source' => 'search', 'device' => 'mobile',
            ]);
        }
    }

    /* ---------- Actions de l'application (middleware) ---------- */

    public function test_a_successful_action_is_recorded_without_touching_the_controller(): void
    {
        [$workspace, $client] = $this->tenant('Boutique Awa');

        $this->actingAs($client)->put(route('currency.update'), ['currency' => 'KMF'])->assertRedirect();

        $event = AnalyticsEvent::where('type', 'action')->firstOrFail();
        $this->assertSame('currency.changed', $event->name);
        $this->assertSame($workspace->id, (int) $event->workspace_id);
    }

    public function test_a_refused_request_is_not_an_action(): void
    {
        [, $client] = $this->tenant('Boutique Awa');

        $this->actingAs($client)->put(route('currency.update'), ['currency' => 'ZZZ']);

        $this->assertSame(0, AnalyticsEvent::where('type', 'action')->count());
    }

    public function test_registering_is_recorded_and_joins_the_visit_that_led_to_it(): void
    {
        $this->beacon([$this->pageview('/register')]);

        $this->withUnencryptedCookies(['_kv' => self::VISITOR, '_ks' => self::SESSION])->post('/register', [
            'name' => 'Awa Ouedraogo', 'email' => 'awa@example.com', 'company' => 'Boutique Awa',
            'password' => 'un-mot-de-passe-solide-2026', 'password_confirmation' => 'un-mot-de-passe-solide-2026',
        ]);

        $event = AnalyticsEvent::where('name', 'registered')->firstOrFail();
        $this->assertSame(AnalyticsSession::firstOrFail()->id, (int) $event->session_id, 'l\'inscription est rattachée à la visite');
    }

    /* ---------- Réglages ---------- */

    public function test_the_settings_section_saves_the_switch_the_digest_and_the_retention(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->put(route('admin.settings.update'), [
            'brand_name' => 'Kouma', 'analytics_enabled' => '0', 'analytics_digest' => '0', 'analytics_retention_days' => '90',
        ])->assertSessionHas('status');

        $tracker = app(Tracker::class);
        $this->assertFalse($tracker->enabled());
        $this->assertSame(90, $tracker->retentionDays());
        $this->assertFalse((bool) app(PlatformSettings::class)->get('analytics.digest', true));

        $this->actingAs($admin)->put(route('admin.settings.update'), ['brand_name' => 'Kouma', 'analytics_enabled' => '1'])->assertSessionHas('status');
        $this->assertTrue($tracker->enabled());
    }

    public function test_saving_other_settings_does_not_switch_measurement_off(): void
    {
        app(PlatformSettings::class)->set('analytics.enabled', true);

        $this->actingAs($this->admin())->put(route('admin.settings.update'), ['brand_name' => 'Kouma'])->assertSessionHas('status');

        $this->assertTrue(app(Tracker::class)->enabled());
    }

    public function test_the_retention_period_cannot_be_set_below_thirty_days(): void
    {
        $this->actingAs($this->admin())->put(route('admin.settings.update'), ['brand_name' => 'Kouma', 'analytics_retention_days' => '3'])->assertSessionHasErrors('analytics_retention_days');
    }

    /* ---------- Confidentialité ---------- */

    public function test_the_privacy_page_explains_the_measurement_and_offers_the_opt_out(): void
    {
        $this->get(route('legal.privacy'))->assertOk()
            ->assertSee('Mesure d\'audience', false)
            ->assertSee('_kv', false)->assertSee('_ks', false)->assertSee('_ko', false)
            ->assertSee('Ne plus me mesurer', false)
            ->assertSee('Ne pas suivre', false)
            ->assertSee((string) app(Tracker::class)->retentionDays());
    }

    /* ---------- Fiche d'un client et Analytique du client ---------- */

    public function test_the_workspace_page_shows_its_activity_to_staff(): void
    {
        [$workspace, $client] = $this->tenant('Boutique Awa');
        $this->actingAs($client);
        $this->beacon([$this->pageview('/bots/3/sources')]);
        Analytics::action('source.added');

        $this->actingAs($this->staff())->get(route('admin.workspaces.show', $workspace))
            ->assertOk()->assertSee('Activité dans l\'application', false)->assertSee('Connaissances')->assertSee('source.added');
    }

    public function test_a_client_sees_the_peak_hours_of_their_own_customers_only(): void
    {
        [$workspaceA, $clientA, $botA] = $this->tenant('Boutique A');
        [$workspaceB, , $botB] = $this->tenant('Boutique B');

        foreach ([[$workspaceA, $botA, 3], [$workspaceB, $botB, 9]] as [$workspace, $bot, $count]) {
            $conversation = Conversation::withoutGlobalScopes()->create(['workspace_id' => $workspace->id, 'bot_id' => $bot->id, 'channel' => 'web']);
            for ($i = 0; $i < $count; $i++) {
                Message::withoutGlobalScopes()->create(['workspace_id' => $workspace->id, 'conversation_id' => $conversation->id, 'role' => 'user', 'content' => 'Bonjour']);
            }
        }

        $this->actingAs($clientA);
        $this->assertSame(3, CustomerRhythm::forBot($botA)['total'], 'les 9 messages de l\'autre entreprise ne comptent pas');
        $this->get(route('analytics.show', $botA))->assertOk()->assertSee('Quand vos clients écrivent');
    }

    /* ---------- Résumé du lundi et entretien ---------- */

    public function test_the_weekly_digest_is_sent_with_the_key_figures(): void
    {
        Mail::fake();
        config(['platform.admin_email' => 'direction@kouma.example']);
        $this->seedTraffic();

        $this->artisan('analytics:digest')->assertExitCode(0);

        Mail::assertSent(AnalyticsDigest::class, fn ($mail) => $mail->hasTo('direction@kouma.example') && $mail->data['visits'] === 3 && str_contains($mail->envelope()->subject, '3 visite'));
    }

    public function test_the_digest_stays_quiet_without_visits_or_when_turned_off(): void
    {
        Mail::fake();
        config(['platform.admin_email' => 'direction@kouma.example']);

        $this->artisan('analytics:digest')->assertExitCode(0);
        Mail::assertNothingSent();

        $this->seedTraffic();
        app(PlatformSettings::class)->set('analytics.digest', false);
        $this->artisan('analytics:digest')->assertExitCode(0);
        Mail::assertNothingSent();
    }

    public function test_the_schedule_prunes_nightly_and_sends_the_digest_on_mondays(): void
    {
        $events = collect(app(Schedule::class)->events())->pluck('command')->implode(' ');

        $this->assertStringContainsString('analytics:prune', $events);
        $this->assertStringContainsString('analytics:digest', $events);
    }

    /* ---------- Le script et les textes ---------- */

    public function test_the_tracker_script_respects_the_signals_and_skips_admin_and_demo_pages(): void
    {
        $js = file_get_contents(base_path('resources/js/analytics.js'));

        $this->assertStringContainsString('doNotTrack', $js);
        $this->assertStringContainsString('globalPrivacyControl', $js);
        $this->assertStringContainsString('_ko', $js);
        $this->assertStringContainsString('(admin|demo)', $js, 'ni la console ni les démonstrations ne sont mesurées');
        $this->assertStringNotContainsString('.innerHTML', $js);
    }

    public function test_no_em_dash_in_the_measurement_code_or_texts(): void
    {
        foreach (['resources/js/analytics.js', 'config/analytics.php', 'app/Services/Analytics', 'app/Http/Controllers/Admin/StatisticsController.php', 'resources/views/admin/statistics', 'resources/views/components/stats', 'resources/views/emails/analytics-digest.blade.php'] as $path) {
            $files = is_dir(base_path($path)) ? collect(File::allFiles(base_path($path)))->map->getPathname()->all() : [base_path($path)];
            foreach ($files as $file) {
                $this->assertStringNotContainsString("\u{2014}", file_get_contents($file), "tiret cadratin dans {$file}");
            }
        }
    }

    public function test_the_landing_page_tags_its_sections_so_the_funnel_can_see_the_pricing(): void
    {
        $this->get('/')->assertOk()->assertSee('data-track-view="tarifs"', false)->assertSee('data-track-view="questions"', false);
        $this->assertSame(0, Stats::lastDays(1)->overview()['sessions']);
    }
}
