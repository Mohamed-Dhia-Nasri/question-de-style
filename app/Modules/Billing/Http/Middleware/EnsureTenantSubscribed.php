<?php

namespace App\Modules\Billing\Http\Middleware;

use App\Modules\Billing\Models\TenantSubscription;
use App\Shared\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Server-side subscription gate (ADR-0021), aliased 'subscribed'.
 *
 * Applied to every PRODUCT surface (dashboard, monitoring, discovery, crm,
 * reports, downloads). Deliberately NOT applied to the account, billing,
 * team, and auth surfaces — a lapsed tenant must always be able to reach
 * billing recovery and reduce seats, and no data is ever deleted.
 *
 * State table (SubscriptionStatus::allowsProductAccess): ACTIVE, TRIALING
 * and PAST_DUE (dunning grace) pass; INCOMPLETE, INCOMPLETE_EXPIRED,
 * UNPAID, PAUSED, CANCELED, or no subscription redirect to the account
 * page. Gated on config('billing.enforced') — OFF until commercial launch,
 * because the founding tenant predates billing.
 */
class EnsureTenantSubscribed
{
    public function __construct(private readonly TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! config('billing.enforced')) {
            return $next($request);
        }

        $tenantId = $this->context->id();

        // Guests/platform context never reach product routes ('auth' runs
        // first); nothing to gate here.
        if ($tenantId === null) {
            return $next($request);
        }

        $subscription = TenantSubscription::liveFor($tenantId);

        if ($subscription !== null && $subscription->allowsProductAccess()) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            abort(402, 'An active subscription is required.');
        }

        return redirect()->route('account.index');
    }
}
