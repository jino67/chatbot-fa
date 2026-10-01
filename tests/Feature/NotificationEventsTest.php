<?php

namespace Tests\Feature;

use App\Chat\ChatService;
use App\Leads\LeadNotifier;
use App\Leads\LeadService;
use App\Mail\Notice;
use App\Models\Bot;
use App\Models\Conversation;
use App\Models\Lead;
use App\Models\Payment;
use App\Models\User;
use App\Models\Workspace;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\CreatesTenants;
use Tests\Concerns\FakesPush;
use Tests\TestCase;

/** Les notifications automatiques : demandes de clients, abonnement, paiement, WhatsApp, bienvenue, alertes de l'équipe. */
class NotificationEventsTest extends TestCase
{
    use CreatesTenants;
    use FakesPush;
    use RefreshDatabase;

    private Workspace $workspace;

    private User $owner;

    private Bot $bot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->fakePush();
        Carbon::setTestNow('2026-10-05 12:00:00');
        [$this->workspace, $this->owner, $this->bot] = $this->tenant('Boutique Awa', 'pro');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function conversation(): Conversation
    {
        return Conversation::withoutGlobalScopes()->create([
            'workspace_id' => $this->workspace->id, 'bot_id' => $this->bot->id, 'channel' => 'web', 'external_id' => 'visiteur-1',
            'contact_name' => 'Fatou', 'contact_phone' => '+22670123456',
        ]);
    }

    /* ---------- Demandes des clients ---------- */

    public function test_an_order_reaches_the_phone_of_the_team_with_the_details(): void
    {
        Mail::fake();
        $this->device($this->owner);

        $lead = app(LeadService::class)->capture($this->conversation(), Lead::ORDER, '2 boubous brodés, livraison demain');

        $this->assertSame(1, $this->push->count());
        $payload = $this->push->payloads()[0];
        $this->assertSame('Commande à confirmer', $payload['title']);
        $this->assertStringContainsString('Fatou', $payload['body']);
        $this->assertStringContainsString('2 boubous brodés', $payload['body']);
        $this->assertSame('/demandes', $payload['url']);
        $this->assertSame('lead-'.$lead->id, $payload['tag']);
        $this->assertSame('high', $this->push->sent[0]['urgency'], 'une commande réveille le téléphone');
        $this->assertSame(1, $payload['badge']);
        $this->assertSame('sent', $lead->fresh()->alert_log[0]['push']);
    }

    public function test_a_request_for_a_person_is_a_handoff_notification(): void
    {
        Mail::fake();
        $this->device($this->owner);

        app(LeadService::class)->capture($this->conversation(), Lead::HUMAN, '« Je veux parler à quelqu\'un »');

        $this->assertSame('Demande une personne', $this->push->payloads()[0]['title']);
        $this->assertSame('handoffs', $this->owner->appNotifications()->firstOrFail()->category);
    }

    public function test_the_phone_channel_can_be_turned_off_in_the_alerts_page(): void
    {
        Mail::fake();
        $this->device($this->owner);

        $this->actingAs($this->owner)->put(route('alerts.update'), ['email' => '1', 'reminder_minutes' => 30, 'kinds' => array_keys(Lead::KINDS)])->assertSessionHasNoErrors();
        $this->assertFalse($this->workspace->fresh()->alertSettings()['push']);

        app(LeadService::class)->capture($this->conversation(), Lead::ORDER, '1 foulard');

        $this->assertSame(0, $this->push->count());
        $this->assertSame(0, $this->owner->appNotifications()->count());
    }

    public function test_the_alerts_page_offers_the_phone_option(): void
    {
        $this->actingAs($this->owner)->get(route('alerts.edit'))->assertOk()->assertSee('Notification sur le téléphone')->assertSee('name="push"', false);
        $this->assertTrue($this->workspace->alertSettings()['push'], 'activé par défaut : il ne fait rien tant qu\'aucun appareil n\'est abonné');
    }

    public function test_a_kind_the_owner_does_not_want_sends_nothing(): void
    {
        Mail::fake();
        $this->device($this->owner);
        $this->actingAs($this->owner)->put(route('alerts.update'), ['email' => '1', 'push' => '1', 'reminder_minutes' => 30, 'kinds' => [Lead::HUMAN]])->assertSessionHasNoErrors();

        app(LeadService::class)->capture($this->conversation(), Lead::ORDER, '1 foulard');

        $this->assertSame(0, $this->push->count());
    }

