<?php

namespace Database\Factories\Concerns;

use App\Shared\Tenancy\MissingTenantContext;
use App\Shared\Tenancy\TenantContext;

/**
 * Tenant resolution for factories (ADR-0019).
 *
 * Factories REQUIRE an active TenantContext: every tenant-owned factory
 * defaults tenant_id to the context, so an inline nested chain
 * (ContentItem → PlatformAccount, Brand → Client, …) always lands in ONE
 * tenant and the composite tenant FKs hold. The context is bound
 * automatically per test (tests/TestCase.php) and by the seeders; to build
 * records for another tenant, switch the context (actingAsTenant /
 * withTenant) rather than passing tenant_id by hand — a context-less
 * chain would mint a stray tenant per model and die on the composite FKs,
 * so that state throws immediately instead.
 */
trait ResolvesTenant
{
    /** The active context's tenant id; throws without a context. */
    protected function defaultTenantId(): int
    {
        $tenantId = app(TenantContext::class)->id();

        if ($tenantId === null) {
            throw new MissingTenantContext(
                'Factory for '.static::class.' needs an active TenantContext: bind one '
                .'(tests get a default tenant from tests/TestCase.php; seeders/scripts must '
                .'set it explicitly) or pass an explicit tenant_id attribute.'
            );
        }

        return $tenantId;
    }
}
