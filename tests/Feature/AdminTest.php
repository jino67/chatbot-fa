<?php

namespace Tests\Feature;

use App\Models\Bot;
use App\Models\Channel;
use App\Models\ChannelRequest;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class AdminTest extends TestCase
{
    use CreatesTenants;
    use RefreshDatabase;

    private Bot $bot;

    private User $owner;

    private ChannelRequest $request;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        [, $this->owner, $this->bot] = $this->tenant();
        $this->request = ChannelRequest::withoutGlobalScopes()->create([
            'workspace_id' => $this->bot->workspace_id, 'bot_id' => $this->bot->id, 'requester_id' => $this->owner->id,
            'business_name' => 'Boutique Test', 'phone_number' => '+226 70 00 00 00', 'country' => 'Burkina Faso',
        ]);
    }

    private function form(array $overrides = []): array
    {
        return array_merge([
            'provider' => Channel::WHATSAPP_META, 'status' => 'active', 'display_phone' => '+226 70 00 00 00',
            'request_status' => 'active', 'admin_notes' => 'Numéro vérifié, prêt.',
            'phone_number_id' => '109876543210', 'waba_id' => '555', 'access_token' => 'EAA-secret-token',
        ], $overrides);
    }

    public function test_the_back_office_is_reserved_to_staff(): void
    {
        $this->get('/admin')->assertRedirect('/login');
        $this->actingAs($this->owner)->get('/admin')->assertForbidden();
        $this->actingAs($this->owner)->get(route('admin.requests.show', $this->request->id))->assertForbidden();
        $this->actingAs($this->owner)->put(route('admin.requests.update', $this->request->id), $this->form())->assertForbidden();
        $this->actingAs($this->staff())->get('/admin')->assertOk();
        $this->actingAs($this->admin())->get('/admin')->assertOk();
    }

    public function test_a_role_cannot_be_granted_through_the_profile_or_mass_assignment(): void
    {
        $this->actingAs($this->owner)->patch('/profile', ['name' => 'Awa', 'email' => $this->owner->email, 'role' => 'super_admin', 'is_active' => 0]);

        $fresh = $this->owner->fresh();
        $this->assertTrue($fresh->isClient());
        $this->assertTrue($fresh->is_active);
    }

    public function test_the_tech_team_can_configure_and_activate_a_meta_channel(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->put(route('admin.requests.update', $this->request->id), $this->form())
            ->assertRedirect()->assertSessionHas('status');

        $channel = Channel::withoutGlobalScopes()->where('bot_id', $this->bot->id)->firstOrFail();
        $this->assertSame(Channel::WHATSAPP_META, $channel->type);
        $this->assertTrue($channel->isActive());
        $this->assertSame('109876543210', $channel->external_ref);
        $this->assertSame('EAA-secret-token', $channel->credential('access_token'));
        $this->assertSame($admin->id, $channel->activated_by);
        $this->assertSame(ChannelRequest::ACTIVE, $this->request->fresh()->status);
        $this->assertSame($admin->id, $this->request->fresh()->handled_by);
    }

    public function test_a_blank_secret_keeps_the_stored_one(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->put(route('admin.requests.update', $this->request->id), $this->form());

        $this->actingAs($admin)->put(route('admin.requests.update', $this->request->id), $this->form(['access_token' => '', 'display_phone' => '+226 71 11 11 11']));

        $channel = Channel::withoutGlobalScopes()->firstOrFail();
        $this->assertSame('EAA-secret-token', $channel->credential('access_token'));
        $this->assertSame('+226 71 11 11 11', $channel->display_phone);
        $this->assertSame(1, Channel::withoutGlobalScopes()->count(), 'mise a jour, pas de doublon');
    }

    public function test_activation_without_an_access_token_is_refused(): void
    {
        $this->actingAs($this->admin())
            ->put(route('admin.requests.update', $this->request->id), $this->form(['access_token' => '']))
            ->assertSessionHas('error');

        $this->assertSame(0, Channel::withoutGlobalScopes()->count());
    }

    public function test_twilio_configuration_requires_sid_and_a_sender(): void
    {
        $admin = $this->admin();
        $base = ['provider' => Channel::WHATSAPP_TWILIO, 'status' => 'pending', 'request_status' => 'in_progress'];

        $this->actingAs($admin)->put(route('admin.requests.update', $this->request->id), $base + ['account_sid' => 'ACtest'])
            ->assertSessionHas('error');

        $this->actingAs($admin)->put(route('admin.requests.update', $this->request->id), $base + [
            'account_sid' => 'ACtest', 'auth_token' => 'tok', 'from' => '+14155238886',
        ])->assertSessionHas('status');

        $channel = Channel::withoutGlobalScopes()->firstOrFail();
        $this->assertSame(Channel::WHATSAPP_TWILIO, $channel->type);
        $this->assertSame('14155238886', $channel->external_ref);
        $this->assertSame(Channel::PENDING, $channel->status);
    }

    public function test_the_connection_test_reports_success_and_failure_from_the_provider(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->put(route('admin.requests.update', $this->request->id), $this->form());
        $channel = Channel::withoutGlobalScopes()->firstOrFail();

        // Deux appels successifs : d'abord un succes, puis un jeton refuse.
        Http::fake(['graph.facebook.com/*' => Http::sequence()
            ->push(['display_phone_number' => '+226 70 00 00 00', 'verified_name' => 'Boutique Test', 'quality_rating' => 'GREEN'], 200)
            ->push(['error' => ['message' => 'Invalid OAuth access token']], 401)]);

        $this->actingAs($admin)->post(route('admin.channels.test', $channel->id))->assertSessionHas('status');
        $this->actingAs($admin)->post(route('admin.channels.test', $channel->id))
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'Invalid OAuth access token'));
    }

    public function test_the_secret_token_is_never_rendered_back_in_the_admin_page(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->put(route('admin.requests.update', $this->request->id), $this->form());

        $this->actingAs($admin)->get(route('admin.requests.show', $this->request->id))
            ->assertOk()->assertDontSee('EAA-secret-token');
    }

    public function test_a_client_can_request_whatsapp_once_at_a_time(): void
    {
        $this->request->delete();
        $payload = ['business_name' => 'Boutique Test', 'phone_number' => '+226 70 00 00 00', 'country' => 'Burkina Faso'];

        $this->actingAs($this->owner)->post(route('channels.whatsapp-request', $this->bot), $payload)->assertSessionHas('status');
        $this->actingAs($this->owner)->post(route('channels.whatsapp-request', $this->bot), $payload)->assertSessionHas('error');

        $this->assertSame(1, ChannelRequest::withoutGlobalScopes()->count());
        $this->assertSame(ChannelRequest::REQUESTED, ChannelRequest::withoutGlobalScopes()->first()->status);
    }

    public function test_phone_number_format_is_validated(): void
    {
        $this->request->delete();

        $this->actingAs($this->owner)->post(route('channels.whatsapp-request', $this->bot), ['business_name' => 'X', 'phone_number' => '<script>'])
            ->assertSessionHasErrors('phone_number');
    }

    public function test_plan_changes_apply_to_the_quotas(): void
    {
        $workspace = $this->bot->workspace;
        $this->assertSame('pro', $workspace->plan);

        $this->actingAs($this->staff())->put(route('admin.workspaces.plan', $workspace), ['plan' => 'free'])->assertSessionHas('status');

        $this->assertSame(1, $workspace->fresh()->limits()['bots']);
        $this->actingAs($this->staff())->put(route('admin.workspaces.plan', $workspace), ['plan' => 'inexistant'])->assertSessionHasErrors('plan');
    }

    public function test_admin_pages_render(): void
    {
        $admin = $this->admin();

        foreach ([route('admin.overview'), route('admin.requests.index'), route('admin.requests.show', $this->request->id), route('admin.workspaces.index')] as $url) {
            $this->actingAs($admin)->get($url)->assertOk();
        }
        $this->assertGreaterThan(0, Workspace::count());
    }
}
