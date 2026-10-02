<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use App\Support\AuditCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/** Le Journal : noms lisibles, niveaux, filtres, export, et le formulaire de notification qui ne se déguise plus en PUT. */
class AuditJournalTest extends TestCase
{
    use CreatesTenants;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function log(string $action, ?User $user = null, ?int $workspace = null, ?string $subject = null, array $meta = [], ?string $when = null): AuditLog
    {
        return AuditLog::create([
            'user_id' => $user?->id, 'workspace_id' => $workspace, 'action' => $action, 'subject' => $subject,
            'meta' => $meta ?: null, 'ip' => '203.0.113.7', 'created_at' => $when ?? now(),
        ]);
    }

    public function test_actions_are_shown_in_plain_french_with_their_level(): void
    {
        $admin = $this->admin();
        $this->log('settings.updated', $admin, null, 'Paramètres');
        $this->log('bot.created', $admin, null, 'Assistant');
        $this->log('action.inconnue', $admin, null, 'Cible', ['raison' => 'test', 'ok' => true]);

        $page = $this->actingAs($admin)->get(route('admin.audit.index'))->assertOk();
        $page->assertSee('Paramètres de la plateforme modifiés')->assertSee('critique')->assertSee('settings.updated');
        $page->assertSee('Assistant créé')->assertSee('203.0.113.7');
        // Une action que le catalogue ne connaît pas reste lisible, au niveau normal.
        $page->assertSee('action.inconnue')->assertSee('raison : test')->assertSee('ok : oui');
        $this->assertSame('normal', AuditCatalog::level('action.inconnue'));
    }

    public function test_the_journal_filters_by_category_person_space_level_search_and_period(): void
    {
        [$ws, , ] = $this->tenant('Boutique A');
        [$other] = $this->tenant('Atelier B');
        $super = $this->admin();
        $team = $this->staff(User::ADMIN);
        $this->log('workspace.suspended', $super, $ws->id, 'Suspension zorglub');
        $this->log('bot.created', $team, $other->id, 'Assistant de Atelier B');
        $this->log('payment.recorded', $team, $ws->id, 'Paiement Orange');
        $this->log('plan.updated', $super, null, 'Offre Pro', [], now()->subDays(20)->toDateTimeString());
        $this->log('team.role_changed', $super, null, 'Ancienne action', [], now()->subDays(60)->toDateTimeString());

        $get = fn (array $query) => $this->actingAs($super)->get(route('admin.audit.index', $query));

        $get(['categorie' => 'paiements'])->assertSee('Paiement Orange')->assertDontSee('Suspension zorglub')->assertDontSee('Assistant de Atelier B');
        $get(['personne' => $team->id])->assertSee('Paiement Orange')->assertSee('Assistant de Atelier B')->assertDontSee('Offre Pro');
        $get(['client' => $other->id])->assertSee('Assistant de Atelier B')->assertDontSee('Paiement Orange');
        $get(['niveau' => 'sensible'])->assertSee('Paiement Orange')->assertSee('Suspension zorglub')->assertDontSee('Assistant de Atelier B');
        $get(['q' => 'Atelier'])->assertSee('Assistant de Atelier B')->assertDontSee('Paiement Orange');
        // Par défaut 30 jours ; « Tout » remonte plus loin.
        $get([])->assertSee('Offre Pro')->assertDontSee('Ancienne action');
        $get(['jours' => 0])->assertSee('Ancienne action');
        $get(['jours' => 1])->assertDontSee('Offre Pro');
        // Un joker saisi n'est pas un joker.
        $get(['q' => '%'])->assertOk()->assertDontSee('Paiement Orange');
    }

    public function test_the_header_counts_actions_people_and_sensitive_ones(): void
    {
        $super = $this->admin();
        $team = $this->staff(User::ADMIN);
        $this->log('settings.updated', $super);
        $this->log('bot.created', $team);
        $this->log('bot.created', $team);

        $page = $this->actingAs($super)->get(route('admin.audit.index'))->assertOk();
        $page->assertSee('3</strong> action(s) par <strong class="text-brand-950">2</strong> personne(s)', false)->assertSee('1 sensible(s)');
    }

    public function test_supervision_entries_link_to_the_conversation(): void
    {
        $super = $this->admin();
        $this->log('chat.viewed', $super, null, 'Conversation n° 42 (Assistant)', ['conversation' => 42]);

        $this->actingAs($super)->get(route('admin.audit.index', ['categorie' => 'chat']))
            ->assertSee('Conversation consultée')->assertSee(route('admin.chats.show', 42), false);
    }

    public function test_the_csv_export_is_safe_logged_and_for_the_super_admin_only(): void
    {
        [$ws] = $this->tenant('Boutique A');
        $super = $this->admin();
        $this->log('workspace.updated', $super, $ws->id, '=CMD()', ['champ' => 'nom']);

        $csv = $this->actingAs($super)->get(route('admin.audit.export'))->assertOk()->streamedContent();
        $this->assertStringContainsString('Espace client modifié', $csv);
        $this->assertStringContainsString("'=CMD()", $csv);
        $this->assertStringContainsString('champ : nom', $csv);
        $this->assertStringContainsString('Boutique A', $csv);
        $this->assertTrue(AuditLog::where('action', 'audit.exported')->exists(), 'l\'export du journal est lui-même journalisé');

        $this->actingAs($this->staff(User::ADMIN))->get(route('admin.audit.export'))->assertForbidden();
    }

    public function test_the_campaign_form_does_not_send_a_put_to_the_audience_estimate(): void
    {
        $super = $this->admin();

        // Sur une campagne en modification, FormData contient « _method=PUT » : le script doit le retirer avant d'estimer.
        $this->actingAs($super)->get(route('admin.notifications.create'))->assertOk()->assertSee("data.delete('_method')", false);
        $this->actingAs($super)->post(route('admin.notifications.estimate'), ['audience_type' => 'all'])->assertOk()->assertJsonStructure(['users']);
        $this->actingAs($super)->put(route('admin.notifications.estimate'))->assertStatus(405);
    }
}
