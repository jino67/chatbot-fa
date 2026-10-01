<?php

namespace Tests\Feature;

use App\Chat\ChatService;
use App\Models\Conversation;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Workspace;
use App\Support\Currency;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/** Prix en FCFA, KMF, euro et dollar ; essai gratuit limité dans le temps ; offre à 10 000 FCFA sans WhatsApp. */
class PricingAndTrialTest extends TestCase
{
    use CreatesTenants;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function plain(string $text): string
    {
        return str_replace("\u{202F}", ' ', $text);
    }

    /** @return list<string> objets des e-mails envoyés (transport « array » des tests) */
    private function subjects(): array
    {
        return collect(app('mailer')->getSymfonyTransport()->messages())
            ->map(fn ($m) => $m->getOriginalMessage()->getSubject())->all();
    }

    private function conversation($bot): Conversation
    {
        return Conversation::withoutGlobalScopes()->create([
            'workspace_id' => $bot->workspace_id, 'bot_id' => $bot->id, 'channel' => 'web', 'external_id' => 'visiteur-test-1',
        ]);
    }

    private function register(array $overrides = [])
    {
        return $this->post(route('register'), $overrides + [
            'name' => 'Awa Traoré', 'company' => 'Boutique Awa', 'email' => 'awa@example.com',
            'password' => 'un-mot-de-passe-solide', 'password_confirmation' => 'un-mot-de-passe-solide',
        ]);
    }

    /* ---------- Prix par devise ---------- */

    public function test_every_paid_plan_has_a_price_in_the_four_currencies_that_follows_the_parities(): void
    {
        foreach (['essentiel', 'pro', 'business'] as $slug) {
            $plan = Plan::bySlug($slug);
            foreach (Currency::codes() as $code) {
                $this->assertNotNull($plan->priceIn($code), "{$slug} sans prix en {$code}");
            }

            $xof = $plan->priceIn('XOF');
            $eurReference = $xof / 655.957;   // parité fixe FCFA / euro

            // Le franc comorien vaut exactement 75 % du FCFA (deux parités fixes avec l'euro).
            $this->assertSame((int) round($xof * 0.75), $plan->priceIn('KMF'), "{$slug} : KMF = 75 % du prix en FCFA");
            $this->assertEqualsWithDelta($eurReference, $plan->priceIn('EUR'), $eurReference * 0.05, "{$slug} : euro à moins de 5 % de l'équivalent FCFA");
            $this->assertEqualsWithDelta($eurReference * 1.1339, $plan->priceIn('USD'), $eurReference * 1.1339 * 0.05, "{$slug} : dollar à moins de 5 % de l'équivalent");
        }

        $this->assertSame(['10 000 FCFA', '7 500 KMF', '15 €', '17 $', '165 DH'], array_map(fn ($c) => $this->plain(Currency::format(Plan::bySlug('essentiel')->priceIn($c), $c)), Currency::codes()));
    }

    public function test_prices_are_formatted_in_the_requested_currency(): void
    {
        $pro = Plan::bySlug('pro');

        $this->assertSame('40 000 FCFA', $this->plain($pro->formattedPrice('XOF')));
        $this->assertSame('30 000 KMF', $this->plain($pro->formattedPrice('KMF')));
        $this->assertSame('60 €', $this->plain($pro->formattedPrice('EUR')));
        $this->assertSame('70 $', $this->plain($pro->formattedPrice('USD')));
        $this->assertSame('par mois', $pro->periodLabel());
        $this->assertSame('Gratuit', Plan::default()->formattedPrice('EUR'));
        $this->assertSame('pendant 14 jours', Plan::default()->periodLabel());
    }

    public function test_a_currency_without_a_price_falls_back_to_the_first_defined_one(): void
    {
        $plan = Plan::create(['slug' => 'local', 'name' => 'Local', 'prices' => ['XOF' => 8000], 'limits' => Plan::FALLBACK_LIMITS, 'features' => [], 'sort' => 9]);

        $this->assertSame('8 000 FCFA', $this->plain($plan->formattedPrice('EUR')), 'jamais de prix vide ni de conversion inventée');
        $this->assertSame('XOF', $plan->currencyFor('USD'));
        $this->assertNull($plan->priceIn('USD'));
    }

    /* ---------- Choix de la devise ---------- */

