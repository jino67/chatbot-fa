<?php

namespace Tests\Feature;

use App\Models\Payment;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Currency;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/** La devise est propre à chaque compte, FCFA par défaut : elle ne suit pas le navigateur d'un compte à l'autre. */
class AccountCurrencyTest extends TestCase
{
    use CreatesTenants;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function staff(): User
    {
        return User::factory()->create(['role' => User::SUPER_ADMIN]);
    }

    public function test_a_new_account_starts_in_fcfa(): void
    {
        $this->post('/register', [
            'name' => 'Awa Ouedraogo', 'email' => 'awa@example.com', 'company' => 'Boutique Awa',
            'password' => 'un-mot-de-passe-solide-2026', 'password_confirmation' => 'un-mot-de-passe-solide-2026',
        ]);

        $this->assertSame('XOF', Workspace::where('name', 'Boutique Awa')->firstOrFail()->currency);
    }

    public function test_a_clients_choice_stays_on_their_account_and_never_in_the_browser_session(): void
    {
        [$workspace, $client] = $this->tenant('Moroni', 'pro');

        $this->actingAs($client)->get(route('billing.show', ['devise' => 'KMF']))->assertOk()->assertSee("30\u{202F}000 KMF", false);

        $this->assertSame('KMF', $workspace->fresh()->currency);
        $this->assertNull(session('currency'), 'rien en session : le choix ne doit pas suivre le navigateur');
    }

    public function test_one_accounts_choice_does_not_change_another_account_or_a_visitor(): void
    {
        [, $moroni] = $this->tenant('Moroni', 'pro');
        [$other, $otherOwner] = $this->tenant('Ouaga', 'pro');

        $this->actingAs($moroni)->get(route('billing.show', ['devise' => 'EUR']));

        $this->assertSame('XOF', $other->fresh()->currency);
        $this->actingAs($otherOwner)->get(route('billing.show'))->assertSee("120\u{202F}000 FCFA", false)->assertDontSee("90\u{202F}000 KMF", false);

        // Un visiteur sur le même navigateur, après déconnexion, repart de FCFA.
        auth()->logout();
        $this->flushSession();
        $this->get('/')->assertOk()->assertSeeText("40\u{202F}000 FCFA");
    }

    public function test_the_staff_sees_and_changes_the_currency_of_the_space_they_entered_not_their_own_session(): void
    {
        $workspace = Workspace::create(['name' => 'Moroni', 'plan' => 'pro', 'currency' => 'KMF']);
        $staff = $this->staff();

        // Le personnel a, dans son navigateur, choisi l'euro sur les pages publiques.
        $this->actingAs($staff)->withSession(['currency' => 'EUR']);

        // Entré dans l'espace de Moroni : la devise affichée est celle de Moroni.
        $this->post(route('admin.workspaces.enter', $workspace))->assertRedirect();
        $this->get(route('billing.show'))->assertOk()->assertSee("30\u{202F}000 KMF", false)->assertDontSee("60 €", false);

        // Y choisir une devise la change pour ce client (la bannière le dit : tout est fait en son nom), pas pour le personnel.
        $this->get(route('billing.show', ['devise' => 'XOF']))->assertOk()->assertSee("40\u{202F}000 FCFA", false);
        $this->assertSame('XOF', $workspace->fresh()->currency);
        $this->assertSame('EUR', session('currency'), 'la session du personnel n\'est pas touchée');
    }

    public function test_the_admin_can_set_a_clients_account_currency_and_a_missing_value_keeps_it(): void
    {
        $workspace = Workspace::create(['name' => 'Go AI Digital', 'plan' => 'pro', 'currency' => 'KMF']);
        $admin = $this->staff();
        $form = ['name' => 'Go AI Digital', 'country' => 'Burkina Faso'];

        $this->actingAs($admin)->get(route('admin.workspaces.show', $workspace))->assertOk()->assertSee('Devise du compte');

        $this->actingAs($admin)->put(route('admin.workspaces.update', $workspace), $form)->assertSessionHas('status');
        $this->assertSame('KMF', $workspace->fresh()->currency, 'sans devise envoyée, celle du compte reste');

        $this->actingAs($admin)->put(route('admin.workspaces.update', $workspace), $form + ['currency' => 'XOF'])->assertSessionHas('status');
        $this->assertSame('XOF', $workspace->fresh()->currency);

        $this->actingAs($admin)->put(route('admin.workspaces.update', $workspace), $form + ['currency' => 'GBP'])->assertSessionHasErrors('currency');
        $this->assertSame('XOF', $workspace->fresh()->currency);
    }

    public function test_the_overview_shows_zero_fcfa_when_nothing_was_collected_and_hides_empty_currencies(): void
    {
        $workspace = Workspace::create(['name' => 'Moroni', 'plan' => 'pro', 'currency' => 'KMF']);
        $admin = $this->staff();

        Payment::create(['workspace_id' => $workspace->id, 'plan' => 'pro', 'amount' => 0, 'currency' => 'KMF', 'method' => 'virement', 'period_months' => 1, 'paid_at' => now()]);

        $page = $this->actingAs($admin)->get(route('admin.overview'))->assertOk();
        $page->assertSee("0 FCFA", false);
        $page->assertDontSee('0 KMF', false);

        Payment::create(['workspace_id' => $workspace->id, 'plan' => 'pro', 'amount' => 26250, 'currency' => 'KMF', 'method' => 'virement', 'period_months' => 1, 'paid_at' => now()]);
        $this->actingAs($admin)->get(route('admin.overview'))->assertSee("26\u{202F}250 KMF", false);
    }

    public function test_the_billing_page_says_the_currency_belongs_to_the_account(): void
    {
        [, $client] = $this->tenant('Moroni', 'pro');

        $this->actingAs($client)->get(route('billing.show'))->assertOk()->assertSee('Devise de votre compte');
        $this->assertSame('XOF', Currency::default());
    }
}
