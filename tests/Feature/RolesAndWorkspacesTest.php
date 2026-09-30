<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Bot;
use App\Models\Source;
use App\Models\User;
use App\Models\Workspace;
use Database\Seeders\DemoSeeder;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/** Trois roles : super admin (tout), admin (gere les espaces clients), client (son espace). */
class RolesAndWorkspacesTest extends TestCase
{
    use CreatesTenants;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    /** Pages reservees au super admin : offres, IA, parametres, equipe, journal. */
    private function superOnlyUrls(): array
    {
        return [route('admin.plans.index'), route('admin.ai.index'), route('admin.settings.edit'), route('admin.team.index'), route('admin.audit.index')];
    }

    public function test_visitors_are_sent_to_login_and_clients_never_reach_staff_pages(): void
    {
        [, $client] = $this->tenant();

        $urls = [route('admin.overview'), route('admin.workspaces.index'), ...$this->superOnlyUrls()];

        foreach ($urls as $url) {
            $this->get($url)->assertRedirect('/login');
        }
        foreach ($urls as $url) {
            $this->actingAs($client)->get($url)->assertForbidden();
        }
    }

    public function test_an_admin_manages_clients_but_not_the_platform(): void
    {
        $admin = $this->staff(User::ADMIN);

        foreach ([route('admin.overview'), route('admin.workspaces.index'), route('admin.workspaces.create'), route('admin.requests.index'), route('admin.plan-requests.index')] as $url) {
            $this->actingAs($admin)->get($url)->assertOk();
        }
        foreach ($this->superOnlyUrls() as $url) {
            $this->actingAs($admin)->get($url)->assertForbidden();
        }
        $this->actingAs($admin)->post(route('admin.team.store'), ['name' => 'X', 'email' => 'x@example.com', 'role' => 'super_admin'])->assertForbidden();
    }

    public function test_a_super_admin_reaches_every_staff_page(): void
    {
        $super = $this->staff(User::SUPER_ADMIN);
        [$workspace] = $this->tenant();

        foreach ([route('admin.overview'), route('admin.workspaces.index'), route('admin.workspaces.create'), route('admin.workspaces.show', $workspace), route('admin.plan-requests.index'), route('admin.plans.create'), ...$this->superOnlyUrls()] as $url) {
            $this->actingAs($super)->get($url)->assertOk();
        }
    }

    public function test_the_role_cannot_be_set_by_mass_assignment(): void
    {
        $user = new User(['name' => 'X', 'email' => 'x@example.com', 'password' => 'secret-pass', 'role' => 'super_admin', 'is_active' => false]);

        $this->assertSame(User::CLIENT, $user->role);
        $this->assertTrue($user->is_active);
    }

    public function test_an_admin_creates_a_client_workspace_with_its_owner_account(): void
    {
        $admin = $this->staff(User::ADMIN);

        $response = $this->actingAs($admin)->post(route('admin.workspaces.store'), [
            'name' => 'Pharmacie du Centre', 'owner_name' => 'Fatou Sanou', 'owner_email' => 'Fatou@Example.com', 'plan' => 'essentiel', 'country' => 'Burkina Faso',
        ]);

        $workspace = Workspace::where('name', 'Pharmacie du Centre')->firstOrFail();
        $response->assertRedirect(route('admin.workspaces.show', $workspace))->assertSessionHas('new_password');

        $owner = User::where('email', 'fatou@example.com')->firstOrFail();
        $this->assertTrue($owner->isClient());
        $this->assertSame($workspace->id, $owner->workspace_id);
        $this->assertSame('essentiel', $workspace->plan);

        // Le mot de passe provisoire affiche une seule fois permet bien de se connecter.
        $password = session('new_password')['password'];
        $this->post('/logout');
        $this->post('/login', ['email' => 'fatou@example.com', 'password' => $password])->assertRedirect(route('dashboard', absolute: false));
        $this->assertAuthenticatedAs($owner);
    }

