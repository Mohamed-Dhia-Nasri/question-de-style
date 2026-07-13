<?php

namespace App\Modules\Billing\Services;

use App\Models\Tenant;
use App\Models\User;
use App\Modules\Billing\Exceptions\SeatLimitExceeded;
use App\Modules\Billing\Models\TenantSubscription;
use Closure;
use Illuminate\Support\Facades\DB;

/**
 * Seat accounting + concurrency-safe enforcement (ADR-0021).
 *
 * Seat model: every ACTIVE user of the tenant consumes exactly one seat —
 * including the owner. Deactivated (suspended) users and pending
 * invitations consume nothing; invitation acceptance re-checks
 * availability atomically instead of reserving seats up front.
 *
 * Concurrency mechanism: every seat-CONSUMING mutation (create user,
 * reactivate user, accept invitation) runs inside reserve(), which opens a
 * transaction and takes a `SELECT … FOR UPDATE` lock on the tenant row —
 * the single serialization point for a tenant's seat arithmetic. Two
 * concurrent acceptances therefore execute strictly one-after-another;
 * the second recounts AFTER the first committed and fails cleanly. A plain
 * count()-then-insert() without this lock is racy at READ COMMITTED and is
 * exactly what this class exists to forbid.
 *
 * Over-limit tenants (a plan downgrade below current usage) are handled
 * deterministically: nothing is ever auto-removed; reserve() refuses ANY
 * further team change until active members fit the limit again.
 */
class SeatLimiter
{
    /**
     * Active members of the tenant — context-independent (explicitly
     * unscoped + tenant-parameterised) so guest acceptance, webhooks and
     * HTTP requests all count the same rows.
     */
    public function activeSeats(int $tenantId): int
    {
        return User::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('active', true)
            ->count();
    }

    /**
     * The tenant's effective seat allowance. NULL = unlimited (enforcement
     * disabled). With enforcement on, a tenant whose subscription state
     * blocks product access (or who has none) gets ZERO seats to consume —
     * recovery happens on the billing page, not by growing the team.
     */
    public function limitFor(Tenant $tenant): ?int
    {
        if (! config('billing.enforced')) {
            return null;
        }

        $subscription = TenantSubscription::liveFor((int) $tenant->id);

        if ($subscription === null || ! $subscription->allowsProductAccess()) {
            return 0;
        }

        return $subscription->seatLimit();
    }

    /** Downgrade aftermath: more active members than the limit allows. */
    public function overLimit(Tenant $tenant): bool
    {
        $limit = $this->limitFor($tenant);

        return $limit !== null && $this->activeSeats((int) $tenant->id) > $limit;
    }

    /**
     * Run a seat-consuming mutation under the tenant's seat lock.
     *
     * Order of operations (all inside one transaction):
     *  1. lock the tenant row (serializes every seat mutation per tenant);
     *  2. refuse if the tenant is ALREADY over its limit (downgrade rule);
     *  3. run the mutation;
     *  4. recount and refuse (roll back) if the invariant broke.
     *
     * @template TReturn
     *
     * @param  Closure(Tenant): TReturn  $mutation
     * @return TReturn
     *
     * @throws SeatLimitExceeded
     */
    public function reserve(Tenant|int $tenant, Closure $mutation): mixed
    {
        $tenantId = $tenant instanceof Tenant ? (int) $tenant->id : $tenant;

        return DB::transaction(function () use ($tenantId, $mutation) {
            /** @var Tenant $locked */
            $locked = Tenant::query()->whereKey($tenantId)->lockForUpdate()->firstOrFail();

            $limit = $this->limitFor($locked);
            $before = $this->activeSeats($tenantId);

            if ($limit !== null && $before > $limit) {
                throw new SeatLimitExceeded($before, $limit, wasAlreadyOver: true);
            }

            $result = $mutation($locked);

            $after = $this->activeSeats($tenantId);

            if ($limit !== null && $after > $limit) {
                throw new SeatLimitExceeded($after, $limit, wasAlreadyOver: false);
            }

            return $result;
        });
    }
}
