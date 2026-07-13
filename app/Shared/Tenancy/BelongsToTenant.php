<?php

namespace App\Shared\Tenancy;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Marks an Eloquent model as tenant-owned (its table carries a NOT NULL
 * tenant_id ownership key — ADR-0019).
 *
 * Behaviour:
 *  - Applies TenantScope: queries are tenant-filtered whenever a tenant
 *    context is active.
 *  - On create, stamps tenant_id from the active TenantContext when the
 *    caller did not set one. With no context and no explicit tenant_id the
 *    INSERT fails on the NOT NULL constraint — deliberately: ownership is
 *    never guessed.
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope);

        static::creating(function ($model): void {
            if ($model->getAttribute('tenant_id') === null) {
                $model->setAttribute('tenant_id', app(TenantContext::class)->id());
            }
        });
    }

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
