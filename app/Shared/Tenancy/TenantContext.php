<?php

namespace App\Shared\Tenancy;

use App\Models\Tenant;
use Closure;

/**
 * The single source of truth for "which tenant is this unit of work for?".
 *
 * Registered as a SCOPED container binding (see TenancyServiceProvider):
 * the instance lives for exactly one request or one queued job and is
 * flushed by the framework between lifecycles, so tenant state can never
 * leak between requests or between jobs on a long-running worker.
 *
 * How it gets set:
 *  - HTTP:  SetTenantContext middleware, from the authenticated user.
 *  - Queue: the dispatching context's tenant id travels in the job payload
 *           and is restored by TenancyServiceProvider's JobProcessing hook.
 *  - Pipelines/CLI spanning tenants: platform code sets it explicitly per
 *    unit of work (e.g. per platform account) via runAs()/setId().
 *
 * A null tenant id means "platform context" (scheduler fan-out, health
 * endpoints, global telemetry). In platform context the TenantScope global
 * scope is inactive and BelongsToTenant does not auto-stamp — writes to
 * tenant-owned tables must then carry an explicit tenant_id or fail the
 * NOT NULL constraint. That failure is intentional: silent ownership
 * assignment is worse than a blocked write.
 */
final class TenantContext
{
    private ?int $tenantId = null;

    private ?Tenant $tenant = null;

    /** @var list<int|null> Saved contexts for nested job execution (sync queue). */
    private array $stack = [];

    public function set(Tenant $tenant): void
    {
        $this->tenantId = (int) $tenant->getKey();
        $this->tenant = $tenant;
    }

    public function setId(?int $tenantId): void
    {
        $this->tenantId = $tenantId;
        $this->tenant = null;
    }

    public function clear(): void
    {
        $this->tenantId = null;
        $this->tenant = null;
    }

    public function id(): ?int
    {
        return $this->tenantId;
    }

    public function hasTenant(): bool
    {
        return $this->tenantId !== null;
    }

    /** The current tenant id, or throw — for code paths that must never run tenant-less. */
    public function idOrFail(): int
    {
        if ($this->tenantId === null) {
            throw new MissingTenantContext(
                'No tenant context is set for this unit of work. HTTP requests set it from the '
                .'authenticated user; jobs inherit it from the dispatcher or must set it '
                .'explicitly (TenantContext::runAs) from the record they process.'
            );
        }

        return $this->tenantId;
    }

    /** The current Tenant model, lazily loaded. */
    public function tenant(): ?Tenant
    {
        if ($this->tenantId === null) {
            return null;
        }

        return $this->tenant ??= Tenant::query()->find($this->tenantId);
    }

    /**
     * Run a callback under a specific tenant context, restoring the previous
     * context afterwards (exception-safe). Pass null to run in platform
     * context.
     */
    public function runAs(Tenant|int|null $tenant, Closure $callback): mixed
    {
        $previousId = $this->tenantId;
        $previousTenant = $this->tenant;

        try {
            if ($tenant instanceof Tenant) {
                $this->set($tenant);
            } else {
                $this->setId($tenant);
            }

            return $callback();
        } finally {
            $this->tenantId = $previousId;
            $this->tenant = $previousTenant;
        }
    }

    /**
     * Enter a queued job's tenant context, saving the current one.
     * Paired with popJobContext() so inline (sync-queue) dispatch from a
     * request does not clobber the request's own context.
     */
    public function pushJobContext(?int $tenantId): void
    {
        $this->stack[] = $this->tenantId;
        $this->setId($tenantId);
    }

    /**
     * Leave a queued job's tenant context, restoring the saved one. An
     * unbalanced pop (empty stack) is a no-op — the current context then
     * belongs to someone else and must not be destroyed.
     */
    public function popJobContext(): void
    {
        if (count($this->stack) > 0) {
            $this->setId(array_pop($this->stack));
        }
    }
}
