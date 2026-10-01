<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\User;
use App\Models\Workspace;
use App\Services\UsageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/** Offre « Bon plan » à 25 000 FCFA : un seul assistant, WhatsApp et vocal en volume mesuré, et une grille qui reste rentable. */
class BonPlanTest extends TestCase
{
    use CreatesTenants;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    /* ---------- L'offre ---------- */

    public function test_the_bon_plan_costs_25000_fcfa_and_has_a_price_in_every_currency(): void
    {
        $plan = Plan::bySlug('bonplan');

        $this->assertSame('Bon plan', $plan->name);
        $this->assertSame(25000, $plan->priceIn('XOF'));
        $this->assertSame(18750, $plan->priceIn('KMF'), 'le franc comorien vaut 75 % du FCFA');
        $this->assertSame(38, $plan->priceIn('EUR'));
        $this->assertSame(43, $plan->priceIn('USD'));
        $this->assertSame(410, $plan->priceIn('MAD'));
        $this->assertSame('business', $plan->audience);
        $this->assertFalse($plan->isFree());
    }

    public function test_it_sits_between_essentiel_and_pro_in_price_and_in_order(): void
    {
        $order = Plan::forBusiness()->where('is_public', true)->orderBy('sort')->pluck('slug')->all();

        $this->assertSame(['free', 'essentiel', 'bonplan', 'pro', 'business'], $order);
        $this->assertGreaterThan(Plan::bySlug('essentiel')->priceIn('XOF'), Plan::bySlug('bonplan')->priceIn('XOF'));
        $this->assertLessThan(Plan::bySlug('pro')->priceIn('XOF'), Plan::bySlug('bonplan')->priceIn('XOF'));
    }

    public function test_it_includes_exactly_what_a_small_shop_needs(): void
    {
        $plan = Plan::bySlug('bonplan');

        $this->assertSame(1, $plan->limit('bots'), 'un seul assistant');
        $this->assertSame(2000, $plan->limit('messages_per_month'));
        $this->assertSame(800, $plan->limit('whatsapp_messages_per_month'));
        $this->assertSame(100, $plan->limit('voice_per_month'));

        $this->assertTrue($plan->feature('whatsapp'));
        $this->assertTrue($plan->feature('voice'));
        $this->assertFalse($plan->feature('templates'), 'les modèles WhatsApp sont facturés par Meta : réservés à Pro');
        $this->assertFalse($plan->feature('remove_branding'));
        $this->assertFalse($plan->feature('chat_import'), 'option à la carte');
        $this->assertFalse($plan->feature('api'));
    }

    public function test_a_client_on_the_bon_plan_is_limited_to_one_assistant_but_can_use_whatsapp_and_voice(): void
    {
        [$workspace, $owner] = $this->tenant('Petite boutique', 'bonplan');
        $usage = app(UsageService::class);

        $this->assertFalse($usage->canAddBot($workspace), 'l\'assistant créé par tenant() est déjà le seul autorisé');
        $this->assertTrue($usage->canSendWhatsApp($workspace));
        $this->assertSame(800, $usage->whatsappAllowance($workspace));
        $this->assertTrue($usage->canUseVoice($workspace));
        $this->assertSame(100, $usage->voiceAllowance($workspace));

        $this->actingAs($owner)->get(route('bots.create'))->assertRedirect(route('billing.show'))->assertSessionHas('error');
    }

    public function test_it_keeps_the_powered_by_mention_which_pro_removes(): void
    {
        [, , $bonPlanBot] = $this->tenant('Petite boutique', 'bonplan');
        [, , $proBot] = $this->tenant('Grande boutique', 'pro');

        $this->assertTrue($bonPlanBot->fresh()->publicConfig()['branding']);
        $this->assertFalse($proBot->fresh()->publicConfig()['branding']);
    }

    /* ---------- Pages ---------- */

    public function test_the_landing_page_shows_the_bon_plan_with_the_free_trial_as_a_separate_band(): void
    {
        $this->get('/')->assertOk()
            ->assertSee('Bon plan')->assertSeeText("25\u{202F}000 FCFA")
            ->assertSee('Commencer l\'essai gratuit')
            ->assertSee('Essentiel')->assertSee('Pro')->assertSee('Business');
    }