    public function test_a_visitor_picks_a_currency_and_it_is_remembered(): void
    {
        $this->get('/')->assertOk()->assertSeeText("40\u{202F}000 FCFA"); // prix affiche ; les autres devises restent dans data-prices (selecteur)

        $this->get('/?devise=EUR')->assertOk()->assertSee('60 €', false)->assertSee('15 €', false);
        $this->get('/')->assertOk()->assertSee('60 €', false);

        $this->get('/?devise=KMF')->assertOk()->assertSee("30\u{202F}000 KMF", false);
    }

    public function test_an_unknown_currency_is_ignored(): void
    {
        $this->get('/?devise=GBP')->assertOk()->assertSee("40\u{202F}000", false)->assertDontSee('GBP');
        $this->assertNull(session('currency'));
    }

    public function test_a_client_keeps_their_currency_on_their_workspace(): void
    {
        [$workspace, $client] = $this->tenant('Moroni', 'pro');
        $this->assertSame('XOF', $workspace->currency);

        $this->actingAs($client)->get(route('billing.show', ['devise' => 'KMF']))->assertOk()->assertSee("30\u{202F}000 KMF", false);
        $this->assertSame('KMF', $workspace->fresh()->currency);

        // Sans paramètre, la devise de l'espace s'applique à toutes les pages.
        $this->actingAs($client)->get(route('billing.show'))->assertSee("90\u{202F}000 KMF", false);
    }

    public function test_registration_records_the_visitors_currency_and_starts_the_trial(): void
    {
        $this->get('/?devise=EUR');
        $this->register()->assertRedirect(route('dashboard', absolute: false));

        $workspace = Workspace::where('name', 'Boutique Awa')->firstOrFail();
        $this->assertSame('EUR', $workspace->currency);
        $this->assertSame('free', $workspace->plan);
        $this->assertSame(Workspace::TRIALING, $workspace->subscription_status);
        $this->assertTrue($workspace->onTrial());
        $this->assertFalse($workspace->trialExpired());
        $this->assertTrue($workspace->plan_ends_at->isBetween(now()->addDays(13), now()->addDays(15)));
    }

    /* ---------- Essai gratuit ---------- */

    public function test_the_landing_page_announces_the_trial_length(): void
    {
        $this->get('/')->assertOk()->assertSee('pendant 14 jours');
    }

    public function test_the_dashboard_shows_the_countdown_during_the_trial(): void
    {
        $this->register();

        $this->get(route('dashboard'))->assertOk()->assertSeeText('Essai gratuit : 14 jours restants');
    }

    public function test_an_expired_trial_pauses_the_assistant_and_says_so(): void
    {
        [$workspace, $owner, $bot] = $this->tenant('Essai', 'free');
        $this->teach($bot, 'Livraison', "# Livraison\nLa livraison coûte 2 000 FCFA à Bobo-Dioulasso.");
        $workspace->update(['plan_ends_at' => now()->subDay(), 'subscription_status' => Workspace::TRIALING]);

        $this->assertTrue($workspace->fresh()->trialExpired());
        $this->assertSame('Essai terminé', $workspace->fresh()->statusLabel());

        $reply = app(ChatService::class)->handleUserMessage($this->conversation($bot), 'La livraison à Bobo-Dioulasso coûte combien ?');

        $this->assertStringContainsString('momentanément indisponible', $reply->content);
        $this->assertSame('trial_expired', $reply->meta['reason']);
        $this->assertFalse($reply->meta['llm'], 'aucun appel IA une fois l\'essai terminé');

        $this->actingAs($owner)->get(route('dashboard'))->assertOk()->assertSee('Votre essai gratuit est terminé', false);
        $this->actingAs($owner)->get(route('billing.show'))->assertOk()->assertSee('Essai terminé')->assertSee('Choisir Pro');
    }

    public function test_the_client_keeps_access_to_their_data_after_the_trial(): void
    {
        [$workspace, $owner, $bot] = $this->tenant('Essai', 'free');
        $workspace->update(['plan_ends_at' => now()->subDays(5)]);

        $this->actingAs($owner)->get(route('sources.index', $bot))->assertOk();
        $this->actingAs($owner)->get(route('conversations.index', $bot))->assertOk();
    }

