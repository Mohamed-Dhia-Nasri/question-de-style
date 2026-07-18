<?php

namespace App\Providers;

use App\Actions\Fortify\ResetUserPassword;
use App\Models\User;
use App\Shared\Http\Responses\GenericPasswordResetLinkResponse;
use App\Shared\Http\Responses\LoginResponse;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\FailedPasswordResetLinkRequestResponse;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(LoginResponseContract::class, LoginResponse::class);

        // Mask the "no such user" outcome so the reset endpoint is not a
        // user-enumeration oracle (M33).
        $this->app->singleton(
            FailedPasswordResetLinkRequestResponse::class,
            GenericPasswordResetLinkResponse::class,
        );
    }

    public function boot(): void
    {
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);

        // Only active users may authenticate (ENT-User.active).
        Fortify::authenticateUsing(function (Request $request): ?User {
            $user = User::where('email', $request->string('email')->value())->first();

            if ($user !== null
                && $user->active
                && Hash::check($request->string('password')->value(), $user->password)) {
                return $user;
            }

            return null;
        });

        // Auth screens are hand-built Blade views on the TailAdmin shell
        // (ADR-0012 — no CRUD/auth scaffolding framework).
        Fortify::loginView(fn () => view('auth.login'));
        Fortify::requestPasswordResetLinkView(fn () => view('auth.forgot-password'));
        Fortify::resetPasswordView(fn (Request $request) => view('auth.reset-password', ['request' => $request]));
        Fortify::confirmPasswordView(fn () => view('auth.confirm-password'));

        RateLimiter::for('login', function (Request $request) {
            $throttleKey = Str::transliterate(Str::lower($request->input(Fortify::username())).'|'.$request->ip());

            return Limit::perMinute(5)->by($throttleKey);
        });

        // The password-reset endpoints are throttled by ThrottlePasswordReset,
        // appended to the web group in bootstrap/app.php (M33) — Fortify leaves
        // its own reset routes unthrottled and reads no limiter config for
        // them, and they register too late for a boot-time route mutation.
    }
}