    public function test_the_landing_page_shows_the_bon_plan_price_in_the_visitors_currency(): void
    {
        $this->get('/?devise=EUR')->assertOk()->assertSeeText('38 €');
        $this->get('/?devise=MAD')->assertOk()->assertSeeText('410');
    }

    public function test_a_client_can_ask_for_the_bon_plan_from_the_billing_page(): void
    {
        [, $owner] = $this->tenant('Petite boutique', 'essentiel');

        $this->actingAs($owner)->get(route('billing.show'))->assertOk()->assertSee('Choisir Bon plan');
        $this->actingAs($owner)->post(route('billing.request'), ['plan' => 'bonplan'])->assertSessionHas('status');
    }

    public function test_the_pro_client_who_downgrades_to_the_bon_plan_keeps_their_data_but_loses_the_extra_assistants_limit(): void
    {
        [$workspace] = $this->tenant('Boutique', 'pro');
        $workspace->update(['plan' => 'bonplan']);

        $this->assertFalse(app(UsageService::class)->canAddBot($workspace->fresh()));
        $this->assertSame(1, $workspace->fresh()->limit('bots'));
    }

    /* ---------- Garde-fou des marges : la grille reste rentable ---------- */

    /**
     * Recalcule la marge de chaque offre payante avec les hypothèses de docs/COUTS.md (section 6) : si un prix ou un
     * volume est modifié sans rester rentable, ce test le signale. Coûts en dollars.
     *
     * @return array<string,array{normal:float,twilio:float,meta:float}>
     */
    private function margins(): array
    {
        $usdPerXof = 1.1339 / 655.957;
        $ai = (2800 * 1.0 + 200 * 5.0) / 1_000_000 * 1.15;   // Haiku 4.5, marge de sécurité de 15 %
        $twilio = config('platform.costs.whatsapp.twilio_fee');
        $voice = 0.01;                                          // pire cas par événement vocal
        $hosting = ['essentiel' => 4, 'bonplan' => 5, 'pro' => 6, 'business' => 12];

        $out = [];
        foreach ($hosting as $slug => $host) {
            $plan = Plan::bySlug($slug);
            $revenue = $plan->priceIn('XOF') * $usdPerXof;
            $fixed = $host + $revenue * 0.03;

            $cost = fn (float $share, float $whatsappUnit) => $fixed
                + $plan->limit('messages_per_month') * $share * $ai
                + $plan->limit('whatsapp_messages_per_month') * $share * $whatsappUnit
                + $plan->limit('voice_per_month') * $share * $voice;

            $out[$slug] = [
                'normal' => ($revenue - $cost(0.4, $twilio)) / $revenue,
                'twilio' => ($revenue - $cost(1.0, $twilio)) / $revenue,
                'meta' => ($revenue - $cost(1.0, 0.0)) / $revenue,
            ];
        }

        return $out;
    }

    public function test_every_paid_plan_stays_profitable_at_normal_usage_and_at_full_quota(): void
    {
        foreach ($this->margins() as $slug => $m) {
            $this->assertGreaterThanOrEqual(0.60, $m['normal'], "{$slug} : marge à usage normal");
            $this->assertGreaterThanOrEqual(0.25, $m['twilio'], "{$slug} : marge à volume entier par Twilio");
            $this->assertGreaterThanOrEqual(0.45, $m['meta'], "{$slug} : marge à volume entier par Meta direct");
        }
    }

    public function test_the_bon_plan_margins_match_the_figures_documented_in_the_migration(): void
    {
        $m = $this->margins()['bonplan'];

        $this->assertEqualsWithDelta(0.73, $m['normal'], 0.02);
        $this->assertEqualsWithDelta(0.53, $m['twilio'], 0.02);
        $this->assertEqualsWithDelta(0.63, $m['meta'], 0.02);
    }

    public function test_the_workspace_and_user_helpers_still_work_with_five_plans(): void
    {
        $this->assertSame(5, Plan::forBusiness()->count());
        $this->assertInstanceOf(Workspace::class, Workspace::create(['name' => 'Test', 'plan' => 'bonplan']));
        $this->assertInstanceOf(User::class, User::factory()->create());
    }
}
