<?php

namespace App\Shared\Tenancy;

use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

/**
 * Tenant-aware validation rule factory (ADR-0019, hard-enforcement phase).
 *
 * A plain `Rule::exists('creators', 'id')` runs raw SQL that bypasses the
 * BelongsToTenant global scope, so it passes for ANY tenant's id — which (a)
 * lets a forged foreign id reach the persistence layer, where it only fails
 * as an opaque composite-FK 500, and (b) leaks a cross-tenant existence
 * oracle (a "taken"/"unknown" signal for ids in other tenants).
 *
 * `TenantRule::exists()` pins the existence check to the active tenant, so a
 * foreign-tenant id fails as a clean, uniform validation error and never
 * touches persistence. In platform context (no bound tenant) it degrades to
 * an unscoped existence check — matching how BelongsToTenant/TenantScope
 * treat platform code.
 */
final class TenantRule
{
    /**
     * An `exists` rule scoped to the active tenant. Use for every foreign key
     * that references a tenant-owned table from a user-facing form.
     */
    public static function exists(string $table, string $column = 'id'): Exists
    {
        $rule = Rule::exists($table, $column);

        $tenantId = app(TenantContext::class)->id();

        if ($tenantId !== null) {
            $rule->where('tenant_id', $tenantId);
        }

        return $rule;
    }
}
