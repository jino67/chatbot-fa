<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Payment;
use App\Models\PlanRequest;
use App\Models\UsageEvent;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/** Remise à zéro des paiements simulés : rien n'est effacé sans choix explicite, confirmation écrite et copie préalable. */
class TestDataResetTest extends TestCase
{
    use CreatesTenants;
    use RefreshDatabase;

    private Workspace $test;

    private Workspace $real;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');

        [$this->test] = $this->tenant('Espace de test', 'pro');
        [$this->real] = $this->tenant('Vrai client', 'pro');

        foreach ([$this->test, $this->real] as $workspace) {
            $workspace->update(['plan_started_at' => now(), 'plan_ends_at' => now()->addMonth(), 'wa_credit' => 500]);
            Payment::withoutGlobalScopes()->create(['workspace_id' => $workspace->id, 'plan' => 'pro', 'amount' => 40000, 'currency' => 'XOF', 'method' => 'orange_money', 'reference' => 'OM-'.$workspace->id, 'period_months' => 1, 'paid_at' => now()]);
            Payment::withoutGlobalScopes()->create(['workspace_id' => $workspace->id, 'plan' => 'recharge_whatsapp', 'amount' => 5000, 'currency' => 'XOF', 'method' => 'wave', 'period_months' => 0, 'paid_at' => now()]);
            UsageEvent::withoutGlobalScopes()->create(['workspace_id' => $workspace->id, 'kind' => UsageEvent::WA_OUT, 'cost_usd' => 0.01, 'created_at' => now()]);
            PlanRequest::withoutGlobalScopes()->create(['workspace_id' => $workspace->id, 'plan' => 'pro', 'status' => PlanRequest::REQUESTED]);
        }
    }

    private function resetWith(array $data)
    {
        return $this->actingAs($this->admin())->post(route('admin.test-data.reset'), $data);
    }

    private function rows(Workspace $workspace, string $model): int
    {
        return $model::withoutGlobalScopes()->where('workspace_id', $workspace->id)->count();
    }

    public function test_only_the_super_admin_reaches_the_page(): void
    {
        $this->actingAs($this->admin())->get(route('admin.test-data.index'))->assertOk()
            ->assertSee('Données de test')->assertSee('Espace de test')->assertSee('Vrai client')->assertSee('REMETTRE A ZERO');

        $this->actingAs($this->staff())->get(route('admin.test-data.index'))->assertForbidden();
        $this->actingAs(User::where('workspace_id', $this->test->id)->firstOrFail())->get(route('admin.test-data.index'))->assertForbidden();
        auth()->logout();
        $this->post(route('admin.test-data.reset'), [])->assertRedirect(route('login'));
    }

    public function test_nothing_is_erased_without_the_written_confirmation_or_a_choice(): void
    {
        $this->resetWith(['workspaces' => [$this->test->id], 'options' => ['payments'], 'confirmation' => 'oui'])->assertSessionHasErrors('confirmation');
        $this->resetWith(['options' => ['payments'], 'confirmation' => 'REMETTRE A ZERO'])->assertSessionHasErrors('workspaces');
        $this->resetWith(['workspaces' => [$this->test->id], 'confirmation' => 'REMETTRE A ZERO'])->assertSessionHasErrors('options');
        $this->resetWith(['workspaces' => [999999], 'options' => ['payments'], 'confirmation' => 'REMETTRE A ZERO'])->assertSessionHasErrors('workspaces.0');
        $this->resetWith(['workspaces' => [$this->test->id], 'options' => ['tout'], 'confirmation' => 'REMETTRE A ZERO'])->assertSessionHasErrors('options.0');

        $this->assertSame(2, $this->rows($this->test, Payment::class));
        $this->assertSame(500, $this->test->fresh()->wa_credit);
        $this->assertSame([], Storage::disk('local')->allFiles('backups'));
    }

    public function test_only_the_chosen_spaces_and_the_chosen_things_are_reset(): void
    {
        $this->resetWith(['workspaces' => [$this->test->id], 'options' => ['payments', 'credit', 'plan'], 'confirmation' => ' remettre a zero '])
            ->assertRedirect(route('admin.test-data.index'))->assertSessionHas('status');

        // L'espace de test : paiements effacés, crédit à 0, offre Découverte ; la consommation et les demandes (non cochées) restent.
        $test = $this->test->fresh();
        $this->assertSame(0, $this->rows($test, Payment::class));
        $this->assertSame(0, $test->wa_credit);
        $this->assertSame('free', $test->plan);
        $this->assertNull($test->plan_ends_at);
        $this->assertNull($test->plan_started_at);
        $this->assertSame(1, $this->rows($test, UsageEvent::class));
        $this->assertSame(1, $this->rows($test, PlanRequest::class));

        // Le vrai client n'est pas touché.
        $real = $this->real->fresh();
        $this->assertSame(2, $this->rows($real, Payment::class));
        $this->assertSame(500, $real->wa_credit);
        $this->assertSame('pro', $real->plan);
        $this->assertNotNull($real->plan_ends_at);
    }

    public function test_usage_and_plan_requests_can_be_erased_too(): void
    {
        $this->resetWith(['workspaces' => [$this->test->id], 'options' => ['usage', 'requests'], 'confirmation' => 'REMETTRE A ZERO'])->assertSessionHas('status');

        $this->assertSame(0, $this->rows($this->test, UsageEvent::class));
        $this->assertSame(0, $this->rows($this->test, PlanRequest::class));
        $this->assertSame(2, $this->rows($this->test, Payment::class), 'les paiements, non cochés, restent');
        $this->assertSame(1, $this->rows($this->real, UsageEvent::class));
    }

    public function test_a_copy_of_what_disappears_is_written_first_and_the_action_is_in_the_journal(): void
    {
        $this->resetWith(['workspaces' => [$this->test->id], 'options' => ['payments', 'usage'], 'confirmation' => 'REMETTRE A ZERO']);

        $files = Storage::disk('local')->allFiles('backups');
        $this->assertCount(1, $files);
        $backup = json_decode(Storage::disk('local')->get($files[0]), true);
        $this->assertSame(['payments', 'usage'], $backup['options']);
        $this->assertCount(2, $backup['payments']);
        $this->assertSame('OM-'.$this->test->id, $backup['payments'][0]['reference']);
        $this->assertSame(1, $backup['usage_events']['count']);
        $this->assertSame('Espace de test', $backup['workspaces'][0]['name']);

        $entry = AuditLog::where('action', 'payment.test_reset')->latest('id')->firstOrFail();
        $this->assertSame(2, $entry->meta['counts']['payments']);
        $this->assertSame($files[0], $entry->meta['backup']);
    }

    public function test_the_revenue_of_the_overview_follows_the_reset(): void
    {
        $revenue = fn () => Payment::withoutGlobalScopes()->sum('amount');
        $this->assertEquals(90000, $revenue());

        $this->resetWith(['workspaces' => [$this->test->id], 'options' => ['payments'], 'confirmation' => 'REMETTRE A ZERO']);

        $this->assertEquals(45000, $revenue());
    }

    public function test_the_command_only_simulates_without_force_and_erases_with_it(): void
    {
        $this->artisan('platform:reset-test-data', ['--workspace' => [$this->test->id], '--payments' => true, '--credit' => true])
            ->expectsOutputToContain('Simulation')->assertSuccessful();
        $this->assertSame(2, $this->rows($this->test, Payment::class));

        $this->artisan('platform:reset-test-data')->assertFailed();

        $this->artisan('platform:reset-test-data', ['--workspace' => [$this->test->id], '--payments' => true, '--credit' => true, '--force' => true])->assertSuccessful();
        $this->assertSame(0, $this->rows($this->test, Payment::class));
        $this->assertSame(0, $this->test->fresh()->wa_credit);
        $this->assertSame(2, $this->rows($this->real, Payment::class));
    }

    public function test_a_space_with_nothing_to_reset_is_not_listed(): void
    {
        [$clean] = $this->tenant('Espace propre', 'free');

        $this->actingAs($this->admin())->get(route('admin.test-data.index'))->assertOk()->assertDontSee('Espace propre');
    }
}