    public function test_paying_customers_in_their_grace_period_and_legacy_free_workspaces_are_not_paused(): void
    {
        $this->teach($lateBot = $this->tenant('En retard', 'pro')[2], 'Livraison', "# Livraison\nLa livraison coûte 2 000 FCFA à Bobo-Dioulasso.");
        $lateBot->workspace->update(['plan_ends_at' => now()->subDays(2), 'subscription_status' => Workspace::PAST_DUE]);
        $this->assertFalse($lateBot->workspace->fresh()->trialExpired(), 'la période de grâce conserve le service');

        $legacy = $this->tenant('Ancien', 'free')[0];
        $this->assertNull($legacy->plan_ends_at);
        $this->assertFalse($legacy->trialExpired());

        $reply = app(ChatService::class)->handleUserMessage($this->conversation($lateBot), 'La livraison à Bobo-Dioulasso coûte combien ?');
        $this->assertTrue($reply->meta['llm']);
    }

    public function test_upgrading_ends_the_pause(): void
    {
        [$workspace, , $bot] = $this->tenant('Essai', 'free');
        $workspace->update(['plan_ends_at' => now()->subDay()]);
        $this->assertTrue($workspace->fresh()->trialExpired());

        $this->actingAs($this->staff())->post(route('admin.payments.store', $workspace), [
            'plan' => 'pro', 'amount' => 40000, 'method' => 'orange_money', 'reference' => 'OM-1', 'period_months' => 1,
        ])->assertSessionHas('status');

        $this->assertFalse($workspace->fresh()->trialExpired());
        $this->assertFalse($workspace->fresh()->onTrial());
        $this->assertSame('pro', $workspace->fresh()->plan);
    }

    /* ---------- Commande quotidienne ---------- */

    public function test_the_command_reminds_before_the_trial_ends_then_pauses_once(): void
    {
        [$soon, $soonOwner] = $this->tenant('Bientot', 'free');
        [$over, $overOwner] = $this->tenant('Termine', 'free');
        [$fresh] = $this->tenant('Debut', 'free');
        $soon->update(['plan_ends_at' => now()->addDays(2), 'subscription_status' => Workspace::TRIALING]);
        $over->update(['plan_ends_at' => now()->subHours(3), 'subscription_status' => Workspace::TRIALING]);
        $fresh->update(['plan_ends_at' => now()->addDays(12), 'subscription_status' => Workspace::TRIALING]);

        $this->artisan('platform:subscriptions')->assertSuccessful();
        $this->artisan('platform:subscriptions')->assertSuccessful();

        $subjects = $this->subjects();
        $this->assertCount(1, array_filter($subjects, fn ($s) => str_contains($s, 'Votre essai gratuit se termine dans')), 'un seul rappel par essai');
        $this->assertCount(1, array_filter($subjects, fn ($s) => $s === 'Votre essai gratuit est terminé'), 'une seule notification de fin');
        $this->assertSame(Workspace::EXPIRED, $over->fresh()->subscription_status);
        $this->assertSame(Workspace::TRIALING, $soon->fresh()->subscription_status);
        $this->assertSame(Workspace::TRIALING, $fresh->fresh()->subscription_status);
        $this->assertCount(2, $subjects, 'l\'essai de 12 jours restants ne reçoit rien');
    }

    public function test_a_downgraded_paying_customer_is_paused_and_not_notified_twice(): void
    {
        [$gone] = $this->tenant('Perdu', 'pro');
        $gone->update(['plan_ends_at' => now()->subDays(10), 'subscription_status' => Workspace::PAST_DUE]);

        $this->artisan('platform:subscriptions')->assertSuccessful();
        $this->artisan('platform:subscriptions')->assertSuccessful();

        $gone = $gone->fresh();
        $this->assertSame('free', $gone->plan);
        $this->assertSame(Workspace::CANCELED, $gone->subscription_status, 'l\'état « résilié » n\'est pas écrasé par « essai terminé »');
        $this->assertTrue($gone->trialExpired());
        $this->assertCount(1, $this->subjects());
    }

    /* ---------- Administration ---------- */

    public function test_staff_creating_a_workspace_on_the_free_plan_starts_a_trial(): void
    {
        $this->actingAs($this->staff())->post(route('admin.workspaces.store'), [
            'name' => 'Nouvelle boutique', 'owner_name' => 'Fatou', 'owner_email' => 'fatou@example.com', 'plan' => 'free', 'currency' => 'KMF',
        ])->assertSessionHas('status');

        $workspace = Workspace::where('name', 'Nouvelle boutique')->firstOrFail();
        $this->assertSame('KMF', $workspace->currency);
        $this->assertTrue($workspace->onTrial());
        $this->assertTrue($workspace->plan_ends_at->isBetween(now()->addDays(13), now()->addDays(15)));
    }

