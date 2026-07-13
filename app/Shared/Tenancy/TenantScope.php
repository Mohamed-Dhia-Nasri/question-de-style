<?php

namespace App\Shared\Tenancy;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Global scope applied by BelongsToTenant: when a tenant context is active,
 * every Eloquent query on a tenant-owned model is filtered to that tenant.
 *
 * With NO active context (platform jobs, scheduler fan-out, artisan) the
 * scope is a no-op — platform services legitimately span tenants. Hard
 * enforcement (requiring a context on every user-facing path) is the
 * security phase's job; this scope is the mechanism it will lean on.
 */
final class TenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $tenantId = app(TenantContext::class)->id();

        if ($tenantId !== null) {
            $builder->where($model->qualifyColumn('tenant_id'), $tenantId);
        }
    }
}