    public function test_a_reminder_replaces_the_previous_notification_of_the_same_request(): void
    {
        Mail::fake();
        $this->device($this->owner);
        $lead = app(LeadService::class)->capture($this->conversation(), Lead::ORDER, '1 foulard');

        Carbon::setTestNow('2026-10-05 12:40:00');
        app(LeadNotifier::class)->notify($lead->fresh(), reminder: true);

        $payloads = $this->push->payloads();
        $this->assertSame('Rappel : Commande à confirmer', $payloads[1]['title']);
        $this->assertSame($payloads[0]['tag'], $payloads[1]['tag'], 'même tag : le téléphone remplace la notification au lieu de les empiler');
    }

    public function test_another_companys_phone_never_receives_my_customers_orders(): void
    {
        Mail::fake();
        [, $stranger] = $this->tenant('Autre boutique');
        $this->device($stranger);
        $this->device($this->owner);

        app(LeadService::class)->capture($this->conversation(), Lead::ORDER, '1 foulard');

        $this->assertSame(0, $stranger->appNotifications()->count());
        $this->assertSame(1, $this->push->count());
    }

    /* ---------- Un client écrit pendant qu'une personne a la main ---------- */

    public function test_a_customer_writing_during_human_takeover_alerts_the_team_once_every_five_minutes(): void
    {
        $this->device($this->owner);
        $conversation = $this->conversation();
        $conversation->forceFill(['status' => Conversation::HUMAN])->save();
        $chat = app(ChatService::class);

        $this->assertNull($chat->handleUserMessage($conversation, 'Bonjour, vous êtes là ?'));
        $this->assertNull($chat->handleUserMessage($conversation->fresh(), 'J\'attends votre réponse'));

        $this->assertSame(1, $this->owner->appNotifications()->count(), 'deux messages de suite, une seule alerte');
        $payload = $this->push->payloads()[0];
        $this->assertSame('Nouveau message de Fatou', $payload['title']);
        $this->assertSame('Bonjour, vous êtes là ?', $payload['body']);
        $this->assertSame("/bots/{$this->bot->id}/conversations/{$conversation->id}", $payload['url']);

        Carbon::setTestNow('2026-10-05 12:06:00');
        $chat->handleUserMessage($conversation->fresh(), 'Toujours là');
        $this->assertSame(2, $this->owner->appNotifications()->count());
    }

    public function test_a_conversation_handled_by_the_bot_sends_no_new_message_alert(): void
    {
        $this->device($this->owner);

        app(ChatService::class)->handleUserMessage($this->conversation(), 'Bonjour');

        $this->assertSame(0, $this->owner->appNotifications()->where('title', 'like', 'Nouveau message%')->count());
    }

    /* ---------- Abonnement ---------- */

    public function test_the_renewal_reminder_arrives_as_notification_and_branded_email(): void
    {
        Mail::fake();
        $this->device($this->owner);
        $this->workspace->update(['plan_ends_at' => now()->addDays(2), 'subscription_status' => Workspace::ACTIVE]);

        $this->artisan('platform:subscriptions')->assertSuccessful();

        $notification = $this->owner->appNotifications()->firstOrFail();
        $this->assertSame('account', $notification->category);
        $this->assertStringContainsString('Votre abonnement se termine dans', $notification->title);
        $this->assertSame('/billing', $notification->url);
        $this->assertSame(1, $this->push->count());

        Mail::assertSent(Notice::class, fn (Notice $mail) => $mail->hasTo($this->owner->email) && str_contains($mail->subjectLine, 'se termine dans') && $mail->actionLabel === 'Renouveler mon abonnement');
    }

    public function test_the_renewal_reminder_is_sent_once_per_due_date(): void
    {
        Mail::fake();
        $this->workspace->update(['plan_ends_at' => now()->addDays(2), 'subscription_status' => Workspace::ACTIVE]);

        $this->artisan('platform:subscriptions');
        $this->artisan('platform:subscriptions');

        Mail::assertSentCount(1);
        $this->assertSame(1, $this->owner->appNotifications()->count());
    }

    public function test_a_payment_sends_a_receipt_with_amount_reference_and_period(): void
    {
        Mail::fake();
        $this->device($this->owner);
        $admin = $this->staff(User::ADMIN);

        $this->actingAs($admin)->post(route('admin.payments.store', $this->workspace), [
            'plan' => 'pro', 'amount' => 40000, 'currency' => 'XOF', 'method' => array_key_first(Payment::METHODS), 'reference' => 'OM-2026-0412', 'period_months' => 1,
        ])->assertSessionHasNoErrors();

        $this->assertSame('Paiement reçu, merci !', $this->owner->appNotifications()->firstOrFail()->title);
        Mail::assertSent(Notice::class, function (Notice $mail) {
            $html = $mail->render();

            return $mail->hasTo($this->owner->email) && str_contains($html, 'OM-2026-0412') && str_contains($html, '40') && $mail->tone === 'success' && str_contains($mail->subjectLine, 'Reçu de paiement');
        });
        $this->assertSame(0, $admin->appNotifications()->count(), 'l\'administrateur n\'est pas notifié du paiement qu\'il enregistre');
    }