    public function test_moving_a_workspace_to_the_free_plan_gives_the_default_trial_unless_a_date_is_set(): void
    {
        [$workspace] = $this->tenant('Client', 'pro');
        $staff = $this->staff();

        $this->actingAs($staff)->put(route('admin.workspaces.plan', $workspace), ['plan' => 'free'])->assertSessionHas('status');
        $this->assertTrue($workspace->fresh()->plan_ends_at->isBetween(now()->addDays(13), now()->addDays(15)));
        $this->assertSame(Workspace::TRIALING, $workspace->fresh()->subscription_status);

        $this->actingAs($staff)->put(route('admin.workspaces.plan', $workspace), ['plan' => 'free', 'ends_at' => now()->addDays(60)->toDateString()]);
        $this->assertSame(now()->addDays(60)->toDateString(), $workspace->fresh()->plan_ends_at->toDateString(), 'une prolongation explicite l\'emporte');

        $this->actingAs($staff)->put(route('admin.workspaces.plan', $workspace), ['plan' => 'business'])->assertSessionHas('status');
        $this->assertNull($workspace->fresh()->plan_ends_at, 'une offre payante offerte reste sans échéance');
        $this->assertSame(Workspace::ACTIVE, $workspace->fresh()->subscription_status);
    }

    public function test_a_payment_is_recorded_in_the_workspace_currency_or_the_one_chosen(): void
    {
        $workspace = Workspace::create(['name' => 'Moroni', 'plan' => 'free', 'currency' => 'KMF']);
        $staff = $this->staff();

        $this->actingAs($staff)->post(route('admin.payments.store', $workspace), ['plan' => 'pro', 'amount' => 26250, 'method' => 'virement', 'reference' => 'V1', 'period_months' => 1])->assertSessionHas('status');
        $this->assertSame('KMF', Payment::withoutGlobalScopes()->latest('id')->first()->currency);

        $this->actingAs($staff)->post(route('admin.payments.store', $workspace), ['plan' => 'pro', 'amount' => 55, 'currency' => 'EUR', 'method' => 'virement', 'reference' => 'V2', 'period_months' => 1])->assertSessionHas('status');
        $this->assertSame('EUR', Payment::withoutGlobalScopes()->latest('id')->first()->currency);

        $this->actingAs($staff)->post(route('admin.payments.store', $workspace), ['plan' => 'pro', 'amount' => 55, 'currency' => 'GBP', 'method' => 'virement', 'period_months' => 1])->assertSessionHasErrors('currency');
    }

    public function test_the_payment_form_proposes_the_price_in_the_workspace_currency(): void
    {
        $workspace = Workspace::create(['name' => 'Moroni', 'plan' => 'free', 'currency' => 'KMF']);

        $this->actingAs($this->staff())->get(route('admin.workspaces.show', $workspace))->assertOk()->assertSee("30\u{202F}000 KMF", false)->assertSee('Devise du paiement');
    }

    public function test_the_admin_overview_sums_revenue_per_currency_and_never_mixes_them(): void
    {
        $workspace = Workspace::create(['name' => 'Moroni', 'plan' => 'pro', 'currency' => 'KMF']);
        foreach ([[26250, 'KMF'], [26250, 'KMF'], [55, 'EUR']] as [$amount, $currency]) {
            Payment::create(['workspace_id' => $workspace->id, 'plan' => 'pro', 'amount' => $amount, 'currency' => $currency, 'method' => 'virement', 'period_months' => 1, 'paid_at' => now()]);
        }

        $this->actingAs($this->admin())->get(route('admin.overview'))->assertOk()->assertSee("52\u{202F}500 KMF", false)->assertSee('55 €', false);
    }

    public function test_the_plan_form_offers_one_field_per_currency_and_the_trial_length(): void
    {
        $this->actingAs($this->admin())->get(route('admin.plans.edit', Plan::default()))->assertOk()
            ->assertSee('prices[XOF]', false)->assertSee('prices[KMF]', false)->assertSee('prices[EUR]', false)->assertSee('prices[USD]', false)
            ->assertSee('trial_days', false);

        $this->actingAs($this->admin())->get(route('admin.plans.index'))->assertOk()->assertSee("10\u{202F}000 FCFA, 7\u{202F}500 KMF, 15 €, 17 $", false);
    }
}
