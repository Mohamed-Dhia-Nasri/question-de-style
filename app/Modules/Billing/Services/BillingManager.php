<?php

namespace App\Modules\Billing\Services;

use App\Models\Tenant;
use App\Modules\Billing\Exceptions\StripeApiException;
use App\Modules\Billing\Http\StripeClient;
use App\Modules\Billing\Models\SubscriptionPlan;
use App\Shared\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Owner-facing billing actions (ADR-0021): Stripe customer provisioning,
 * subscription Checkout, and the Billing Portal.
 *
 * Every method operates on the ACTOR'S OWN tenant only — callers pass the
 * tenant they resolved from the authenticated user, and authorization
 * (the billing.manage owner gate) happens before this service is reached.
 * The customer id used for portal/checkout always comes from the tenants
 * row, never from user input, so a session for another tenant's billing
 * can never be minted.
 */
class BillingManager
{
    public function __construct(
        private readonly StripeClient $stripe,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * The tenant's Stripe customer id, creating the customer on first use.
     * Guarded by the tenant row lock so two concurrent "subscribe" clicks
     * cannot mint two customers (the deterministic Idempotency-Key in
     * StripeClient makes even a lost race converge on one customer).
     */
    public function ensureCustomer(Tenant $tenant): string
    {
        $existing = $tenant->stripe_customer_id;

        if ($existing !== null) {
            return $existing;
        }

        return DB::transaction(function () use ($tenant): string {
            /** @var Tenant $locked */
            $locked = Tenant::query()->whereKey($tenant->id)->lockForUpdate()->firstOrFail();

            if ($locked->stripe_customer_id !== null) {
                return $locked->stripe_customer_id;
            }

            $customerId = $this->stripe->createCustomer($locked);

            // Not mass assignable — trusted server state (ADR-0020 §6).
            $locked->forceFill(['stripe_customer_id' => $customerId])->save();
            $tenant->stripe_customer_id = $customerId;

            $this->audit->record('billing.customer.created', $locked);

            return $customerId;
        });
    }

    /** Hosted Checkout URL for subscribing the tenant to a plan. */
    public function checkoutUrl(Tenant $tenant, SubscriptionPlan $plan): string
    {
        if (! $plan->isPurchasable()) {
            throw new StripeApiException('create checkout session', "Plan {$plan->code} is not purchasable");
        }

        $url = $this->stripe->createCheckoutSession(
            $this->ensureCustomer($tenant),
            (string) $plan->stripe_price_id,
            route('account.billing').'?checkout=success',
            route('account.billing').'?checkout=canceled',
        );

        $this->audit->record('billing.checkout.started', $tenant, ['plan' => $plan->code]);

        return $url;
    }

    /**
     * Hosted Billing Portal URL — payment methods, invoices, plan changes
     * and cancellation all happen there; state returns via webhooks.
     */
    public function portalUrl(Tenant $tenant): string
    {
        $customerId = $tenant->stripe_customer_id;

        if ($customerId === null) {
            throw new StripeApiException(
                'create billing portal session',
                'No billing account exists yet — start a subscription first',
            );
        }

        $url = $this->stripe->createBillingPortalSession($customerId, route('account.billing'));

        $this->audit->record('billing.portal.opened', $tenant);

        return $url;
    }
}
