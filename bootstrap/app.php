<?php

use App\Shared\Http\Controllers\HealthController;
use App\Shared\Http\Middleware\AssignRequestId;
use App\Shared\Http\Middleware\EnsureUserIsActive;
use App\Shared\Http\Middleware\SetSecureHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            // Registered OUTSIDE the web group on purpose: /health must not
            // depend on the session store (database-backed), or a database
            // outage would 500 in StartSession before the controller can
            // report a structured 503. Global middleware still applies.
            Route::get('/health', HealthController::class)->name('health');
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Global: every response (including /up and /health) carries a
        // request id and the secure default headers.
        $middleware->append(AssignRequestId::class);
        $middleware->append(SetSecureHeaders::class);

        // AuthenticateSession invalidates other sessions when the password
        // changes (e.g. after a reset following account compromise);
        // EnsureUserIsActive revokes access the moment an account is
        // deactivated, not just at next login.
        $middleware->web(append: [
            AuthenticateSession::class,
            EnsureUserIsActive::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
