<?php

namespace Tests\Feature;

use App\Models\Bot;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\PlanRequest;
use App\Models\User;
use App\Models\Workspace;
use App\Services\PlatformSettings;
use App\Services\UsageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/** Offres en base, quotas, paiements enregistres a la main (Mobile Money), cycle de vie des abonnements. */
class BillingTest extends TestCase
{
    use CreatesTenants;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function pay(Workspace $workspace, array $overrides = []): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->staff())->post(route('admin.payments.store', $workspace), $overrides + [
            'plan' => 'pro', 'amount' => 30000, 'method' => 'orange_money', 'reference' => 'OM-123456', 'period_months' => 1,
        ]);
    }

    public function test_the_default_plans_exist_with_a_free_default_one(): void
    {
        $this->assertSame(['free', 'essentiel', 'bonplan', 'pro', 'business', 'api'], Plan::orderBy('sort')->pluck('slug')->all());
        $this->assertSame('free', Plan::default()->slug);
        $this->assertTrue(Plan::bySlug('free')->isFree());
        $this->assertFalse(Plan::bySlug('free')->feature('whatsapp'));
        $this->assertTrue(Plan::bySlug('pro')->feature('templates'));
        $this->assertTrue(Plan::bySlug('pro')->feature('remove_branding'));
        $this->assertFalse(Plan::bySlug('essentiel')->feature('templates'));
        $this->assertSame('40 000 FCFA', str_replace("\u{202F}", ' ', Plan::bySlug('pro')->formattedPrice()));

        // L'offre a 10 000 FCFA n'inclut pas WhatsApp (trop couteux) ; l'offre gratuite est un essai limite dans le temps.
        $this->assertFalse(Plan::bySlug('essentiel')->feature('whatsapp'));
        $this->assertTrue(Plan::bySlug('pro')->feature('whatsapp'));
        $this->assertSame(14, Plan::default()->trial_days);
        $this->assertNull(Plan::bySlug('pro')->trial_days);
    }

    public function test_plan_limits_come_from_the_database_and_fall_back_when_a_plan_is_missing(): void
    {
        $workspace = Workspace::create(['name' => 'Client', 'plan' => 'pro']);
        $this->assertSame(3, $workspace->limit('bots'));

        Plan::bySlug('pro')->update(['limits' => ['bots' => 7, 'sources' => 60, 'pages_per_crawl' => 200, 'messages_per_month' => 5000, 'members' => 5]]);
        $this->assertSame(7, $workspace->fresh()->limit('bots'), 'un changement de quota vaut tout de suite');

        // Offre supprimee alors qu'un espace la reference : jamais d'erreur, l'offre par defaut prend le relais.
        $orphan = Workspace::create(['name' => 'Orphelin', 'plan' => 'disparue']);
        $this->assertSame('free', $orphan->planModel()->slug);
        $this->assertSame(1, $orphan->limit('bots'));
    }

    public function test_bot_and_user_limits_follow_the_plan(): void
    {
        $usage = app(UsageService::class);
        $workspace = Workspace::create(['name' => 'Petit', 'plan' => 'free']);

        $this->assertTrue($usage->canAddBot($workspace));
        Bot::withoutGlobalScopes()->create(['workspace_id' => $workspace->id, 'name' => 'Unique']);
        $this->assertFalse($usage->canAddBot($workspace), 'l\'offre gratuite n\'inclut qu\'un assistant');

        $workspace->update(['plan' => 'pro']);
        $this->assertTrue($usage->canAddBot($workspace->fresh()));
    }

    public function test_a_client_over_the_bot_limit_is_sent_to_the_plans_page(): void
    {
        [, $client] = $this->tenant('Petit', 'free');
        Bot::create(['name' => 'Deuxième', 'workspace_id' => $client->workspace_id]);

        $this->actingAs($client)->get(route('bots.create'))->assertRedirect(route('billing.show'))->assertSessionHas('error');
    }

    public function test_the_billing_page_shows_plans_usage_and_payment_instructions(): void
    {
        [, $client] = $this->tenant('Boutique', 'essentiel');
        app(PlatformSettings::class)->set('billing.instructions', 'Orange Money : 70 00 00 00, au nom de Kouma SARL');

        $this->actingAs($client)->get(route('billing.show'))
            ->assertOk()
            ->assertSee('Essentiel')->assertSee('Pro')->assertSee('Business')
            ->assertSee('Offre actuelle')
            ->assertSee('Orange Money : 70 00 00 00, au nom de Kouma SARL');
    }

    public function test_a_hidden_plan_is_not_offered_to_clients(): void
    {
        [, $client] = $this->tenant();
        Plan::create(['slug' => 'sur-mesure', 'name' => 'Offre Sur Mesure', 'prices' => ['XOF' => 500000], 'period_months' => 1, 'limits' => Plan::FALLBACK_LIMITS, 'features' => [], 'is_public' => false, 'sort' => 9]);

        $this->actingAs($client)->get(route('billing.show'))->assertDontSee('Offre Sur Mesure');
        $this->actingAs($client)->post(route('billing.request'), ['plan' => 'sur-mesure'])->assertSessionHasErrors('plan');
    }

    public function test_a_client_requests_a_plan_and_the_team_is_notified(): void
    {
        [$workspace, $client] = $this->tenant('Boutique', 'free');
        config(['platform.admin_email' => 'equipe@kouma.test']);

        $this->actingAs($client)->post(route('billing.request'), ['plan' => 'pro', 'message' => 'Nous voulons WhatsApp'])->assertSessionHas('status');

        $request = PlanRequest::withoutGlobalScopes()->firstOrFail();
        $this->assertSame('pro', $request->plan);
        $this->assertSame(PlanRequest::REQUESTED, $request->status);
        $this->assertSame($workspace->id, $request->workspace_id);

        $subjects = collect(app('mailer')->getSymfonyTransport()->messages())->map(fn ($m) => $m->getOriginalMessage()->getSubject());
        $this->assertTrue($subjects->contains(fn ($s) => str_contains($s, "Demande d'offre")));

        // Une seconde demande met a jour la premiere au lieu de s'accumuler.
        $this->actingAs($client)->post(route('billing.request'), ['plan' => 'business'])->assertSessionHas('status');
        $this->assertSame(1, PlanRequest::withoutGlobalScopes()->count());
        $this->assertSame('business', PlanRequest::withoutGlobalScopes()->first()->plan);

        $this->actingAs($client)->post(route('billing.request'), ['plan' => 'free'])->assertSessionHas('error', fn ($m) => str_contains($m, 'déjà'));
    }

    public function test_recording_a_payment_activates_the_plan_and_closes_the_request(): void
    {
        [$workspace, $client] = $this->tenant('Boutique', 'free');
        $this->actingAs($client)->post(route('billing.request'), ['plan' => 'pro']);
        $staff = $this->staff();

        $this->actingAs($staff)->post(route('admin.payments.store', $workspace), [
            'plan' => 'pro', 'amount' => 30000, 'method' => 'orange_money', 'reference' => 'OM-998877', 'period_months' => 1,
        ])->assertSessionHas('status');

        $workspace->refresh();
        $this->assertSame('pro', $workspace->plan);
        $this->assertSame('active', $workspace->subscription_status);
        $this->assertTrue($workspace->plan_ends_at->isBetween(now()->addDays(29), now()->addDays(32)));

        $payment = Payment::withoutGlobalScopes()->firstOrFail();
        $this->assertSame(30000, $payment->amount);
        $this->assertSame('XOF', $payment->currency);
        $this->assertSame($staff->id, $payment->recorded_by);
        $this->assertSame(PlanRequest::APPROVED, PlanRequest::withoutGlobalScopes()->first()->status);

        // Et le client le voit dans son historique.
        $this->actingAs($client)->get(route('billing.show'))->assertSee('OM-998877')->assertSee('Orange Money');
    }

    public function test_a_renewal_extends_from_the_current_end_date_not_from_today(): void
    {
        [$workspace] = $this->tenant('Boutique', 'free');
        $this->pay($workspace);
        $firstEnd = $workspace->fresh()->plan_ends_at;

        $this->pay($workspace, ['reference' => 'OM-2']);

        $this->assertEquals($firstEnd->copy()->addMonth()->toDateString(), $workspace->fresh()->plan_ends_at->toDateString(), 'pas de jour perdu en payant en avance');
        $this->assertSame(2, Payment::withoutGlobalScopes()->count());
    }

    public function test_paying_after_expiry_starts_a_fresh_period_and_lifts_a_suspension(): void
    {
        [$workspace] = $this->tenant('Boutique', 'pro');
        $workspace->update(['plan_ends_at' => now()->subDays(10), 'subscription_status' => 'past_due', 'is_suspended' => true, 'suspended_reason' => 'Impayé']);

        $this->pay($workspace);

        $workspace->refresh();
        $this->assertTrue($workspace->plan_ends_at->isFuture());
        $this->assertSame('active', $workspace->subscription_status);
        $this->assertFalse($workspace->is_suspended);
    }

    public function test_payment_validation_and_permissions(): void
    {
        [$workspace, $client] = $this->tenant();

        $this->actingAs($client)->post(route('admin.payments.store', $workspace), ['plan' => 'pro', 'amount' => 1, 'method' => 'wave', 'period_months' => 1])->assertForbidden();

        $staff = $this->staff();
        $this->actingAs($staff)->post(route('admin.payments.store', $workspace), ['plan' => 'inconnue', 'amount' => 30000, 'method' => 'orange_money', 'period_months' => 1])->assertSessionHasErrors('plan');
        $this->actingAs($staff)->post(route('admin.payments.store', $workspace), ['plan' => 'pro', 'amount' => -5, 'method' => 'orange_money', 'period_months' => 1])->assertSessionHasErrors('amount');
        $this->actingAs($staff)->post(route('admin.payments.store', $workspace), ['plan' => 'pro', 'amount' => 30000, 'method' => 'bitcoin', 'period_months' => 1])->assertSessionHasErrors('method');
        $this->assertSame(0, Payment::withoutGlobalScopes()->count());
    }

    public function test_a_client_cannot_see_another_clients_payments(): void
    {
        [$a, $clientA] = $this->tenant('Client A');
        [$b] = $this->tenant('Client B');
        $this->pay($b, ['reference' => 'REF-DE-B']);

        $this->actingAs($clientA)->get(route('billing.show'))->assertDontSee('REF-DE-B');
    }

    /* ---------- Cycle de vie : commande platform:subscriptions ---------- */

    public function test_the_subscription_command_reminds_flags_late_and_downgrades(): void
    {
        Mail::fake();
        [$soon, $soonUser] = $this->tenant('Bientot', 'pro');
        [$late, $lateUser] = $this->tenant('En retard', 'pro');
        [$gone, $goneUser] = $this->tenant('Perdu', 'pro');
        [$fine, ] = $this->tenant('Tranquille', 'pro');
        $soon->update(['plan_ends_at' => now()->addDays(3), 'subscription_status' => 'active']);
        $late->update(['plan_ends_at' => now()->subDay(), 'subscription_status' => 'active']);
        $gone->update(['plan_ends_at' => now()->subDays(10), 'subscription_status' => 'past_due']);
        $fine->update(['plan_ends_at' => now()->addDays(20), 'subscription_status' => 'active']);

        $this->artisan('platform:subscriptions')->assertSuccessful();

        $this->assertSame('active', $soon->fresh()->subscription_status);
        $this->assertSame('past_due', $late->fresh()->subscription_status);
        $this->assertSame('pro', $late->fresh()->plan, 'la periode de grace conserve l\'offre');

        $gone = $gone->fresh();
        $this->assertSame('free', $gone->plan, 'grace depassee : retour a l\'offre gratuite');
        $this->assertTrue($gone->trialExpired(), 'l\'offre gratuite est un essai deja consomme : l\'assistant est en pause');
        $this->assertSame('canceled', $gone->subscription_status);
        $this->assertSame(1, Bot::withoutGlobalScopes()->where('workspace_id', $gone->id)->count(), 'les donnees sont conservees');

        $this->assertSame('pro', $fine->fresh()->plan);
    }

    public function test_the_reminder_is_sent_only_once_per_period(): void
    {
        [$workspace, $user] = $this->tenant('Bientot', 'pro');
        $workspace->update(['plan_ends_at' => now()->addDays(3), 'subscription_status' => 'active']);

        $this->artisan('platform:subscriptions')->assertSuccessful();
        $this->artisan('platform:subscriptions')->assertSuccessful();

        $reminders = collect(app('mailer')->getSymfonyTransport()->messages())
            ->filter(fn ($m) => str_contains($m->getOriginalMessage()->getSubject(), 'se termine'));
        $this->assertCount(1, $reminders);
    }

    public function test_an_expired_free_workspace_keeps_its_plan_and_one_without_end_date_is_never_touched(): void
    {
        $free = Workspace::create(['name' => 'Gratuit', 'plan' => 'free', 'plan_ends_at' => now()->subYear()]);
        $forever = Workspace::create(['name' => 'Offert', 'plan' => 'business', 'plan_ends_at' => null]);

        $this->artisan('platform:subscriptions')->assertSuccessful();

        $this->assertSame('free', $free->fresh()->plan);
        $this->assertSame(Workspace::EXPIRED, $free->fresh()->subscription_status);
        $this->assertSame('business', $forever->fresh()->plan);
        $this->assertSame(Workspace::ACTIVE, $forever->fresh()->subscription_status);
    }

    public function test_downgrade_removes_paid_features(): void
    {
        [$workspace] = $this->tenant('Perdu', 'pro');
        $this->assertTrue($workspace->hasFeature('whatsapp'));
        $workspace->update(['plan_ends_at' => now()->subDays(10)]);

        $this->artisan('platform:subscriptions')->assertSuccessful();

        $this->assertFalse($workspace->fresh()->hasFeature('whatsapp'));
        $this->assertFalse($workspace->fresh()->hasFeature('remove_branding'));
    }

    /* ---------- Gestion des offres par le super admin ---------- */

    public function test_a_super_admin_creates_and_edits_a_plan(): void
    {
        $super = $this->admin();

        $this->actingAs($super)->post(route('admin.plans.store'), [
            'slug' => 'ong', 'name' => 'ONG', 'tagline' => 'Tarif associatif', 'prices' => ['XOF' => 5000, 'KMF' => '', 'EUR' => 8], 'period_months' => 1, 'sort' => 5,
            'limits' => ['bots' => 2, 'sources' => 30, 'pages_per_crawl' => 100, 'messages_per_month' => 2000, 'members' => 3],
            'features' => ['whatsapp' => 1], 'is_public' => 1,
        ])->assertRedirect(route('admin.plans.index'));

        $plan = Plan::bySlug('ong');
        $this->assertSame(5000, $plan->priceIn('XOF'));
        $this->assertSame(8, $plan->priceIn('EUR'));
        $this->assertNull($plan->priceIn('KMF'), 'une devise laissee vide n\'est pas proposee');
        $this->assertNull($plan->trial_days);
        $this->assertTrue($plan->feature('whatsapp'));
        $this->assertFalse($plan->feature('templates'));
        $this->assertSame(2000, $plan->limit('messages_per_month'));

        $this->actingAs($super)->put(route('admin.plans.update', $plan), [
            'name' => 'ONG', 'prices' => ['XOF' => 4000, 'USD' => 7], 'trial_days' => 30, 'period_months' => 1, 'sort' => 5,
            'limits' => ['bots' => 2, 'sources' => 30, 'pages_per_crawl' => 100, 'messages_per_month' => 2500, 'members' => 3],
            'features' => ['whatsapp' => 1, 'templates' => 1], 'is_public' => 1,
        ])->assertRedirect(route('admin.plans.index'));

        $plan->refresh();
        $this->assertSame(4000, $plan->priceIn('XOF'));
        $this->assertSame(7, $plan->priceIn('USD'));
        $this->assertNull($plan->priceIn('EUR'), 'un prix retire du formulaire disparait');
        $this->assertSame(30, $plan->trial_days);
        $this->assertTrue($plan->feature('templates'));
        $this->assertSame('ong', $plan->slug, 'l\'identifiant ne change jamais');
    }

    public function test_plan_validation_and_default_plan_rules(): void
    {
        $super = $this->admin();
        $form = ['name' => 'X', 'prices' => ['XOF' => 100], 'period_months' => 1, 'sort' => 1, 'limits' => Plan::FALLBACK_LIMITS];

        $this->actingAs($super)->post(route('admin.plans.store'), ['slug' => 'pro'] + $form)->assertSessionHasErrors('slug');
        $this->actingAs($super)->post(route('admin.plans.store'), ['slug' => 'Mauvais Slug'] + $form)->assertSessionHasErrors('slug');
        $this->actingAs($super)->post(route('admin.plans.store'), ['slug' => 'ok', 'prices' => ['XOF' => -1]] + $form)->assertSessionHasErrors('prices.XOF');
        $this->actingAs($super)->post(route('admin.plans.store'), ['slug' => 'ok', 'prices' => ['KMF' => 500]] + $form)->assertSessionHasErrors('prices.XOF');
        $this->actingAs($super)->post(route('admin.plans.store'), ['slug' => 'ok', 'trial_days' => 0] + $form)->assertSessionHasErrors('trial_days');

        // Une seule offre par defaut : en designer une autre retire le statut a l'ancienne.
        $this->actingAs($super)->post(route('admin.plans.store'), ['slug' => 'nouvelle', 'is_default' => 1] + $form);
        $this->assertSame(1, Plan::where('is_default', true)->count());
        $this->assertSame('nouvelle', Plan::default()->slug);

        // On ne supprime ni l'offre par defaut, ni une offre encore utilisee.
        $this->actingAs($super)->delete(route('admin.plans.destroy', Plan::default()))->assertSessionHas('error');
        Workspace::create(['name' => 'Utilisateur de pro', 'plan' => 'pro']);
        $this->actingAs($super)->delete(route('admin.plans.destroy', Plan::bySlug('pro')))->assertSessionHas('error');
        $this->assertNotNull(Plan::bySlug('pro'));

        $unused = Plan::create(['slug' => 'inutilisee', 'name' => 'Inutilisée', 'prices' => ['XOF' => 1], 'period_months' => 1, 'limits' => Plan::FALLBACK_LIMITS, 'features' => [], 'sort' => 50]);
        $this->actingAs($super)->delete(route('admin.plans.destroy', $unused))->assertSessionHas('status');
    }

    public function test_the_landing_page_lists_the_plans_stored_in_the_database(): void
    {
        Plan::bySlug('pro')->update(['prices' => ['XOF' => 42000, 'KMF' => 31500, 'EUR' => 65, 'USD' => 73]]);

        $this->get('/')->assertOk()->assertSee(Plan::bySlug('pro')->formattedPrice());
    }

    public function test_staff_can_change_a_plan_without_payment_and_set_an_end_date(): void
    {
        [$workspace] = $this->tenant('Boutique', 'free');

        $this->actingAs($this->staff())->put(route('admin.workspaces.plan', $workspace), ['plan' => 'business', 'ends_at' => now()->addMonths(2)->toDateString()])->assertSessionHas('status');

        $workspace->refresh();
        $this->assertSame('business', $workspace->plan);
        $this->assertSame(now()->addMonths(2)->toDateString(), $workspace->plan_ends_at->toDateString());
        $this->assertSame(0, Payment::withoutGlobalScopes()->count(), 'geste commercial : aucun paiement enregistre');
    }

    /* ---------- Options reservees aux offres payantes ---------- */

    public function test_whatsapp_activation_is_reserved_to_plans_that_include_it(): void
    {
        [, $free, $bot] = $this->tenant('Gratuit', 'free');
        $payload = ['business_name' => 'Boutique', 'phone_number' => '+226 70 00 00 00'];

        $this->actingAs($free)->post(route('channels.whatsapp-request', $bot), $payload)->assertRedirect(route('billing.show'))->assertSessionHas('error');
        $this->assertSame(0, \App\Models\ChannelRequest::withoutGlobalScopes()->count());

        // L'offre a 10 000 FCFA n'inclut pas WhatsApp : la demande est refusee et renvoie vers les offres.
        [, $essential, $essentialBot] = $this->tenant('Essentiel', 'essentiel');
        $this->actingAs($essential)->post(route('channels.whatsapp-request', $essentialBot), $payload)->assertRedirect(route('billing.show'))->assertSessionHas('error');
        $this->assertSame(0, \App\Models\ChannelRequest::withoutGlobalScopes()->count());

        [, $paid, $paidBot] = $this->tenant('Payant', 'pro');
        $this->actingAs($paid)->post(route('channels.whatsapp-request', $paidBot), $payload)->assertSessionHas('status');
    }

    public function test_the_widget_advertises_the_platform_unless_the_plan_removes_branding(): void
    {
        [, , $free] = $this->tenant('Gratuit', 'free');
        [, , $pro] = $this->tenant('Pro', 'pro');

        $freeConfig = $this->getJson('/api/v1/widget/'.$free->public_key.'/config')->assertOk()->json();
        $proConfig = $this->getJson('/api/v1/widget/'.$pro->public_key.'/config')->assertOk()->json();

        $this->assertTrue($freeConfig['branding']);
        $this->assertSame('Kouma', $freeConfig['brand']['name']);
        $this->assertStringContainsString('utm_source=widget', $freeConfig['brand']['url']);
        $this->assertFalse($proConfig['branding']);
    }

    public function test_the_ads_follow_the_brand_name_configured_by_the_super_admin(): void
    {
        [, , $free] = $this->tenant('Gratuit', 'free');
        $this->actingAs($this->admin())->put(route('admin.settings.update'), ['brand_name' => 'Parole+', 'brand_url' => 'https://parole.example'])->assertSessionHas('status');

        $config = $this->getJson('/api/v1/widget/'.$free->public_key.'/config')->json();

        $this->assertSame('Parole+', $config['brand']['name']);
        $this->assertStringStartsWith('https://parole.example/', $config['brand']['url']);
        $this->get('/')->assertSee('Parole+');
    }
}
