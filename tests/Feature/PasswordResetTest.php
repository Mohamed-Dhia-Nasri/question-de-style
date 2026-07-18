<?php

namespace Tests\Feature;

use App\Shared\Enums\RoleName;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_reset_password_link_screen_renders(): void
    {
        $this->get('/forgot-password')->assertOk();
    }

    public function test_forgot_password_does_not_disclose_whether_an_account_exists(): void
    {
        // M33: known vs unknown emails must be indistinguishable, or the
        // endpoint is a user-enumeration oracle.
        $this->seedRoles();
        $user = $this->makeUser(RoleName::Analyst);

        $this->post('/forgot-password', ['email' => $user->email])
            ->assertSessionHas('status')
            ->assertSessionHasNoErrors();

        $this->post('/forgot-password', ['email' => 'ghost@nowhere.test'])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status');
    }

    public function test_forgot_password_is_rate_limited(): void
    {
        $this->seedRoles();

        foreach (range(1, 5) as $ignored) {
            $this->post('/forgot-password', ['email' => 'ghost@nowhere.test']);
        }

        $this->post('/forgot-password', ['email' => 'ghost@nowhere.test'])->assertStatus(429);
    }

    public function test_reset_password_link_can_be_requested(): void
    {
        Notification::fake();

        $this->seedRoles();
        $user = $this->makeUser(RoleName::Analyst);

        $this->post('/forgot-password', ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class);
    }

    public function test_password_can_be_reset_with_a_valid_token(): void
    {
        Notification::fake();

        $this->seedRoles();
        $user = $this->makeUser(RoleName::Analyst);

        $this->post('/forgot-password', ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user) {
            $this->get('/reset-password/'.$notification->token)->assertOk();

            $this->post('/reset-password', [
                'token' => $notification->token,
                'email' => $user->email,
                'password' => 'new-secure-password',
                'password_confirmation' => 'new-secure-password',
            ])->assertSessionHasNoErrors();

            $this->post('/login', [
                'email' => $user->email,
                'password' => 'new-secure-password',
            ]);

            $this->assertAuthenticatedAs($user->fresh());

            return true;
        });
    }
}