    public function test_a_duplicate_owner_email_is_refused(): void
    {
        [, $existing] = $this->tenant();

        $this->actingAs($this->staff())->post(route('admin.workspaces.store'), [
            'name' => 'Doublon', 'owner_name' => 'X', 'owner_email' => $existing->email, 'plan' => 'free',
        ])->assertSessionHasErrors('owner_email');

        $this->assertNull(Workspace::where('name', 'Doublon')->first());
    }

    public function test_staff_must_enter_a_workspace_before_using_client_pages(): void
    {
        $admin = $this->staff();

        $this->actingAs($admin)->get(route('dashboard'))->assertRedirect(route('admin.overview'));
        $this->actingAs($admin)->get(route('bots.index'))->assertRedirect(route('admin.workspaces.index'));
    }

    public function test_an_admin_entering_a_workspace_manages_its_content_on_behalf_of_the_client(): void
    {
        [$workspace, $client, $bot] = $this->tenant('Boutique Awa');
        [, , $otherBot] = $this->tenant('Autre client');
        $admin = $this->staff();

        $this->actingAs($admin)->post(route('admin.workspaces.enter', $workspace))->assertRedirect(route('dashboard'));

        // Il voit l'espace du client, et seulement lui.
        $this->get(route('bots.index'))->assertOk()->assertSee($bot->name)->assertDontSee($otherBot->name);
        $this->get(route('sources.index', $otherBot))->assertNotFound();

        // Il ajoute une connaissance a la place du client : elle est rattachee a l'espace du client, pas au sien.
        $this->post(route('sources.store', $bot), ['type' => 'text', 'title' => 'Horaires', 'content' => 'Ouvert de 8 h à 19 h du lundi au samedi, fermé le dimanche.'])->assertSessionHas('status');
        $source = Source::withoutGlobalScopes()->where('name', 'Horaires')->firstOrFail();
        $this->assertSame($workspace->id, $source->workspace_id);

        // Il peut creer un assistant pour le client.
        $this->post(route('bots.store'), [
            'name' => 'Assistant cree par l\'equipe', 'language' => 'fr', 'sector' => 'commerce',
            'tone' => 'chaleureux', 'formality' => 'vous', 'emojis' => 'light', 'length' => 'balanced', 'languages' => ['fr'],
        ])->assertRedirect();
        $created = Bot::withoutGlobalScopes()->where('name', 'Assistant cree par l\'equipe')->firstOrFail();
        $this->assertSame($workspace->id, $created->workspace_id);
        $this->assertStringContainsString('Boutique Awa', $created->instructions, 'la consigne est generee pour l\'entreprise du client');

        // L'entree est tracee dans le journal.
        $this->assertTrue(AuditLog::where('action', 'workspace.entered')->where('user_id', $admin->id)->where('workspace_id', $workspace->id)->exists());

        // Il quitte l'espace : les pages client lui sont de nouveau fermees.
        $this->post(route('admin.workspaces.leave'))->assertRedirect(route('admin.workspaces.show', $workspace));
        $this->get(route('bots.index'))->assertRedirect(route('admin.workspaces.index'));
    }

    public function test_entering_a_workspace_does_not_leak_into_the_next_staff_member_or_client(): void
    {
        [$workspace, $client] = $this->tenant('Boutique Awa');
        [$other, $otherClient, $otherBot] = $this->tenant('Autre client');

        $this->actingAs($this->staff())->post(route('admin.workspaces.enter', $workspace));

        // Un client connecte reste enferme dans son propre espace, quelle que soit la session du personnel.
        $this->actingAs($otherClient)->withSession(['acting_workspace_id' => $workspace->id])->get(route('sources.index', $otherBot))->assertOk();
        $this->assertSame($other->id, $otherClient->currentWorkspaceId());
    }

    public function test_a_suspended_workspace_is_locked_but_can_still_reach_billing(): void
    {
        [$workspace, $client, $bot] = $this->tenant();
        $workspace->update(['is_suspended' => true, 'suspended_reason' => 'Impayé']);

        $this->actingAs($client)->get(route('dashboard'))->assertForbidden()->assertSee('suspendu');
        $this->actingAs($client)->get(route('sources.index', $bot))->assertForbidden();
        $this->actingAs($client)->get(route('billing.show'))->assertOk();
    }

