<?php

namespace Tests\Feature;

use App\Shared\Enums\RoleName;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_screen_renders(): void
    {
        $this->get('/login')->assertOk();
    }

    public function test_staff_can_authenticate_and_land_on_the_dashboard(): void
    {
        $this->seedRoles();
        $user = $this->makeUser(RoleName::Analyst);

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $this->assertAuthenticatedAs($user);
        $response->assertRedirect(route('dashboard'));
    }

    public function test_client_viewer_lands_on_the_reports_area(): void
    {
        $this->seedRoles();
        $viewer = $this->makeUser(RoleName::ClientViewer);

        $response = $this->post('/login', [
            'email' => $viewer->email,
            'password' => 'password',
        ]);

        $this->assertAuthenticatedAs($viewer);
        $response->assertRedirect(route('reports.index'));
    }

    public function test_invalid_credentials_are_rejected(): void
    {
        $this->seedRoles();
        $user = $this->makeUser(RoleName::Analyst);

        $this->from('/login')->post('/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ])->assertRedirect('/login');

        $this->assertGuest();
    }

    public function test_inactive_users_cannot_authenticate(): void
    {
        $this->seedRoles();
        $user = $this->makeUser(RoleName::Analyst, ['active' => false]);

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $this->assertGuest();
    }

    public function test_deactivation_revokes_an_existing_session(): void
    {
        $this->seedRoles();
        $user = $this->makeUser(RoleName::Analyst);

        $this->actingAs($user)->get('/dashboard')->assertOk();

        // Deactivate mid-session: the very next request must be rejected,
        // not just the next login (EnsureUserIsActive middleware).
        $user->update(['active' => false]);

        $this->get('/dashboard')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_password_reset_enforces_the_platform_password_policy(): void
    {
        $this->seedRoles();
        $user = $this->makeUser(RoleName::Analyst);

        $token = app('auth.password.broker')->createToken($user);

        // 8 characters passed under Laravel's fallback default — the platform
        // policy is 12 (Password::defaults in AppServiceProvider).
        $this->post('/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'short-pw',
            'password_confirmation' => 'short-pw',
        ])->assertSessionHasErrors('password');
    }

    public function test_users_can_log_out(): void
    {
        $this->seedRoles();
        $user = $this->makeUser(RoleName::Analyst);

        $this->actingAs($user)->post('/logout')->assertRedirect('/');

        $this->assertGuest();
    }

    public function test_guests_are_redirected_to_login_from_protected_routes(): void
    {
        foreach (['/dashboard', '/monitoring', '/discovery', '/crm', '/admin/users', '/reports'] as $uri) {
            $this->get($uri)->assertRedirect('/login');
        }
    }

    public function test_login_is_rate_limited(): void
    {
        $this->seedRoles();
        $user = $this->makeUser(RoleName::Analyst);

        foreach (range(1, 5) as $attempt) {
            $this->post('/login', ['email' => $user->email, 'password' => 'wrong-password']);
        }

        $this->post('/login', ['email' => $user->email, 'password' => 'wrong-password'])
            ->assertStatus(429);
    }
}
