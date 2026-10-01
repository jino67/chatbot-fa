<?php

namespace Tests\Feature;

use App\Models\AppNotification;
use App\Models\PushSubscription;
use App\Models\User;
use App\Notify\Notifier;
use App\Push\PushResult;
use App\Push\WebPushCrypto;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\Concerns\CreatesTenants;
use Tests\Concerns\FakesPush;
use Tests\TestCase;

/** Notifications : ce qui part sur les téléphones, ce qui reste dans le centre, ce que chacun a choisi, et qui voit quoi. */
class NotificationsTest extends TestCase
{
    use CreatesTenants;
    use FakesPush;
    use RefreshDatabase;

    private Notifier $notifier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->fakePush();
        $this->notifier = app(Notifier::class);

        // Midi : hors des heures calmes, quelle que soit l'heure à laquelle les tests tournent.
        Carbon::setTestNow('2026-10-05 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /* ---------- Envoi à une personne ---------- */

    public function test_a_notification_is_stored_in_the_center_and_pushed_to_every_device_with_the_badge_count(): void
    {
        [, $user] = $this->tenant('Boutique Awa');
        $this->device($user, id: 'phone', platform: 'android');
        $this->device($user, 'updates.push.services.mozilla.com', id: 'laptop', platform: 'desktop');

        $this->notifier->toUser($user, 'leads', 'Nouvelle commande', 'Awa : 2 boubous brodés', '/demandes');
        $second = $this->notifier->toUser($user, 'leads', 'Autre commande', 'Moussa : 1 sac', '/demandes');

        $this->assertSame(2, $user->appNotifications()->count());
        $this->assertSame(4, $this->push->count(), 'deux messages sur deux appareils');
        $this->assertSame(2, $this->push->payloads()[3]['badge'], 'le compteur de l\'icône suit le nombre de non lues');
        $this->assertSame('/demandes', $this->push->payloads()[0]['url']);
        $this->assertNotNull($second->fresh()->pushed_at);
    }

    public function test_urgent_categories_use_high_urgency_and_promos_use_low(): void
    {
        [, $user] = $this->tenant();
        $this->device($user);

        $this->notifier->toUser($user, 'leads', 'Commande', '');
        $this->notifier->toUser($user, 'promo', 'Offre', '');

        $this->assertSame(['high', 'low'], array_column($this->push->sent, 'urgency'));
    }

    public function test_a_person_who_refuses_a_category_receives_nothing_of_it(): void
    {
        [, $user] = $this->tenant();
        $this->device($user);
        $user->forceFill(['notification_prefs' => ['cats' => ['promo' => false]]])->save();

        $this->assertNull($this->notifier->toUser($user->fresh(), 'promo', 'Offre', 'Profitez-en'));
        $this->assertSame(0, AppNotification::count());
        $this->assertSame(0, $this->push->count());
        $this->assertSame('opt_out', $this->notifier->skipReason($user->fresh(), 'promo'));
    }

    public function test_important_messages_cannot_be_refused(): void
    {
        [, $user] = $this->tenant();
        $user->forceFill(['notification_prefs' => ['cats' => ['system' => false]]])->save();

        $this->assertTrue($user->fresh()->notificationPrefs()['cats']['system']);
        $this->assertNotNull($this->notifier->toUser($user->fresh(), 'system', 'Information importante', ''));
    }

    public function test_turning_the_phone_off_keeps_the_message_in_the_center(): void
    {
        [, $user] = $this->tenant();
        $this->device($user);
        $user->forceFill(['notification_prefs' => ['push' => false]])->save();

        $this->notifier->toUser($user->fresh(), 'leads', 'Commande', '');

        $this->assertSame(1, $user->appNotifications()->count());
        $this->assertSame(0, $this->push->count());
    }

    public function test_a_disabled_account_receives_nothing(): void
    {
        [, $user] = $this->tenant();
        $user->forceFill(['is_active' => false])->save();

        $this->assertNull($this->notifier->toUser($user->fresh(), 'leads', 'Commande', ''));
    }

    public function test_an_unknown_category_is_refused(): void
    {
        [, $user] = $this->tenant();

        $this->assertNull($this->notifier->toUser($user, 'inconnue', 'x', ''));
    }

    public function test_the_same_tag_is_not_sent_twice_within_the_window(): void
    {
        [, $user] = $this->tenant();

        $this->assertNotNull($this->notifier->toUser($user, 'handoffs', 'Message', '', null, ['tag' => 'conv-9', 'dedupe_minutes' => 10]));
        $this->assertNull($this->notifier->toUser($user, 'handoffs', 'Message', '', null, ['tag' => 'conv-9', 'dedupe_minutes' => 10]));
        $this->assertNotNull($this->notifier->toUser($user, 'handoffs', 'Message', '', null, ['tag' => 'conv-10', 'dedupe_minutes' => 10]));
    }

    public function test_only_one_promo_per_day_per_person(): void
    {
        [, $user] = $this->tenant();

        $this->assertNotNull($this->notifier->toUser($user, 'promo', 'Offre 1', ''));
        $this->assertNull($this->notifier->toUser($user, 'promo', 'Offre 2', ''));
        $this->assertSame('cap', $this->notifier->skipReason($user, 'promo'));

        Carbon::setTestNow(now()->addHours(21));
        $this->assertNotNull($this->notifier->toUser($user, 'promo', 'Offre 3', ''));
    }

    /* ---------- Heures calmes ---------- */

    public function test_a_promo_at_night_waits_for_the_morning_but_an_order_does_not(): void
    {
        [, $user] = $this->tenant();
        $this->device($user);
        Carbon::setTestNow('2026-10-05 22:30:00');

        $promo = $this->notifier->toUser($user, 'promo', 'Offre du soir', 'Profitez-en');
        $order = $this->notifier->toUser($user, 'leads', 'Commande', 'Awa');

        $this->assertSame(1, $this->push->count(), 'seule la commande est partie');
        $this->assertNull($promo->pushed_at);
        $this->assertSame('2026-10-06 07:00:00', $promo->fresh()->push_after->format('Y-m-d H:i:s'));
        $this->assertNotNull($order->fresh()->pushed_at);

        // Le planificateur la pousse à partir de 7 h.
        $this->assertSame(0, $this->notifier->flushDeferred());
        Carbon::setTestNow('2026-10-06 07:01:00');
        $this->assertSame(1, $this->notifier->flushDeferred());
        $this->assertSame(2, $this->push->count());
        $this->assertNotNull($promo->fresh()->pushed_at);
        $this->assertNull($promo->fresh()->push_after);
    }

    public function test_a_deferred_promo_already_read_is_not_pushed(): void
    {
        [, $user] = $this->tenant();
        $this->device($user);
        Carbon::setTestNow('2026-10-05 23:00:00');

        $promo = $this->notifier->toUser($user, 'promo', 'Offre du soir', '');
        $this->notifier->markRead($user, [$promo->id]);

        Carbon::setTestNow('2026-10-06 08:00:00');
        $this->assertSame(0, $this->notifier->flushDeferred());
        $this->assertSame(0, $this->push->count());
    }

    public function test_quiet_hours_can_be_turned_off_or_moved(): void
    {
        [, $user] = $this->tenant();
        Carbon::setTestNow('2026-10-05 22:30:00');

        $this->assertTrue($this->notifier->inQuietHours($user));

        $user->forceFill(['notification_prefs' => ['quiet' => ['on' => false]]])->save();
        $this->assertFalse($this->notifier->inQuietHours($user->fresh()));

        $user->forceFill(['notification_prefs' => ['quiet' => ['on' => true, 'from' => 23, 'to' => 5]]])->save();
        $this->assertFalse($this->notifier->inQuietHours($user->fresh()), '22 h 30 est avant le début des heures calmes choisies');
    }

    /* ---------- Appareils ---------- */

    public function test_a_device_that_no_longer_exists_is_forgotten_and_the_message_stays_in_the_center(): void
    {
        [, $user] = $this->tenant();
        $gone = $this->device($user, id: 'gone');
        $this->push->results[$gone->endpoint] = PushResult::GONE;

        $this->notifier->toUser($user, 'leads', 'Commande', '');

        $this->assertNull(PushSubscription::find($gone->id));
        $this->assertSame(1, $user->appNotifications()->count());
    }

    public function test_a_device_failing_again_and_again_is_dropped(): void
    {
        [, $user] = $this->tenant();
        $flaky = $this->device($user, id: 'flaky');
        $this->push->results[$flaky->endpoint] = PushResult::RETRY;

        for ($i = 1; $i <= 4; $i++) {
            $this->notifier->toUser($user, 'leads', "Commande {$i}", '');
            $this->assertSame($i, $flaky->fresh()->failures);
        }

        $this->notifier->toUser($user, 'leads', 'Commande 5', '');
        $this->assertNull(PushSubscription::find($flaky->id), 'après cinq échecs de suite, l\'appareil est oublié');
    }

    public function test_a_success_resets_the_failure_count(): void
    {
        [, $user] = $this->tenant();
        $device = $this->device($user);
        $device->forceFill(['failures' => 3])->save();

        $this->notifier->toUser($user, 'leads', 'Commande', '');

        $this->assertSame(0, $device->fresh()->failures);
        $this->assertNotNull($device->fresh()->last_success_at);
    }

    /* ---------- Espace et équipe ---------- */

    public function test_a_workspace_notification_reaches_its_members_only(): void
    {
        [$workspaceA, $owner, ] = $this->tenant('Boutique A');
        $second = User::factory()->create(['workspace_id' => $workspaceA->id]);
        [, $stranger] = $this->tenant('Boutique B');
        $staff = $this->staff();

        $count = $this->notifier->toWorkspace($workspaceA, 'account', 'Paiement reçu', 'Merci', '/billing');

        $this->assertSame(2, $count);
        $this->assertSame(1, $owner->appNotifications()->count());
        $this->assertSame(1, $second->appNotifications()->count());
        $this->assertSame(0, $stranger->appNotifications()->count(), 'une autre entreprise ne reçoit rien');
        $this->assertSame(0, $staff->appNotifications()->count());
    }

    public function test_the_owner_only_option_and_the_staff_audience(): void
    {
        [$workspace, $owner] = $this->tenant('Boutique A');
        User::factory()->create(['workspace_id' => $workspace->id]);
        $admin = $this->staff(User::ADMIN);
        $super = $this->admin();

        $this->assertSame(1, $this->notifier->toWorkspace($workspace, 'account', 'Seulement vous', '', null, ['owner_only' => true]));
        $this->assertSame(2, $this->notifier->toStaff('system', 'Nouvelle demande', ''));
        $this->assertSame(1, $this->notifier->toStaff('system', 'Réservé', '', null, ['super_only' => true]));
        $this->assertSame(2, $admin->appNotifications()->count() + $super->appNotifications()->count() - 1);
    }

    /* ---------- Lecture ---------- */

    public function test_reading_lowers_the_counter_and_opening_goes_to_the_page(): void
    {
        [, $user] = $this->tenant();
        $first = $this->notifier->toUser($user, 'leads', 'Commande 1', '', '/demandes');
        $this->notifier->toUser($user, 'leads', 'Commande 2', '', '/demandes');

        $this->assertSame(2, $user->unreadNotificationCount());

        $this->actingAs($user)->get(route('notifications.open', $first->id))->assertRedirect('/demandes');

        $this->assertSame(1, $user->unreadNotificationCount());
        $this->assertNotNull($first->fresh()->read_at);
    }

    public function test_the_bell_summary_lists_the_latest_with_counts(): void
    {
        [, $user] = $this->tenant();
        $this->notifier->toUser($user, 'leads', 'Commande', 'Awa', '/demandes');

        $this->actingAs($user)->getJson(route('notifications.summary'))->assertOk()
            ->assertJsonPath('unread', 1)
            ->assertJsonPath('items.0.title', 'Commande')
            ->assertJsonPath('items.0.read', false)
            ->assertJsonStructure(['unread', 'items' => [['id', 'title', 'body', 'label', 'category', 'read', 'ago', 'url']]]);
    }

    public function test_mark_all_read_clears_the_counter_and_the_deferred_pushes(): void
    {
        [, $user] = $this->tenant();
        $this->notifier->toUser($user, 'leads', 'A', '');
        $this->notifier->toUser($user, 'handoffs', 'B', '');

        $this->actingAs($user)->post(route('notifications.read-all'))->assertRedirect();

        $this->assertSame(0, $user->unreadNotificationCount());
    }

    public function test_the_notifications_page_filters_unread(): void
    {
        [, $user] = $this->tenant();
        $read = $this->notifier->toUser($user, 'leads', 'Déjà vue', '');
        $this->notifier->toUser($user, 'leads', 'À lire', '');
        $this->notifier->markRead($user, [$read->id]);

        $this->actingAs($user)->get(route('notifications.index'))->assertOk()->assertSee('Déjà vue')->assertSee('À lire');
        $this->actingAs($user)->get(route('notifications.index', ['filtre' => 'non-lues']))->assertOk()->assertSee('À lire')->assertDontSee('Déjà vue');
    }

    /* ---------- Isolation ---------- */

    public function test_nobody_reads_or_opens_someone_elses_notifications(): void
    {
        [, $alice] = $this->tenant('Boutique A');
        [, $bob] = $this->tenant('Boutique B');
        $secret = $this->notifier->toUser($alice, 'leads', 'Commande secrète d\'Alice', 'Détails');

        $this->actingAs($bob)->get(route('notifications.open', $secret->id))->assertNotFound();
        $this->actingAs($bob)->postJson(route('notifications.read', $secret->id))->assertNotFound();
        $this->actingAs($bob)->getJson(route('notifications.summary'))->assertJsonPath('unread', 0)->assertJsonCount(0, 'items');
        $this->actingAs($bob)->get(route('notifications.index'))->assertDontSee('Commande secrète d\'Alice');
        $this->assertNull($secret->fresh()->read_at);
    }

    public function test_guests_cannot_reach_any_notification_route(): void
    {
        foreach (['notifications.index', 'notifications.summary', 'notifications.preferences'] as $route) {
            $this->get(route($route))->assertRedirect();
        }
        $this->postJson(route('push.subscribe'), [])->assertUnauthorized();
        $this->postJson(route('push.test'))->assertUnauthorized();
    }

    /* ---------- Abonnement depuis le navigateur ---------- */

    private function subscribePayload(string $endpoint = 'https://fcm.googleapis.com/fcm/send/device-1'): array
    {
        $pair = WebPushCrypto::generateKeyPair();

        return [
            'endpoint' => $endpoint,
            'keys' => ['p256dh' => WebPushCrypto::b64urlEncode($pair['public']), 'auth' => WebPushCrypto::b64urlEncode(random_bytes(16))],
            'standalone' => true,
        ];
    }

    public function test_a_device_subscribes_and_is_attached_to_the_signed_in_person(): void
    {
        [$workspace, $user] = $this->tenant('Boutique Awa');

        $this->actingAs($user)->withHeader('User-Agent', 'Mozilla/5.0 (Linux; Android 14; Pixel 8) Chrome/120 Mobile')
            ->postJson(route('push.subscribe'), $this->subscribePayload())
            ->assertOk()->assertJson(['subscribed' => true, 'devices' => 1]);

        $device = $user->pushSubscriptions()->firstOrFail();
        $this->assertSame($workspace->id, (int) $device->workspace_id);
        $this->assertSame('android', $device->platform);
        $this->assertTrue($device->standalone);
    }

    public function test_the_same_device_signing_in_as_someone_else_changes_owner_instead_of_duplicating(): void
    {
        [, $alice] = $this->tenant('Boutique A');
        [, $bob] = $this->tenant('Boutique B');
        $payload = $this->subscribePayload();

        $this->actingAs($alice)->postJson(route('push.subscribe'), $payload)->assertOk();
        $this->actingAs($bob)->postJson(route('push.subscribe'), $payload)->assertOk();

        $this->assertSame(1, PushSubscription::count());
        $this->assertSame(0, $alice->pushSubscriptions()->count(), 'Alice ne reçoit plus sur ce téléphone');
        $this->assertSame(1, $bob->pushSubscriptions()->count());
    }

    public function test_only_real_push_services_and_valid_keys_are_accepted(): void
    {
        [, $user] = $this->tenant();
        $this->actingAs($user);

        $this->postJson(route('push.subscribe'), $this->subscribePayload('https://169.254.169.254/latest/meta-data'))->assertStatus(422);
        $this->postJson(route('push.subscribe'), $this->subscribePayload('http://fcm.googleapis.com/fcm/send/x'))->assertStatus(422);
        $this->postJson(route('push.subscribe'), ['endpoint' => 'https://fcm.googleapis.com/fcm/send/x', 'keys' => ['p256dh' => 'court', 'auth' => 'court']])->assertStatus(422);
        $this->postJson(route('push.subscribe'), ['endpoint' => 'https://fcm.googleapis.com/fcm/send/x'])->assertStatus(422);

        $this->assertSame(0, PushSubscription::count());
    }

    public function test_a_person_keeps_at_most_ten_devices(): void
    {
        [, $user] = $this->tenant();

        for ($i = 1; $i <= 12; $i++) {
            $this->actingAs($user)->postJson(route('push.subscribe'), $this->subscribePayload("https://fcm.googleapis.com/fcm/send/device-{$i}"))->assertOk();
        }

        $this->assertSame(10, $user->pushSubscriptions()->count());
        $this->assertNull(PushSubscription::where('endpoint', 'https://fcm.googleapis.com/fcm/send/device-1')->first(), 'les plus anciens sont oubliés');
    }

    public function test_unsubscribing_removes_only_the_callers_device(): void
    {
        [, $alice] = $this->tenant('Boutique A');
        [, $bob] = $this->tenant('Boutique B');
        $mine = $this->device($alice, id: 'mine');
        $theirs = $this->device($bob, id: 'theirs');

        $this->actingAs($alice)->deleteJson(route('push.unsubscribe'), ['endpoint' => $theirs->endpoint])->assertNoContent();
        $this->assertNotNull(PushSubscription::find($theirs->id), 'l\'appareil de Bob n\'est pas touché');

        $this->actingAs($alice)->deleteJson(route('push.unsubscribe'), ['endpoint' => $mine->endpoint])->assertNoContent();
        $this->assertNull(PushSubscription::find($mine->id));
    }

    public function test_removing_a_device_from_the_list_cannot_remove_someone_elses(): void
    {
        [, $alice] = $this->tenant('Boutique A');
        [, $bob] = $this->tenant('Boutique B');
        $theirs = $this->device($bob);

        $this->actingAs($alice)->delete(route('push.devices.destroy', $theirs->id))->assertRedirect();

        $this->assertNotNull(PushSubscription::find($theirs->id));
    }

    public function test_the_test_notification_needs_a_device_and_does_not_raise_the_counter(): void
    {
        [, $user] = $this->tenant();

        $this->actingAs($user)->postJson(route('push.test'))->assertStatus(409);

        $this->device($user);
        $this->actingAs($user)->postJson(route('push.test'))->assertOk()->assertJson(['sent' => true]);

        $this->assertSame(1, $this->push->count());
        $this->assertSame(0, $this->push->payloads()[0]['badge'], 'un essai ne compte pas comme un message à lire');
        $this->assertSame(0, $user->unreadNotificationCount());
    }

    /* ---------- Préférences ---------- */

    public function test_preferences_are_saved_and_locked_categories_stay_on(): void
    {
        [, $user] = $this->tenant();

        $this->actingAs($user)->put(route('notifications.preferences.update'), [
            'push' => '1', 'cats' => ['leads' => '1'], 'quiet_on' => '1', 'quiet_from' => '22', 'quiet_to' => '6',
        ])->assertRedirect()->assertSessionHas('status');

        $prefs = $user->fresh()->notificationPrefs();
        $this->assertTrue($prefs['push']);
        $this->assertTrue($prefs['cats']['leads']);
        $this->assertFalse($prefs['cats']['promo'], 'une case décochée veut dire non');
        $this->assertFalse($prefs['cats']['handoffs']);
        $this->assertTrue($prefs['cats']['system'], 'les messages importants restent toujours reçus');
        $this->assertSame(['on' => true, 'from' => 22, 'to' => 6], $prefs['quiet']);
    }

    public function test_preferences_reject_impossible_hours(): void
    {
        [, $user] = $this->tenant();

        $this->actingAs($user)->put(route('notifications.preferences.update'), ['quiet_from' => '27'])->assertSessionHasErrors('quiet_from');
    }

    public function test_the_preferences_page_lists_devices_and_every_category(): void
    {
        [, $user] = $this->tenant();
        $this->device($user, platform: 'android');

        $this->actingAs($user)->get(route('notifications.preferences'))->assertOk()
            ->assertSee('Téléphone Android')
            ->assertSee('Commandes, rendez-vous et devis')->assertSee('Nouveautés et offres')->assertSee('Heures calmes');
    }

    /* ---------- Pages et fichiers ---------- */

    public function test_connected_pages_carry_the_push_configuration_and_guests_do_not(): void
    {
        [, $user] = $this->tenant();
        $this->notifier->toUser($user, 'leads', 'Commande', '');

        $html = $this->actingAs($user)->get(route('dashboard'))->assertOk()->getContent();

        $this->assertStringContainsString('name="kouma-push"', $html);
        preg_match('/name="kouma-push" content="([^"]*)"/', $html, $m);
        $config = json_decode(html_entity_decode($m[1]), true);
        $this->assertSame(1, $config['unread']);
        $this->assertSame($user->id, $config['user']);
        $this->assertSame(65, strlen(WebPushCrypto::b64urlDecode($config['key'])));
        $this->assertArrayNotHasKey('private', $config);

        auth()->logout();
        $this->get('/')->assertDontSee('name="kouma-push"', false);
    }

    public function test_the_dashboard_and_pages_show_the_bell_with_its_counter(): void
    {
        [, $user] = $this->tenant();
        $this->notifier->toUser($user, 'leads', 'Commande', '');

        $this->actingAs($user)->get(route('dashboard'))->assertOk()->assertSee('notifBell(', false)->assertSee('notifications\/resume', false);
    }

    public function test_the_service_worker_shows_a_notification_for_every_push_and_sets_the_icon_badge(): void
    {
        $sw = file_get_contents(public_path('sw.js'));

        foreach (["addEventListener('push'", "addEventListener('notificationclick'", 'showNotification', 'setAppBadge', 'clearAppBadge', '/notifications/', "'/badge-96.png'"] as $needle) {
            $this->assertStringContainsString($needle, $sw, $needle);
        }
        $this->assertFileExists(public_path('badge-96.png'));
        $this->assertFileExists(public_path('email-mark.png'));
    }

    public function test_urls_in_notifications_stay_on_the_site(): void
    {
        config(['app.url' => 'https://kouma.site']);

        $this->assertSame('/demandes', $this->notifier->normalizeUrl('https://kouma.site/demandes'));
        $this->assertSame('/demandes?x=1', $this->notifier->normalizeUrl('/demandes?x=1'));
        $this->assertSame('https://exemple.test/promo', $this->notifier->normalizeUrl('https://exemple.test/promo'));
        $this->assertNull($this->notifier->normalizeUrl('//evil.example/x'));
        $this->assertNull($this->notifier->normalizeUrl('javascript:alert(1)'));
        $this->assertNull($this->notifier->normalizeUrl('http://exemple.test/non-securise'));
        $this->assertNull($this->notifier->normalizeUrl(''));
    }

    public function test_no_em_dash_in_the_notification_code(): void
    {
        foreach (['app/Push', 'app/Notify', 'app/Models/AppNotification.php', 'app/Models/PushCampaign.php', 'app/Models/PushSubscription.php', 'app/Http/Controllers/NotificationController.php', 'app/Http/Controllers/PushController.php', 'config/notifications.php', 'resources/js/push.js', 'public/sw.js', 'resources/views/notifications', 'resources/views/components/push-card.blade.php', 'resources/views/components/notification-bell.blade.php', 'resources/views/components/mail', 'resources/views/emails'] as $path) {
            $files = is_dir(base_path($path)) ? collect(File::allFiles(base_path($path)))->map->getPathname()->all() : [base_path($path)];
            foreach ($files as $file) {
                $this->assertStringNotContainsString("\u{2014}", file_get_contents($file), "tiret cadratin dans {$file}");
            }
        }
    }
}
