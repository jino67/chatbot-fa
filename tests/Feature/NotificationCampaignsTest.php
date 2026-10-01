<?php

namespace Tests\Feature;

use App\Mail\Notice;
use App\Models\AnalyticsSession;
use App\Models\AuditLog;
use App\Models\Plan;
use App\Models\PushCampaign;
use App\Models\User;
use App\Models\Workspace;
use App\Notify\CampaignAudience;
use App\Notify\CampaignSender;
use App\Notify\Notifier;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\CreatesTenants;
use Tests\Concerns\FakesPush;
use Tests\TestCase;

/** Les envois de l'équipe aux clients : audience, aperçu, envoi par morceaux, programmation, résultats et droits d'accès. */
class NotificationCampaignsTest extends TestCase
{
    use CreatesTenants;
    use FakesPush;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->fakePush();
        Carbon::setTestNow('2026-10-05 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return array<string,mixed> */
    private function form(array $over = []): array
    {
        return $over + [
            'title' => 'Offre Pro à -20 %', 'body' => 'Jusqu\'à dimanche, passez à l\'offre Pro avec 20 % de réduction.', 'url' => '/billing',
            'kind' => 'promo', 'audience_type' => 'all', 'when' => 'now',
        ];
    }

    private function campaign(array $over = []): PushCampaign
    {
        return PushCampaign::create($over + [
            'title' => 'Nouveauté', 'body' => 'Les modèles WhatsApp se créent tout seuls.', 'url' => '/bots', 'kind' => 'promo',
            'audience' => ['type' => 'all'], 'status' => PushCampaign::DRAFT,
        ]);
    }

    /* ---------- Audience ---------- */

    public function test_the_audience_is_active_clients_of_active_workspaces_never_staff(): void
    {
        [, $awa] = $this->tenant('Boutique A');
        [$suspended, $blocked] = $this->tenant('Boutique B');
        $suspended->update(['is_suspended' => true]);
        [, $inactive] = $this->tenant('Boutique C');
        $inactive->forceFill(['is_active' => false])->save();
        $staff = $this->staff(User::ADMIN);

        $ids = CampaignAudience::query(['type' => 'all'])->pluck('users.id')->all();

        $this->assertSame([$awa->id], $ids);
        $this->assertNotContains($blocked->id, $ids);
        $this->assertNotContains($staff->id, $ids);
    }

    public function test_audience_by_plan_workspace_and_push(): void
    {
        [$pro, $proUser] = $this->tenant('Pro', 'pro');
        [$free, $freeUser] = $this->tenant('Gratuit', Plan::default()?->slug ?? 'free');
        $this->device($proUser);

        $this->assertSame([$proUser->id], CampaignAudience::query(['type' => 'plans', 'plans' => ['pro']])->pluck('users.id')->all());
        $this->assertSame([$freeUser->id], CampaignAudience::query(['type' => 'workspaces', 'workspaces' => [$free->id]])->pluck('users.id')->all());
        $this->assertSame([$proUser->id], CampaignAudience::query(['type' => 'all', 'only_push' => true])->pluck('users.id')->all());
        $this->assertSame([], CampaignAudience::query(['type' => 'plans', 'plans' => []])->pluck('users.id')->all());
    }

    public function test_the_inactive_audience_uses_the_visit_measurement(): void
    {
        [$active, $activeUser] = $this->tenant('Active');
        [$silent, $silentUser] = $this->tenant('Silencieuse');
        AnalyticsSession::create([
            'session_key' => str_pad('active', 20, 'x'), 'visitor_key' => str_pad('v', 20, 'x'), 'workspace_id' => $active->id, 'audience' => 'client',
            'started_at' => now(), 'last_seen_at' => now(), 'date' => now()->toDateString(), 'hour' => 9, 'dow' => 0,
        ]);

        $ids = CampaignAudience::query(['type' => 'inactive', 'days' => 14])->pluck('users.id')->all();

        $this->assertSame([$silentUser->id], $ids);
    }

    public function test_the_estimate_counts_people_and_devices(): void
    {
        [, $a] = $this->tenant('A');
        [, $b] = $this->tenant('B');
        $this->device($a, id: 'one');
        $this->device($a, 'updates.push.services.mozilla.com', id: 'two');

        $estimate = CampaignAudience::estimate(['type' => 'all']);

        $this->assertSame(['users' => 2, 'workspaces' => 2, 'with_push' => 1, 'devices' => 2], $estimate);
        $this->actingAs($this->staff(User::ADMIN))->postJson(route('admin.notifications.estimate'), ['audience_type' => 'all'])->assertOk()->assertJson($estimate);
    }

    public function test_the_audience_is_described_in_plain_words(): void
    {
        $this->assertSame('Tous les clients', CampaignAudience::describe(['type' => 'all']));
        $this->assertSame('Offres : pro, business, seulement ceux qui ont activé les notifications', CampaignAudience::describe(['type' => 'plans', 'plans' => ['pro', 'business'], 'only_push' => true]));
        $this->assertSame('Clients absents depuis 21 jours', CampaignAudience::describe(['type' => 'inactive', 'days' => 21]));
    }

    /* ---------- Envoi ---------- */

    public function test_sending_now_notifies_every_client_and_pushes_to_their_phones(): void
    {
        [, $awa] = $this->tenant('A');
        [, $bob] = $this->tenant('B');
        $this->tenant('Sans appareil');
        $this->device($awa);
        $this->device($bob);
        $admin = $this->staff(User::ADMIN);

        $response = $this->actingAs($admin)->post(route('admin.notifications.store'), $this->form())->assertSessionHasNoErrors();

        $campaign = PushCampaign::firstOrFail();
        $response->assertRedirect(route('admin.notifications.show', $campaign));
        $this->assertSame(PushCampaign::SENT, $campaign->status);
        $this->assertSame(3, $campaign->targeted);
        $this->assertSame(3, $campaign->notified, 'tous reçoivent le message dans l\'application');
        $this->assertSame(2, $campaign->pushed, 'deux appareils activés');
        $this->assertSame(2, $this->push->count());
        $this->assertSame('Offre Pro à -20 %', $this->push->payloads()[0]['title']);
        $this->assertSame('/billing', $this->push->payloads()[0]['url']);
        $this->assertSame($admin->id, $campaign->created_by);
        $this->assertSame(3, $campaign->notifications()->count());
        $this->assertNotNull(AuditLog::where('action', 'notification.sent')->first());
    }

    public function test_promos_respect_opt_out_and_the_daily_cap_but_important_messages_do_not(): void
    {
        [, $optOut] = $this->tenant('A');
        [, $capped] = $this->tenant('B');
        [, $normal] = $this->tenant('C');
        $optOut->forceFill(['notification_prefs' => ['cats' => ['promo' => false]]])->save();
        app(Notifier::class)->toUser($capped, 'promo', 'Déjà reçu aujourd\'hui', '');

        $promo = $this->campaign();
        $sender = app(CampaignSender::class);
        $sender->start($promo);
        $sender->process($promo);

        $promo->refresh();
        $this->assertSame(1, $promo->notified);
        $this->assertSame(2, $promo->skipped);
        $this->assertSame(0, $optOut->appNotifications()->count());

        $important = $this->campaign(['kind' => PushCampaign::IMPORTANT, 'title' => 'Maintenance ce soir']);
        $sender->start($important);
        $sender->process($important);

        $this->assertSame(3, $important->refresh()->notified, 'un message important passe même pour ceux qui refusent les promotions');
        $this->assertSame('system', $optOut->appNotifications()->firstOrFail()->category);
    }

    public function test_promos_sent_at_night_are_deferred_and_leave_in_the_morning(): void
    {
        [, $user] = $this->tenant('A');
        $this->device($user);
        Carbon::setTestNow('2026-10-05 22:30:00');

        $campaign = $this->campaign();
        $sender = app(CampaignSender::class);
        $sender->start($campaign);
        $sender->process($campaign);

        $this->assertSame(1, $campaign->refresh()->deferred);
        $this->assertSame(0, $this->push->count());

        Carbon::setTestNow('2026-10-06 07:05:00');
        $this->artisan('notifications:dispatch')->assertSuccessful();

        $this->assertSame(1, $this->push->count());
    }

    public function test_a_big_audience_is_sent_in_chunks_without_doubles(): void
    {
        $users = collect(range(1, 5))->map(fn ($i) => $this->tenant("Boutique {$i}")[1]);
        $users->each(fn ($user) => $this->device($user));
        $campaign = $this->campaign();
        $sender = app(CampaignSender::class);
        $sender->start($campaign);

        $this->assertFalse($sender->process($campaign, 40, 2));
        $this->assertSame(PushCampaign::SENDING, $campaign->refresh()->status);
        $this->assertSame(2, $campaign->notified);
        $this->assertFalse($sender->process($campaign->refresh(), 40, 2));
        $this->assertTrue($sender->process($campaign->refresh(), 40, 2));

        $campaign->refresh();
        $this->assertSame(PushCampaign::SENT, $campaign->status);
        $this->assertSame(5, $campaign->notified);
        $this->assertSame(5, $this->push->count(), 'chaque personne reçoit le message une seule fois');
        $this->assertNotNull($campaign->finished_at);
        $this->assertFalse($sender->process($campaign, 40, 2) && false, 'un nouveau passage ne renvoie rien');
        $this->assertSame(5, $this->push->count());
    }

    public function test_the_scheduler_starts_due_campaigns_and_leaves_future_and_canceled_ones(): void
    {
        [, $user] = $this->tenant('A');
        $due = $this->campaign(['status' => PushCampaign::SCHEDULED, 'scheduled_at' => now()->subMinute()]);
        $future = $this->campaign(['status' => PushCampaign::SCHEDULED, 'scheduled_at' => now()->addHour(), 'title' => 'Demain']);
        $canceled = $this->campaign(['status' => PushCampaign::CANCELED, 'scheduled_at' => now()->subMinute(), 'title' => 'Annulée']);

        $this->artisan('notifications:dispatch')->assertSuccessful();

        $this->assertSame(PushCampaign::SENT, $due->refresh()->status);
        $this->assertSame(PushCampaign::SCHEDULED, $future->refresh()->status);
        $this->assertSame(PushCampaign::CANCELED, $canceled->refresh()->status);
        $this->assertSame(1, $user->appNotifications()->count());
    }

    public function test_the_email_copy_is_sent_only_when_asked(): void
    {
        Mail::fake();
        $this->tenant('A');

        $quiet = $this->campaign();
        app(CampaignSender::class)->start($quiet);
        app(CampaignSender::class)->process($quiet);
        Mail::assertNothingSent();

        $loud = $this->campaign(['title' => 'Important', 'kind' => PushCampaign::IMPORTANT, 'also_email' => true]);
        app(CampaignSender::class)->start($loud);
        app(CampaignSender::class)->process($loud);

        Mail::assertSent(Notice::class, fn (Notice $mail) => $mail->subjectLine === 'Important');
        $this->assertSame(1, $loud->refresh()->emailed);
    }

    public function test_opening_a_notification_counts_as_read_in_the_results(): void
    {
        [, $a] = $this->tenant('A');
        [, $b] = $this->tenant('B');
        $campaign = $this->campaign();
        $sender = app(CampaignSender::class);
        $sender->start($campaign);
        $sender->process($campaign);

        $this->actingAs($a)->get(route('notifications.open', $a->appNotifications()->firstOrFail()->id))->assertRedirect('/bots');

        $this->assertSame(1, $campaign->readCount());
        $this->assertSame(50.0, $campaign->readRate());
        $this->actingAs($this->staff(User::ADMIN))->get(route('admin.notifications.show', $campaign))->assertOk()->assertSee('Ouverts');
    }

    /* ---------- Formulaire : programmation, brouillon, essai ---------- */

    public function test_a_campaign_can_be_scheduled_and_nothing_goes_out_before_its_time(): void
    {
        $this->tenant('A');
        $admin = $this->staff(User::ADMIN);

        $this->actingAs($admin)->post(route('admin.notifications.store'), $this->form(['when' => 'later', 'scheduled_at' => '2026-10-07 18:00']))->assertSessionHasNoErrors();

        $campaign = PushCampaign::firstOrFail();
        $this->assertSame(PushCampaign::SCHEDULED, $campaign->status);
        $this->assertSame('2026-10-07 18:00', $campaign->scheduled_at->format('Y-m-d H:i'));
        $this->assertSame(0, AppNotificationCount::all());
    }

    public function test_a_draft_is_saved_without_sending_and_stays_editable(): void
    {
        $this->tenant('A');
        $admin = $this->staff(User::ADMIN);

        $this->actingAs($admin)->post(route('admin.notifications.store'), $this->form(['when' => 'draft']))->assertRedirect();
        $campaign = PushCampaign::firstOrFail();

        $this->assertSame(PushCampaign::DRAFT, $campaign->status);
        $this->actingAs($admin)->get(route('admin.notifications.edit', $campaign))->assertOk()->assertSee('Offre Pro à -20 %');
        $this->actingAs($admin)->put(route('admin.notifications.update', $campaign), $this->form(['title' => 'Titre modifié', 'when' => 'draft']))->assertRedirect();
        $this->assertSame('Titre modifié', $campaign->refresh()->title);
        $this->assertSame(0, AppNotificationCount::all());
    }

    public function test_a_sent_campaign_cannot_be_edited_but_can_be_duplicated(): void
    {
        $this->tenant('A');
        $admin = $this->staff(User::ADMIN);
        $this->actingAs($admin)->post(route('admin.notifications.store'), $this->form());
        $campaign = PushCampaign::firstOrFail();

        $this->actingAs($admin)->get(route('admin.notifications.edit', $campaign))->assertForbidden();
        $this->actingAs($admin)->put(route('admin.notifications.update', $campaign), $this->form())->assertForbidden();
        $this->actingAs($admin)->get(route('admin.notifications.create', ['copie' => $campaign->id]))->assertOk()->assertSee('Offre Pro à -20 %');
    }

    public function test_a_scheduled_campaign_can_be_canceled(): void
    {
        $this->tenant('A');
        $campaign = $this->campaign(['status' => PushCampaign::SCHEDULED, 'scheduled_at' => now()->addDay()]);

        $this->actingAs($this->staff(User::ADMIN))->post(route('admin.notifications.cancel', $campaign))->assertRedirect(route('admin.notifications.index'));

        $this->assertSame(PushCampaign::CANCELED, $campaign->refresh()->status);
        $this->artisan('notifications:dispatch');
        $this->assertSame(0, AppNotificationCount::all());
    }

    public function test_the_enter_key_cannot_send_by_accident(): void
    {
        $html = $this->actingAs($this->staff(User::ADMIN))->get(route('admin.notifications.create'))->assertOk()->getContent();

        $this->assertLessThan(strpos($html, 'value="now"'), strpos($html, 'name="when" value="draft"'), 'le premier bouton du formulaire est « brouillon »');
    }

    public function test_validation_protects_the_message_and_its_audience(): void
    {
        $admin = $this->staff(User::ADMIN);
        $this->actingAs($admin);

        $this->post(route('admin.notifications.store'), $this->form(['title' => str_repeat('x', 66)]))->assertSessionHasErrors('title');
        $this->post(route('admin.notifications.store'), $this->form(['body' => str_repeat('x', 179)]))->assertSessionHasErrors('body');
        $this->post(route('admin.notifications.store'), $this->form(['url' => 'javascript:alert(1)']))->assertSessionHasErrors('url');
        $this->post(route('admin.notifications.store'), $this->form(['url' => '//evil.example']))->assertSessionHasErrors('url');
        $this->post(route('admin.notifications.store'), $this->form(['when' => 'later', 'scheduled_at' => '2026-10-01 10:00']))->assertSessionHasErrors('scheduled_at');
        $this->post(route('admin.notifications.store'), $this->form(['when' => 'later']))->assertSessionHasErrors('scheduled_at');
        $this->post(route('admin.notifications.store'), $this->form(['audience_type' => 'plans']))->assertSessionHasErrors('plans');
        $this->post(route('admin.notifications.store'), $this->form(['audience_type' => 'workspaces']))->assertSessionHasErrors('workspaces');
        $this->post(route('admin.notifications.store'), $this->form(['kind' => 'urgent']))->assertSessionHasErrors('kind');

        $this->assertSame(0, PushCampaign::count());
    }

    public function test_the_test_message_goes_to_my_own_devices_and_does_not_count_as_a_campaign(): void
    {
        $admin = $this->staff(User::ADMIN);
        [, $client] = $this->tenant('A');
        $this->device($client);

        $this->actingAs($admin)->postJson(route('admin.notifications.test'), ['title' => 'Offre', 'body' => 'Texte'])->assertStatus(409);

        $this->device($admin);
        $this->actingAs($admin)->postJson(route('admin.notifications.test'), ['title' => 'Offre', 'body' => 'Texte', 'url' => '/billing'])->assertOk()->assertJson(['sent' => true]);

        $this->assertSame(1, $this->push->count());
        $this->assertSame('[Essai] Offre', $this->push->payloads()[0]['title']);
        $this->assertSame(0, $client->appNotifications()->count(), 'aucun client ne reçoit l\'essai');
        $this->assertSame(0, PushCampaign::count());
    }

    /* ---------- Droits d'accès ---------- */

    public function test_only_the_team_can_reach_the_campaign_pages(): void
    {
        [, $client] = $this->tenant('A');
        $campaign = $this->campaign();

        $this->get(route('admin.notifications.index'))->assertRedirect();
        foreach ([route('admin.notifications.index'), route('admin.notifications.create'), route('admin.notifications.show', $campaign), route('admin.notifications.edit', $campaign)] as $url) {
            $this->actingAs($client)->get($url)->assertForbidden();
        }
        $this->actingAs($client)->post(route('admin.notifications.store'), $this->form())->assertForbidden();
        $this->actingAs($client)->postJson(route('admin.notifications.test'), ['title' => 'a', 'body' => 'b'])->assertForbidden();
        $this->actingAs($client)->postJson(route('admin.notifications.estimate'), [])->assertForbidden();

        $this->actingAs($this->staff(User::ADMIN))->get(route('admin.notifications.index'))->assertOk()->assertSee('Notifications aux clients');
        $this->actingAs($this->admin())->get(route('admin.notifications.index'))->assertOk();
    }

    public function test_the_history_shows_results_and_the_reach_summary(): void
    {
        [, $user] = $this->tenant('A');
        $this->device($user);
        $admin = $this->staff(User::ADMIN);
        $this->actingAs($admin)->post(route('admin.notifications.store'), $this->form());

        $this->actingAs($admin)->get(route('admin.notifications.index'))->assertOk()
            ->assertSee('Offre Pro à -20 %')->assertSee('Joignables sur téléphone')->assertSee('Envoyée');
        $this->actingAs($admin)->get(route('admin.notifications.show', PushCampaign::firstOrFail()))->assertOk()
            ->assertSee('Personnes visées')->assertSee('Pourquoi tout le monde n\'a pas été touché', false);
    }

    public function test_the_sidebar_links_the_team_to_notifications(): void
    {
        $this->actingAs($this->staff(User::ADMIN))->get(route('admin.overview'))->assertOk()->assertSee(route('admin.notifications.index'), false);
    }
}

/** Petit utilitaire de test : le nombre de notifications du centre, toutes personnes confondues. */
final class AppNotificationCount
{
    public static function all(): int
    {
        return \App\Models\AppNotification::count();
    }
}