    public function test_whatsapp_activation_tells_the_owner_their_assistant_is_live(): void
    {
        Mail::fake();
        $this->device($this->owner);

        app(\App\Notify\Events::class)->whatsappActivated($this->workspace, $this->bot);

        $notification = $this->owner->appNotifications()->firstOrFail();
        $this->assertStringContainsString('WhatsApp est activé', $notification->title);
        $this->assertSame("/bots/{$this->bot->id}/channels", $notification->url);
        $this->assertTrue($this->push->payloads()[0]['badge'] >= 1);
        Mail::assertSent(Notice::class, fn (Notice $mail) => $mail->hasTo($this->owner->email) && $mail->tone === 'success');
    }

    public function test_the_whatsapp_limit_alerts_reach_the_owner_only_by_email_but_everyone_in_the_app(): void
    {
        Mail::fake();
        $second = User::factory()->create(['workspace_id' => $this->workspace->id]);

        app(\App\Notify\Events::class)->whatsappNearLimit($this->workspace, 905, 1000);

        $this->assertSame(1, $this->owner->appNotifications()->count());
        $this->assertSame(1, $second->appNotifications()->count());
        Mail::assertSentCount(1);
        Mail::assertSent(Notice::class, fn (Notice $mail) => $mail->hasTo($this->owner->email) && ! $mail->hasTo($second->email));
    }

    /* ---------- Bienvenue ---------- */

    public function test_registering_sends_a_welcome_in_the_center_and_by_email(): void
    {
        Mail::fake();

        $this->post('/register', [
            'name' => 'Awa Ouedraogo', 'email' => 'awa@example.com', 'company' => 'Boutique Awa Deux',
            'password' => 'un-mot-de-passe-solide-2026', 'password_confirmation' => 'un-mot-de-passe-solide-2026',
        ])->assertRedirect();

        $user = User::where('email', 'awa@example.com')->firstOrFail();
        $this->assertStringContainsString('Bienvenue', $user->appNotifications()->firstOrFail()->title);
        Mail::assertSent(Notice::class, fn (Notice $mail) => $mail->hasTo('awa@example.com') && str_contains($mail->subjectLine, 'Bienvenue') && $mail->greeting() === 'Bonjour Awa,');
    }

    public function test_a_failing_mail_server_never_blocks_registration(): void
    {
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('Connection could not be established'));

        $this->post('/register', [
            'name' => 'Awa Ouedraogo', 'email' => 'awa2@example.com', 'company' => 'Boutique Trois',
            'password' => 'un-mot-de-passe-solide-2026', 'password_confirmation' => 'un-mot-de-passe-solide-2026',
        ])->assertRedirect(route('dashboard'));

        $this->assertNotNull(User::where('email', 'awa2@example.com')->first());
    }

    /* ---------- Équipe ---------- */

    public function test_a_plan_request_alerts_the_team_on_phone_and_by_email(): void
    {
        Mail::fake();
        config(['platform.admin_email' => 'direction@kouma.example']);
        $admin = $this->staff(User::ADMIN);
        $this->device($admin);

        $this->actingAs($this->owner)->post(route('billing.request'), ['plan' => 'business', 'message' => 'Nous voulons WhatsApp'])->assertSessionHasNoErrors();

        $this->assertSame(1, $admin->appNotifications()->count());
        $this->assertStringContainsString('Demande d\'offre : Boutique Awa', $admin->appNotifications()->firstOrFail()->title);
        $this->assertSame(1, $this->push->count());
        Mail::assertSent(Notice::class, fn (Notice $mail) => $mail->hasTo('direction@kouma.example') && str_contains($mail->subjectLine, 'Demande d\'offre'));
    }

    public function test_a_low_twilio_balance_reaches_the_team_urgently(): void
    {
        Mail::fake();
        config(['platform.admin_email' => 'direction@kouma.example']);
        $super = $this->admin();
        $this->device($super);

        app(\App\Notify\Events::class)->walletLow(12.5, 'USD', 20.0);

        $this->assertSame('high', $this->push->sent[0]['urgency']);
        Mail::assertSent(Notice::class, fn (Notice $mail) => $mail->tone === 'danger' && str_contains($mail->render(), '12,50'));
    }

    public function test_staff_notifications_do_not_reach_clients(): void
    {
        Mail::fake();
        $this->device($this->owner);

        app(\App\Notify\Events::class)->walletLow(12.5, 'USD', 20.0);

        $this->assertSame(0, $this->owner->appNotifications()->count());
    }
}
