<?php

namespace App\Providers;

use App\Shared\Authorization\TenantIsolationGate;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Registers the cross-tenant authorization backstop (ADR-0019). The
 * per-model policies (module service providers) decide role/permission;
 * this Gate::before decides tenant ownership FIRST and fail-closed, so the
 * two concerns stay separate and no tenant role can ever reach another
 * tenant's model regardless of how privileged it is.
 */
class AuthServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Gate::before(new TenantIsolationGate);
    }
}
