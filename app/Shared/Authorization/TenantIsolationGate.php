<?php

namespace App\Shared\Authorization;

use App\Models\User;
use App\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Fail-closed cross-tenant authorization backstop (ADR-0019, hard-enforcement
 * phase). Registered as a Gate::before hook (AuthServiceProvider) so it runs
 * ahead of every policy and permission check: for any ability whose arguments
 * include a tenant-owned model whose tenant_id differs from the acting user's
 * tenant, authorization is DENIED — regardless of role or permission. A
 * fully-privileged ADMIN of Tenant A is thereby denied every ability against
 * a Tenant B model, closing the "policies gate on permission only" gap
 * centrally instead of editing 30 policies.
 *
 * It NEVER grants (returns only false or null), so it can only tighten
 * authorization, never widen it: permission-only policies keep deciding
 * same-tenant access exactly as before, and class-argument abilities
 * (viewAny/create, no model instance) fall straight through.
 *
 * This is defense in depth ON TOP OF TenantScope: even if a tenant-owned
 * model is ever loaded outside a bound context (a raw query, an unscoped
 * find, a future route that skips SetTenantContext, or a middleware-ordering
 * regression), it can still never be authorized for a user of another tenant.
 */
class TenantIsolationGate
{
    /**
     * Gate::before contract: the third argument is the ability's full
     * argument list as ONE array (Gate::callBeforeCallbacks passes it
     * unspread), holding model instances and/or class-name strings.
     *
     * @param  User|null  $user
     * @param  array<int, mixed>  $arguments
     * @return false|null false denies outright; null defers to the normal policy/permission stack
     */
    public function __invoke($user, string $ability, array $arguments = []): ?bool
    {
        // The acting user's tenant is the ground truth for "same tenant".
        // A null actor tenant (should never happen: users.tenant_id is NOT
        // NULL) fails closed against any owned model below.
        $actorTenantId = $user?->getAttribute('tenant_id');

        foreach ($arguments as $argument) {
            if (! $argument instanceof Model) {
                continue;
            }

            if (! in_array(BelongsToTenant::class, class_uses_recursive($argument), true)) {
                continue;
            }

            $modelTenantId = $argument->getAttribute('tenant_id');

            // A model with no owner (a genuinely global/platform row, or a
            // not-yet-persisted instance) is not tenant-gated here; every
            // real tenant-owned row carries a NOT NULL tenant_id by schema.
            if ($modelTenantId === null) {
                continue;
            }

            if ($actorTenantId === null || (int) $modelTenantId !== (int) $actorTenantId) {
                return false;
            }
        }

        return null;
    }
}