    public function test_the_widget_of_a_suspended_workspace_stops_answering_with_the_ai(): void
    {
        [$workspace, , $bot] = $this->tenant();
        $this->teach($bot, 'Livraison', "# Livraison\nGratuite dès 25 000 FCFA.");
        $workspace->update(['is_suspended' => true]);

        $token = $this->postJson("/api/v1/widget/{$bot->public_key}/conversations", ['visitor_id' => 'visiteur-test-0001'])->assertOk()->json('token');
        $this->postJson("/api/v1/widget/{$bot->public_key}/conversations/{$token}/messages", ['content' => 'La livraison est gratuite ?'])->assertOk();

        $reply = \App\Models\Message::withoutGlobalScopes()->where('role', 'assistant')->latest('id')->firstOrFail();
        $this->assertFalse($reply->meta['llm'], 'aucun cout IA pour un espace suspendu');
    }

    public function test_staff_can_suspend_and_reactivate_a_workspace(): void
    {
        [$workspace] = $this->tenant();
        $admin = $this->staff();

        $this->actingAs($admin)->post(route('admin.workspaces.suspend', $workspace), ['reason' => 'Abus'])->assertSessionHas('status');
        $this->assertTrue($workspace->fresh()->is_suspended);
        $this->assertSame('Abus', $workspace->fresh()->suspended_reason);

        $this->actingAs($admin)->post(route('admin.workspaces.suspend', $workspace))->assertSessionHas('status');
        $this->assertFalse($workspace->fresh()->is_suspended);
        $this->assertNull($workspace->fresh()->suspended_reason);
    }

