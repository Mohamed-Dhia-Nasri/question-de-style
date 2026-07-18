<?php

use App\Modules\Billing\Http\Middleware\EnsureTenantSubscribed;
use App\Shared\Http\Controllers\HealthController;
use App\Shared\Http\Middleware\AssignRequestId;
use App\Shared\Http\Middleware\EnsureUserIsActive;
use App\Shared\Http\Middleware\SetSecureHeaders;
use App\Shared\Http\Middleware\SetTenantContext;
use App\Shared\Http\Middleware\ThrottlePasswordReset;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Routing\Middleware\SubstituteBindings;
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
        // deactivated, not just at next login. SetTenantContext then binds
        // the request to the authenticated user's tenant (ADR-0019).
        $middleware->web(append: [
            AuthenticateSession::class,
            EnsureUserIsActive::class,
            SetTenantContext::class,
            // Fortify leaves its password-reset routes unthrottled; this
            // rate-limits them by path once the route is resolved (M33).
            ThrottlePasswordReset::class,
        ]);

        // ADR-0021: server-side subscription gate for product surfaces.
        // Route groups opt in with 'subscribed'; account/billing/team/auth
        // surfaces never carry it (billing recovery must stay reachable).
        $middleware->alias([
            'subscribed' => EnsureTenantSubscribed::class,
        ]);

        // ADR-0019: the tenant context MUST be bound before route-model
        // binding runs, or SubstituteBindings resolves tenant-owned models
        // unscoped (a foreign-tenant id would resolve instead of 404-ing).
        // SubstituteBindings sits in the framework priority list, so being
        // appended to the web group is not enough — pin the order:
        // EnsureUserIsActive → SetTenantContext → SubstituteBindings.
        $middleware->prependToPriorityList(
            SubstituteBindings::class,
            SetTenantContext::class,
        );
        $middleware->prependToPriorityList(
            SetTenantContext::class,
            EnsureUserIsActive::class,
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
