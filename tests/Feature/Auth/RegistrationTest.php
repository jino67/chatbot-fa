<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_screen_can_be_rendered(): void
    {
        $response = $this->get('/register');

        $response->assertStatus(200);
    }

    public function test_new_users_can_register(): void
    {
        $response = $this->post('/register', [
            'name' => 'Test User',
            'company' => 'Boutique Awa',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('dashboard', absolute: false));
    }

    public function test_registration_creates_an_isolated_workspace_on_the_free_plan(): void
    {
        $this->post('/register', [
            'name' => 'Awa',
            'company' => 'Boutique Awa',
            'email' => 'awa@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $user = User::where('email', 'awa@example.com')->firstOrFail();

        $this->assertSame('Boutique Awa', $user->workspace->name);
        $this->assertSame('free', $user->workspace->plan);
        $this->assertTrue($user->isClient());
    }

    public function test_the_company_name_is_required(): void
    {
        $this->post('/register', [
            'name' => 'Awa', 'email' => 'awa@example.com', 'password' => 'password', 'password_confirmation' => 'password',
        ])->assertSessionHasErrors('company');

        $this->assertSame(0, Workspace::count());
    }

    public function test_a_visitor_cannot_make_themselves_super_admin_at_registration(): void
    {
        $this->post('/register', [
            'name' => 'Pirate', 'company' => 'X', 'email' => 'pirate@example.com',
            'password' => 'password', 'password_confirmation' => 'password',
            'is_super_admin' => 1, 'role' => 'admin', 'workspace_id' => 999,
        ]);

        $user = User::where('email', 'pirate@example.com')->firstOrFail();
        $this->assertTrue($user->isClient());
        $this->assertNotSame(999, $user->workspace_id);
    }
}