    public function test_a_deactivated_account_is_logged_out_and_cannot_use_the_app(): void
    {
        [, $client] = $this->tenant();
        $client->forceFill(['is_active' => false])->save();

        $this->actingAs($client)->get(route('dashboard'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_a_deactivated_staff_account_is_logged_out_too(): void
    {
        $admin = $this->staff();
        $admin->forceFill(['is_active' => false])->save();

        $this->actingAs($admin)->get(route('admin.overview'))->assertRedirect(route('login'));
    }

    public function test_an_admin_manages_client_users_but_not_other_staff(): void
    {
        [$workspace, $client] = $this->tenant();
        $admin = $this->staff(User::ADMIN);
        $super = $this->staff(User::SUPER_ADMIN);

        $this->actingAs($admin)->post(route('admin.users.reset', $client))->assertSessionHas('new_password');
        $this->actingAs($admin)->post(route('admin.users.toggle', $client))->assertSessionHas('status');
        $this->assertFalse($client->fresh()->is_active);

        $this->actingAs($admin)->post(route('admin.users.reset', $super))->assertForbidden();
        $this->actingAs($admin)->post(route('admin.users.toggle', $super))->assertForbidden();
        $this->assertTrue($super->fresh()->is_active);
    }

    public function test_the_user_limit_of_the_plan_is_enforced_when_staff_add_a_user(): void
    {
        $workspace = Workspace::create(['name' => 'Petit client', 'plan' => 'free']); // 1 utilisateur
        User::factory()->create(['workspace_id' => $workspace->id]);

        $this->actingAs($this->staff())->post(route('admin.workspace-users.store', $workspace), ['name' => 'Deuxième', 'email' => 'deux@example.com'])
            ->assertSessionHas('error');

        $this->assertNull(User::where('email', 'deux@example.com')->first());
    }

    public function test_a_super_admin_manages_the_team_and_cannot_demote_themselves(): void
    {
        $super = $this->staff(User::SUPER_ADMIN);

        $this->actingAs($super)->post(route('admin.team.store'), ['name' => 'Nouvel admin', 'email' => 'nouveau@kouma.test', 'role' => 'admin'])->assertSessionHas('new_password');
        $member = User::where('email', 'nouveau@kouma.test')->firstOrFail();
        $this->assertSame(User::ADMIN, $member->role);
        $this->assertNull($member->workspace_id);

        $this->actingAs($super)->put(route('admin.team.update', $member), ['role' => 'super_admin'])->assertSessionHas('status');
        $this->assertTrue($member->fresh()->isSuperAdmin());

        $this->actingAs($super)->put(route('admin.team.update', $super), ['role' => 'admin'])->assertSessionHas('error');
        $this->assertTrue($super->fresh()->isSuperAdmin());
    }

    public function test_the_audit_log_records_sensitive_actions(): void
    {
        [$workspace] = $this->tenant();
        $super = $this->staff(User::SUPER_ADMIN);

        $this->actingAs($super)->post(route('admin.workspaces.suspend', $workspace), ['reason' => 'Test']);

        $this->actingAs($super)->get(route('admin.audit.index'))->assertOk()->assertSee('workspace.suspended');
    }

    public function test_the_platform_seeder_guarantees_a_super_admin_and_is_idempotent(): void
    {
        $this->assertSame(0, User::where('role', User::SUPER_ADMIN)->count());

        $this->seed(PlatformSeeder::class);
        $this->seed(PlatformSeeder::class);

        $super = User::where('role', User::SUPER_ADMIN)->get();
        $this->assertCount(1, $super);
        $this->assertSame('admin@kouma.test', $super->first()->email);
        $this->assertNull($super->first()->workspace_id);
    }

    public function test_the_super_admin_email_and_password_come_from_the_environment(): void
    {
        config(['platform.admin_email' => 'Direction@Kouma.test', 'platform.admin_password' => 'un-mot-de-passe-solide']);

        $this->seed(PlatformSeeder::class);

        $this->post('/login', ['email' => 'direction@kouma.test', 'password' => 'un-mot-de-passe-solide'])->assertRedirect();
        $this->assertAuthenticated();
        $this->assertTrue(auth()->user()->isSuperAdmin());
    }

    public function test_make_admin_command_creates_and_promotes_accounts(): void
    {
        $this->artisan('platform:make-admin', ['email' => 'chef@kouma.test', '--role' => 'super_admin', '--password' => 'mot-de-passe-1234'])->assertSuccessful();
        $this->assertTrue(User::where('email', 'chef@kouma.test')->firstOrFail()->isSuperAdmin());

        $this->artisan('platform:make-admin', ['email' => 'chef@kouma.test', '--role' => 'admin'])->assertSuccessful();
        $this->assertSame(User::ADMIN, User::where('email', 'chef@kouma.test')->firstOrFail()->role);

        $this->artisan('platform:make-admin', ['email' => 'x@kouma.test', '--role' => 'client'])->assertFailed();
    }

    public function test_the_demo_seeder_builds_all_three_account_types_and_a_working_showcase(): void
    {
        $this->seed(DemoSeeder::class);

        $this->assertTrue(User::where('email', 'admin@kouma.test')->firstOrFail()->isSuperAdmin());
        $this->assertSame(User::ADMIN, User::where('email', 'equipe@kouma.test')->firstOrFail()->role);
        $this->assertTrue(User::where('email', 'awa@kouma.test')->firstOrFail()->isClient());

        $this->post('/login', ['email' => 'admin@kouma.test', 'password' => 'kouma-demo-2026'])->assertRedirect();

        // La vitrine de la page d'accueil est un assistant qui connait le produit et ses offres.
        $key = app(\App\Services\PlatformSettings::class)->get('marketing.landing_bot_key');
        $vitrine = Bot::withoutGlobalScopes()->where('public_key', $key)->firstOrFail();
        $this->assertGreaterThan(0, $vitrine->sources()->withoutGlobalScopes()->where('status', Source::READY)->count());
        $this->assertStringContainsString('Kouma', $vitrine->instructions);

        // Relancer la demo ne duplique rien.
        $this->seed(DemoSeeder::class);
        $this->assertSame(1, Bot::withoutGlobalScopes()->where('name', 'Assistant Kouma')->count());
    }
}
