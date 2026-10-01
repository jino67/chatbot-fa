<?php

namespace Tests\Feature;

use App\Models\AnalyticsEvent;
use App\Models\AnalyticsSession;
use App\Services\Analytics\ClientStats;
use App\Services\Analytics\Insights;
use App\Services\Analytics\Stats;
use App\Services\Analytics\Tracker;
use App\Services\PlatformSettings;
use App\Support\Analytics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/** La mesure d'audience : ce qu'elle enregistre, ce qu'elle refuse d'enregistrer, et ce qu'elle en déduit. */
class AnalyticsTest extends TestCase
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

    /** Envoie un lot comme le fait navigator.sendBeacon : du texte brut, sans jeton CSRF. */
    private function beacon(array $events, array $extra = [], array $headers = [], array $cookies = [])
    {
        $payload = array_merge(['v' => self::VISITOR, 's' => self::SESSION, 'e' => $events], $extra);

        $server = ['CONTENT_TYPE' => 'text/plain;charset=UTF-8', 'HTTP_USER_AGENT' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Mobile/15E148 Safari/604.1'];
        foreach ($headers as $name => $value) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }

        return $this->call('POST', '/a/e', [], $cookies, [], $server, json_encode($payload));
    }

    private function pageview(string $path = '/', array $more = []): array
    {
        return ['t' => 'pageview', 'n' => 'Accueil', 'p' => $path] + $more;
    }

    /* ---------- Collecte ---------- */

    public function test_a_pageview_creates_a_visit_with_its_source_and_device(): void
    {
        $this->beacon([$this->pageview('/')], ['m' => ['r' => 'https://www.google.com/search?q=chatbot', 'tz' => 'Africa/Ouagadougou', 'l' => 'fr-BF']])->assertNoContent();

        $session = AnalyticsSession::firstOrFail();
        $this->assertSame('visitor', $session->audience);
        $this->assertSame('search', $session->source);
        $this->assertSame('google.com', $session->referrer_host);
        $this->assertSame('mobile', $session->device);
        $this->assertSame('BF', $session->country);
        $this->assertSame('/', $session->entry_path);
        $this->assertSame(1, $session->pageviews);
        $this->assertTrue($session->is_bounce);
        $this->assertTrue($session->is_new);
    }

    public function test_the_date_is_stored_as_a_plain_day_so_period_queries_work(): void
    {
        $this->beacon([$this->pageview()]);

        $raw = \DB::table('analytics_sessions')->value('date');
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', (string) $raw);
        $this->assertSame(1, Stats::lastDays(1)->overview()['sessions']);
    }

    public function test_a_click_ends_the_bounce_and_pages_are_counted(): void
    {
        $this->beacon([$this->pageview('/')]);
        $this->beacon([$this->pageview('/ressources'), ['t' => 'click', 'n' => 'Voir les offres', 'p' => '/ressources', 'c' => true, 'g' => '/register']]);

        $session = AnalyticsSession::firstOrFail();
        $this->assertSame(2, $session->pageviews);
        $this->assertFalse($session->is_bounce);
        $this->assertSame('/ressources', $session->exit_path);
        $this->assertSame(1, AnalyticsSession::count(), 'une seule visite, même en plusieurs lots');
    }

    public function test_paths_are_normalised_and_query_strings_dropped(): void
    {
        $this->beacon([
            $this->pageview('/bots/12/sources?token=secret'),
            $this->pageview('/conversations/8f14e45f-ceea-467a-9575-1a2b3c4d5e6f'),
        ]);

        $paths = AnalyticsEvent::pluck('path')->all();
        $this->assertContains('/bots/:id/sources', $paths);
        $this->assertContains('/conversations/:token', $paths);
        $this->assertStringNotContainsString('secret', implode('', $paths));
    }

    public function test_emails_and_phone_numbers_in_labels_are_erased(): void
    {
        $this->beacon([['t' => 'click', 'n' => 'Écrire à awa@example.com ou appeler +226 70 12 34 56', 'p' => '/', 'o' => ['mail' => 'bob@example.com']]]);

        $event = AnalyticsEvent::where('type', 'click')->firstOrFail();
        $this->assertStringNotContainsString('awa@example.com', $event->name);
        $this->assertStringNotContainsString('70 12 34', $event->name);
        $this->assertStringContainsString('[e-mail]', $event->name);
        $this->assertSame('[e-mail]', $event->props['mail']);
    }

    public function test_contact_links_never_keep_the_address_or_number(): void
    {
        $this->beacon([['t' => 'contact', 'n' => 'telephone', 'p' => '/', 'g' => 'tel:+22670123456']]);

        $this->assertNull(AnalyticsEvent::where('type', 'contact')->value('target'));
    }

    public function test_unknown_event_types_and_oversized_batches_are_ignored(): void
    {
        $this->beacon([['t' => 'drop_table', 'p' => '/']]);
        $this->assertSame(0, AnalyticsEvent::count());

        $this->call('POST', '/a/e', [], [], [], ['CONTENT_TYPE' => 'text/plain', 'HTTP_USER_AGENT' => 'Mozilla/5.0 Safari'], str_repeat('x', 21000))->assertNoContent();
        $this->assertSame(0, AnalyticsSession::count());
    }

    public function test_invalid_identifiers_are_refused(): void
    {
        $this->beacon([$this->pageview()], ['v' => 'court']);
        $this->beacon([$this->pageview()], ['s' => '../../etc/passwd']);

        $this->assertSame(0, AnalyticsSession::count());
    }

    public function test_the_values_of_events_are_bounded(): void
    {
        $this->beacon([$this->pageview(), ['t' => 'scroll', 'x' => 999, 'p' => '/'], ['t' => 'engage', 'x' => 99999, 'p' => '/']]);

        $this->assertSame(100, (int) AnalyticsEvent::where('type', 'scroll')->value('value'));
        $this->assertSame(1800, (int) AnalyticsEvent::where('type', 'engage')->value('value'));
    }

    public function test_the_collector_always_answers_204_and_needs_no_csrf_token(): void
    {
        $this->beacon([])->assertNoContent();
        $this->call('POST', '/a/e', [], [], [], ['CONTENT_TYPE' => 'text/plain'], 'pas du json')->assertNoContent();
    }

    /* ---------- Vie privée ---------- */

    public function test_do_not_track_global_privacy_control_and_the_opt_out_cookie_are_respected(): void
    {
        $this->beacon([$this->pageview()], [], ['DNT' => '1']);
        $this->beacon([$this->pageview()], [], ['Sec-GPC' => '1']);
        $this->beacon([$this->pageview()], [], [], ['_ko' => '1']);

        $this->assertSame(0, AnalyticsSession::count());
        $this->assertSame(0, AnalyticsEvent::count());
    }

    public function test_robots_and_other_origins_are_not_counted(): void
    {
        $this->call('POST', '/a/e', [], [], [], ['CONTENT_TYPE' => 'text/plain', 'HTTP_USER_AGENT' => 'Googlebot/2.1'], json_encode(['v' => self::VISITOR, 's' => self::SESSION, 'e' => [$this->pageview()]]));
        $this->beacon([$this->pageview()], [], ['Origin' => 'https://evil.example']);

        $this->assertSame(0, AnalyticsSession::count());
    }

    public function test_nothing_is_recorded_when_the_platform_turns_measurement_off(): void
    {
        app(PlatformSettings::class)->set('analytics.enabled', false);

        $this->beacon([$this->pageview()]);

        $this->assertSame(0, AnalyticsSession::count());
    }

    public function test_no_ip_address_is_stored_anywhere(): void
    {
        $this->call('POST', '/a/e', [], [], [], ['CONTENT_TYPE' => 'text/plain', 'REMOTE_ADDR' => '203.0.113.77', 'HTTP_USER_AGENT' => 'Mozilla/5.0 (Windows NT 10.0) Chrome/120 Safari/537.36'], json_encode(['v' => self::VISITOR, 's' => self::SESSION, 'e' => [$this->pageview()]]));

        $dump = json_encode(\DB::table('analytics_sessions')->get()).json_encode(\DB::table('analytics_events')->get());
        $this->assertStringNotContainsString('203.0.113.77', $dump);
    }

    public function test_the_page_only_loads_the_tracker_when_measurement_is_on(): void
    {
        $this->get('/')->assertOk()->assertSee('name="kouma-analytics"', false);

        app(PlatformSettings::class)->set('analytics.enabled', false);
        $this->get('/')->assertOk()->assertDontSee('name="kouma-analytics"', false);
    }

    /* ---------- Public : visiteur, client, équipe ---------- */

    public function test_a_signed_in_client_is_a_client_visit_and_staff_are_kept_apart(): void
    {
        [$workspace, $client] = $this->tenant('Boutique Awa');
        $staff = $this->staff();

        $this->actingAs($client)->beacon([$this->pageview('/dashboard')]);
        $this->actingAs($staff)->beacon([$this->pageview('/admin')], ['s' => 'QqWwEeRrTtYyUuIiOoPp']);

        $this->assertSame('client', AnalyticsSession::where('session_key', self::SESSION)->value('audience'));
        $this->assertSame($workspace->id, (int) AnalyticsSession::where('session_key', self::SESSION)->value('workspace_id'));
        $this->assertSame('staff', AnalyticsSession::where('session_key', 'QqWwEeRrTtYyUuIiOoPp')->value('audience'));

        $this->assertSame(1, Stats::lastDays(1, 'all')->overview()['sessions'], 'les visites de l\'équipe sont exclues de « tous »');
    }

    public function test_a_visit_that_starts_anonymous_and_then_signs_in_becomes_the_clients(): void
    {
        [, $client] = $this->tenant('Boutique Awa');

        $this->beacon([$this->pageview('/login')]);
        $this->assertSame('visitor', AnalyticsSession::firstOrFail()->audience);

        $this->actingAs($client)->beacon([$this->pageview('/dashboard')]);
        $this->assertSame('client', AnalyticsSession::firstOrFail()->audience);
    }

    public function test_server_side_actions_are_recorded_with_the_user_and_workspace(): void
    {
        [$workspace, $client] = $this->tenant('Boutique Awa');
        $this->actingAs($client);

        Analytics::action('bot.created', ['sector' => 'commerce']);

        $event = AnalyticsEvent::where('type', 'action')->firstOrFail();
        $this->assertSame('bot.created', $event->name);
        $this->assertSame($client->id, (int) $event->user_id);
        $this->assertSame($workspace->id, (int) $event->workspace_id);
        $this->assertSame('client', $event->audience);
        $this->assertSame('commerce', $event->props['sector']);
    }

    public function test_actions_respect_the_visitors_refusal_and_never_throw(): void
    {
        [, $client] = $this->tenant('Boutique Awa');
        $this->actingAs($client);

        // Une vraie requête portant le cookie de refus : l'action qui la suit ne doit rien enregistrer.
        $refusing = \Illuminate\Http\Request::create('/dashboard', 'GET', [], ['_ko' => '1']);
        app(Tracker::class)->action('bot.created', [], null, null, $refusing);
        $this->assertSame(0, AnalyticsEvent::count());

        $dnt = \Illuminate\Http\Request::create('/dashboard', 'GET', [], [], [], ['HTTP_DNT' => '1']);
        app(Tracker::class)->action('bot.created', [], null, null, $dnt);
        $this->assertSame(0, AnalyticsEvent::count());

        app(Tracker::class)->action('bot.created', [], null, null, \Illuminate\Http\Request::create('/dashboard'));
        $this->assertSame(1, AnalyticsEvent::count(), 'sans refus, l\'action est bien enregistrée');
    }

    /* ---------- Sources de trafic ---------- */

    public function test_sources_are_classified(): void
    {
        $tracker = app(Tracker::class);

        $this->assertSame('direct', $tracker->source(null, null, null, null));
        $this->assertSame('search', $tracker->source('google.fr', null, null, null));
        $this->assertSame('social', $tracker->source('l.facebook.com', null, null, null));
        $this->assertSame('ai', $tracker->source('chatgpt.com', null, null, null));
        $this->assertSame('email', $tracker->source(null, 'email', 'newsletter', null));
        $this->assertSame('campaign', $tracker->source(null, 'cpc', 'google', 'lancement'));
        $this->assertSame('referral', $tracker->source('blog-ami.example', null, null, null));
    }

    /* ---------- Calculs ---------- */

    private function seedTraffic(): void
    {
        $day = now(Tracker::timezone());

        foreach ([['a', 'search', 10, 1, 1], ['b', 'direct', 10, 1, 0], ['c', 'social', 21, 6, 1]] as $i => [$key, $source, $hour, $dow, $contact]) {
            $session = AnalyticsSession::create([
                'session_key' => str_pad('sess'.$key, 20, 'x'), 'visitor_key' => str_pad('vis'.$key, 20, 'x'), 'audience' => 'visitor',
                'started_at' => now(), 'last_seen_at' => now(), 'date' => $day->toDateString(), 'hour' => $hour, 'dow' => $dow,
                'pageviews' => 2, 'duration_seconds' => 60, 'is_bounce' => $i === 1, 'entry_path' => '/', 'exit_path' => '/ressources', 'source' => $source, 'device' => 'mobile',
            ]);
            AnalyticsEvent::create(['session_id' => $session->id, 'visitor_key' => $session->visitor_key, 'audience' => 'visitor', 'type' => 'pageview', 'path' => '/', 'date' => $day->toDateString(), 'hour' => $hour, 'dow' => $dow, 'created_at' => now()]);
            if ($contact) {
                AnalyticsEvent::create(['session_id' => $session->id, 'visitor_key' => $session->visitor_key, 'audience' => 'visitor', 'type' => 'contact', 'name' => 'whatsapp', 'path' => '/', 'date' => $day->toDateString(), 'hour' => $hour, 'dow' => $dow, 'created_at' => now()]);
            }
        }
    }

    public function test_the_overview_counts_visits_visitors_bounce_and_conversions(): void
    {
        $this->seedTraffic();

        $overview = Stats::lastDays(7)->overview();

        $this->assertSame(3, $overview['sessions']);
        $this->assertSame(3, $overview['visitors']);
        $this->assertSame(6, $overview['pageviews']);
        $this->assertSame(2.0, $overview['pages_per_session']);
        $this->assertSame(60, $overview['avg_duration']);
        $this->assertSame(33.3, $overview['bounce_rate']);
        $this->assertSame(2, $overview['conversions']);
        $this->assertSame(66.7, $overview['conversion_rate']);
    }

    public function test_the_heatmap_finds_the_busiest_hour_and_day(): void
    {
        $this->seedTraffic();

        $heat = Stats::lastDays(7)->heatmap();

        $this->assertSame(2, $heat['matrix'][1][10]);
        $this->assertSame(10, $heat['peak_hour']);
        $this->assertSame('Mardi', $heat['peak_day']);
        $this->assertSame(3, $heat['total']);
    }

    public function test_the_breakdown_by_source_includes_how_many_end_in_a_contact(): void
    {
        $this->seedTraffic();

        $rows = collect(Stats::lastDays(7)->breakdown('source'))->keyBy('key');

        $this->assertSame(100.0, $rows['search']['conversion_rate']);
        $this->assertSame(0.0, $rows['direct']['conversion_rate']);
        $this->assertSame('Moteurs de recherche', $rows['search']['label']);
    }

    public function test_the_daily_series_has_no_gaps(): void
    {
        $this->seedTraffic();

        $series = Stats::lastDays(7)->daily();

        $this->assertCount(7, $series);
        $this->assertSame(3, end($series)['sessions']);
        $this->assertSame(0, $series[0]['sessions']);
    }

    public function test_the_previous_period_has_the_same_length_and_feeds_the_deltas(): void
    {
        $stats = Stats::lastDays(7);
        $previous = $stats->previous();

        $this->assertSame(7, $previous->days());
        $this->assertSame($stats->from->subDay()->toDateString(), $previous->to->toDateString());
        $this->assertNull(collect($stats->kpis())->firstWhere('key', 'sessions')['delta'], 'rien à comparer : pas de pourcentage inventé');
    }

    public function test_the_funnel_counts_each_step_once_per_visit(): void
    {
        $this->seedTraffic();
        $first = AnalyticsSession::where('session_key', 'like', 'sessa%')->firstOrFail();
        foreach ([['view', 'tarifs', null, false], ['click', 'Créer mon compte', '/register', true]] as [$type, $name, $target, $cta]) {
            AnalyticsEvent::create(['session_id' => $first->id, 'visitor_key' => $first->visitor_key, 'audience' => 'visitor', 'type' => $type, 'name' => $name, 'target' => $target, 'cta' => $cta, 'path' => '/', 'date' => now(Tracker::timezone())->toDateString(), 'hour' => 10, 'dow' => 1, 'created_at' => now()]);
        }
        AnalyticsEvent::create(['session_id' => $first->id, 'visitor_key' => $first->visitor_key, 'audience' => 'visitor', 'type' => 'action', 'name' => 'registered', 'date' => now(Tracker::timezone())->toDateString(), 'hour' => 10, 'dow' => 1, 'created_at' => now()]);

        $funnel = collect(Stats::lastDays(7)->funnel());

        $this->assertSame(3, $funnel[0]['count']);
        $this->assertSame(1, $funnel[2]['count'], 'a regardé les offres');
        $this->assertSame(1, $funnel[3]['count'], 'a cliqué sur un bouton d\'action');
        $this->assertSame(1, $funnel[5]['count'], 'a créé un compte');
        $this->assertSame(33.3, $funnel[5]['percent']);
    }

    public function test_core_web_vitals_use_the_75th_percentile_and_rate_it(): void
    {
        $this->seedTraffic();
        $session = AnalyticsSession::firstOrFail();
        foreach ([1000, 1200, 1500, 5000] as $lcp) {
            AnalyticsEvent::create(['session_id' => $session->id, 'visitor_key' => $session->visitor_key, 'audience' => 'visitor', 'type' => 'vital', 'name' => 'LCP', 'value' => $lcp, 'date' => now(Tracker::timezone())->toDateString(), 'hour' => 10, 'dow' => 1, 'created_at' => now()]);
        }

        $lcp = collect(Stats::lastDays(7)->vitals())->firstWhere('key', 'LCP');

        $this->assertSame(1500.0, $lcp['p75']);
        $this->assertSame('good', $lcp['rating']);
        $this->assertSame(4, $lcp['samples']);
    }

    public function test_insights_stay_quiet_with_too_few_visits_then_speak(): void
    {
        $quiet = (new Insights)->for(Stats::lastDays(7));
        $this->assertCount(1, $quiet);
        $this->assertStringContainsString('Pas encore assez', $quiet[0]['title']);

        $this->seedTraffic();
        // Dix visites de plus pour dépasser le seuil de dix.
        for ($i = 0; $i < 10; $i++) {
            AnalyticsSession::create(['session_key' => str_pad('more'.$i, 20, 'x'), 'visitor_key' => str_pad('mv'.$i, 20, 'x'), 'audience' => 'visitor', 'started_at' => now(), 'last_seen_at' => now(), 'date' => now(Tracker::timezone())->toDateString(), 'hour' => 10, 'dow' => 1, 'source' => 'direct', 'device' => 'mobile', 'pageviews' => 1]);
        }

        $titles = collect((new Insights)->for(Stats::lastDays(7)))->pluck('title')->implode(' | ');
        $this->assertStringNotContainsString('Pas encore assez', $titles);
    }

    public function test_health_score_rewards_recent_regular_and_broad_use(): void
    {
        $this->assertSame('good', ClientStats::health(1, 8, 4, true)['tone']);
        $this->assertSame('bad', ClientStats::health(null, 0, 0, false)['tone']);
        $this->assertSame(0, ClientStats::health(60, 0, 0, false)['score']);
        $this->assertSame(100, ClientStats::health(0, 6, 4, true)['score']);
    }

    public function test_clients_at_risk_are_those_silent_for_more_than_two_weeks(): void
    {
        [$active, $user] = $this->tenant('Active');
        [$silent] = $this->tenant('Silencieuse');
        \DB::table('workspaces')->whereIn('id', [$active->id, $silent->id])->update(['created_at' => now()->subDays(40)]);

        AnalyticsSession::create(['session_key' => str_pad('recent', 20, 'x'), 'visitor_key' => str_pad('r', 20, 'x'), 'user_id' => $user->id, 'workspace_id' => $active->id, 'audience' => 'client', 'started_at' => now(), 'last_seen_at' => now(), 'date' => now(Tracker::timezone())->toDateString(), 'hour' => 9, 'dow' => 1, 'pageviews' => 3]);

        $risk = ClientStats::today()->atRisk();

        $this->assertSame(['Silencieuse'], collect($risk)->pluck('name')->all());
    }

    public function test_the_activation_funnel_follows_what_really_exists_in_each_workspace(): void
    {
        [$workspace] = $this->tenant('Nouvelle');

        $steps = collect(ClientStats::today(7)->activation());

        $this->assertSame(1, $steps[0]['count']);
        $this->assertSame(1, $steps[1]['count'], 'un assistant existe');
        $this->assertSame(0, $steps[2]['count'], 'aucune connaissance prête');
        $this->assertSame(1, $steps[5]['count'], 'offre payante : le compte de test est en offre pro');
    }

    /* ---------- Pages d'administration ---------- */

    public function test_the_statistics_page_is_for_super_admins_only(): void
    {
        [, $client] = $this->tenant();

        $this->get(route('admin.statistics.index'))->assertRedirect();
        $this->actingAs($client)->get(route('admin.statistics.index'))->assertForbidden();
        $this->actingAs($this->staff())->get(route('admin.statistics.index'))->assertForbidden();
    }

    public function test_every_tab_of_the_statistics_page_renders_with_and_without_data(): void
    {
        $admin = $this->admin();

        foreach ([false, true] as $withData) {
            if ($withData) {
                $this->seedTraffic();
            }

            foreach (array_keys(\App\Http\Controllers\Admin\StatisticsController::TABS) as $tab) {
                foreach (['visitor', 'client', 'all'] as $audience) {
                    $this->actingAs($admin)->get(route('admin.statistics.index', ['onglet' => $tab, 'public' => $audience, 'jours' => 30]))
                        ->assertOk()->assertSee('Statistiques');
                }
            }
        }
    }

    public function test_the_overview_shows_figures_and_the_live_endpoint_answers(): void
    {
        $this->seedTraffic();
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('admin.statistics.index'))->assertOk()->assertSee('Visiteurs uniques')->assertSee('En ce moment');
        $this->actingAs($admin)->getJson(route('admin.statistics.live'))->assertOk()->assertJsonStructure(['active', 'pages', 'recent']);
    }

    public function test_the_csv_export_has_a_bom_semicolons_and_neutralises_formulas(): void
    {
        $this->seedTraffic();
        $session = AnalyticsSession::firstOrFail();
        AnalyticsEvent::create(['session_id' => $session->id, 'visitor_key' => $session->visitor_key, 'audience' => 'visitor', 'type' => 'click', 'name' => '=HYPERLINK("http://evil")', 'path' => '/', 'date' => now(Tracker::timezone())->toDateString(), 'hour' => 10, 'dow' => 1, 'created_at' => now()]);

        $response = $this->actingAs($this->admin())->get(route('admin.statistics.export', ['table' => 'clics']));
        $response->assertOk();
        $csv = $response->streamedContent();

        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertStringContainsString('Élément;Page;Destination;Clics;Visiteurs', $csv);
        $this->assertStringContainsString("'=HYPERLINK", $csv);
        $this->actingAs($this->admin())->get(route('admin.statistics.export', ['table' => 'inconnue']))->assertNotFound();
    }

    /* ---------- Isolation ---------- */

    public function test_a_client_can_never_read_the_platform_statistics(): void
    {
        $this->seedTraffic();
        [, $client] = $this->tenant();

        $this->actingAs($client);
        foreach (['admin.statistics.index', 'admin.statistics.live'] as $route) {
            $this->get(route($route))->assertForbidden();
        }
        $this->get(route('admin.statistics.export', ['table' => 'pages']))->assertForbidden();
    }

    public function test_the_collector_does_not_depend_on_a_workspace_scope(): void
    {
        [, $client] = $this->tenant('A');
        [, $other] = $this->tenant('B');

        $this->actingAs($client)->beacon([$this->pageview('/dashboard')]);
        $this->actingAs($other)->beacon([$this->pageview('/dashboard')], ['v' => 'Zz'.self::VISITOR, 's' => 'Zz'.self::SESSION]);

        $this->assertSame(2, AnalyticsSession::count());
        $this->assertCount(2, AnalyticsSession::pluck('workspace_id')->unique());
    }

    /* ---------- Entretien ---------- */

    public function test_old_data_is_pruned_after_the_retention_period(): void
    {
        $this->seedTraffic();
        $old = now()->subDays(500);
        $session = AnalyticsSession::create(['session_key' => str_pad('old', 20, 'x'), 'visitor_key' => str_pad('o', 20, 'x'), 'audience' => 'visitor', 'started_at' => $old, 'last_seen_at' => $old, 'date' => $old->toDateString(), 'hour' => 1, 'dow' => 1]);
        AnalyticsEvent::create(['session_id' => $session->id, 'visitor_key' => $session->visitor_key, 'audience' => 'visitor', 'type' => 'pageview', 'path' => '/', 'date' => $old->toDateString(), 'hour' => 1, 'dow' => 1, 'created_at' => $old]);

        $this->artisan('analytics:prune')->assertExitCode(0);

        $this->assertSame(3, AnalyticsSession::count());
        $this->assertSame(0, AnalyticsEvent::where('date', $old->toDateString())->count());
    }
}
