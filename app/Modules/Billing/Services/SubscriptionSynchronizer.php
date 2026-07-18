<?php

namespace App\Modules\Billing\Services;

use App\Models\Tenant;
use App\Modules\Billing\Http\StripeClient;
use App\Modules\Billing\Models\SubscriptionPlan;
use App\Modules\Billing\Models\TenantSubscription;
use App\Shared\Audit\AuditLogger;
use App\Shared\Enums\SubscriptionStatus;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use ValueError;

/**
 * Applies verified Stripe events to local subscription state (ADR-0021).
 *
 * The ONLY writer of ENT-TenantSubscription. Runs in platform context (the
 * webhook endpoint is tenant-less) and follows the ADR-0020 §7 doctrine:
 * resolve the tenant from the TRUSTED stripe_customer_id mapping — never
 * from payload metadata — then perform every tenant-owned write under
 * runAs(tenant).
 *
 * Robustness contract:
 *  - idempotent: handlers SYNC state from the event (no increments), so a
 *    replayed event converges to the same row;
 *  - out-of-order safe: a subscription event older than the last applied
 *    one (Stripe event.created watermark) is skipped;
 *  - unknown customer / price / status never throw — they log structured
 *    warnings and acknowledge, because a Stripe retry cannot fix them.
 */
class SubscriptionSynchronizer
{
    public function __construct(
        private readonly StripeClient $stripe,
        private readonly TenantContext $context,
        private readonly AuditLogger $audit,
    ) {}

    /** @param  array<string, mixed>  $event  the full verified Stripe event */
    public function handle(array $event): void
    {
        $type = (string) $event['type'];
        /** @var array<string, mixed> $object */
        $object = $event['data']['object'] ?? [];
        // Fall back to "now" (not epoch 0) when Stripe omits created, so a
        // new subscription's first watermark is not stamped 1970-01-01.
        $eventCreated = isset($event['created']) ? (int) $event['created'] : now()->getTimestamp();

        match ($type) {
            'checkout.session.completed' => $this->checkoutCompleted($object, $eventCreated),
            'customer.subscription.created',
            'customer.subscription.updated',
            'customer.subscription.deleted' => $this->subscriptionEvent($object, $eventCreated, $type),
            'invoice.payment_failed' => $this->paymentFailed($object),
            'invoice.payment_succeeded' => $this->paymentSucceeded($object),
            default => Log::info('stripe webhook: ignored event type', ['type' => $type]),
        };
    }

    /** @param  array<string, mixed>  $session */
    private function checkoutCompleted(array $session, int $eventCreated): void
    {
        if (($session['mode'] ?? null) !== 'subscription') {
            return;
        }

        $tenant = $this->resolveTenant($session['customer'] ?? null, 'checkout.session.completed');
        $subscriptionId = $session['subscription'] ?? null;

        if ($tenant === null || ! is_string($subscriptionId) || $subscriptionId === '') {
            return;
        }

        // The session carries only the subscription id — fetch the
        // canonical object so the very first sync is complete.
        $subscription = $this->stripe->getSubscription($subscriptionId);

        $this->applySubscription($tenant, $subscription, $eventCreated, 'checkout.session.completed');
    }

    /** @param  array<string, mixed>  $subscription */
    private function subscriptionEvent(array $subscription, int $eventCreated, string $type): void
    {
        $tenant = $this->resolveTenant($subscription['customer'] ?? null, $type);

        if ($tenant === null) {
            return;
        }

        $this->applySubscription($tenant, $subscription, $eventCreated, $type);
    }

    /** @param  array<string, mixed>  $subscription  a Stripe subscription object */
    private function applySubscription(Tenant $tenant, array $subscription, int $eventCreated, string $eventType): void
    {
        $stripeId = $subscription['id'] ?? null;

        if (! is_string($stripeId) || $stripeId === '') {
            Log::warning('stripe webhook: subscription object without id', ['event_type' => $eventType]);

            return;
        }

        try {
            $status = SubscriptionStatus::fromStripe((string) ($subscription['status'] ?? ''));
        } catch (ValueError) {
            Log::warning('stripe webhook: unknown subscription status', [
                'subscription' => $stripeId,
                'status' => $subscription['status'] ?? null,
            ]);

            return;
        }

        // Lock the row FOR UPDATE (the controller wraps handle() in a
        // transaction): concurrent, out-of-order deliveries for the same
        // subscription must serialize on it, so the second handler blocks
        // until the first commits and then re-reads the FRESH watermark below.
        // Without the lock both read the same pre-write watermark and a stale
        // event can resurrect a canceled subscription (a lost update).
        /** @var TenantSubscription|null $existing */
        $existing = TenantSubscription::query()
            ->withoutGlobalScopes()
            ->where('stripe_subscription_id', $stripeId)
            ->lockForUpdate()
            ->first();

        // Out-of-order guard: never let a stale event roll state backwards.
        // Equal timestamps still apply (created+updated share a second at
        // checkout) — the sync is idempotent, so that is harmless.
        if ($existing !== null
            && $existing->last_stripe_event_at !== null
            && $eventCreated < $existing->last_stripe_event_at->getTimestamp()) {
            Log::info('stripe webhook: out-of-order event skipped', [
                'subscription' => $stripeId,
                'event_type' => $eventType,
            ]);

            return;
        }

        $plan = $this->resolvePlan($subscription, $existing, $stripeId);

        if ($plan === null) {
            return;
        }

        $this->context->runAs($tenant, function () use ($tenant, $subscription, $existing, $plan, $status, $stripeId, $eventCreated, $eventType): void {
            $row = $existing ?? new TenantSubscription;

            // A brand-new live subscription supersedes any other live row
            // (a new checkout after cancellation): terminate the old row
            // first or the one-live-per-tenant index rejects the insert.
            if ($existing === null && ! in_array($status, SubscriptionStatus::terminal(), true)) {
                $this->supersedeLiveSubscription($tenant, $stripeId, $eventType);
            }

            $endedAt = $subscription['ended_at'] ?? null;

            $row->forceFill([
                'tenant_id' => $tenant->id,
                'subscription_plan_id' => $plan->id,
                'stripe_subscription_id' => $stripeId,
                'status' => $status,
                'cancel_at_period_end' => (bool) ($subscription['cancel_at_period_end'] ?? false),
                'trial_ends_at' => $this->timestamp($subscription['trial_end'] ?? null),
                'current_period_ends_at' => $this->timestamp($subscription['current_period_end'] ?? null),
                'ended_at' => $this->timestamp($endedAt)
                    ?? ($row->ended_at ?? (in_array($status, SubscriptionStatus::terminal(), true) ? Carbon::createFromTimestamp($eventCreated) : null)),
                'last_stripe_event_at' => Carbon::createFromTimestamp($eventCreated),
            ])->save();

            $this->audit->record('billing.subscription.synced', $row, [
                'event_type' => $eventType,
                'status' => $status->value,
                'plan' => $plan->code,
            ]);
        });
    }

    /**
     * @param  array<string, mixed>  $subscription
     */
    private function resolvePlan(array $subscription, ?TenantSubscription $existing, string $stripeId): ?SubscriptionPlan
    {
        $priceId = $subscription['items']['data'][0]['price']['id'] ?? null;

        if (is_string($priceId)) {
            $plan = SubscriptionPlan::query()->where('stripe_price_id', $priceId)->first();

            if ($plan !== null) {
                return $plan;
            }
        }

        // Unknown price: an existing row keeps its plan (the status change
        // still applies); a NEW subscription cannot be attached to a plan —
        // acknowledged and logged loudly, never guessed (ADR-0019 doctrine).
        if ($existing !== null) {
            Log::warning('stripe webhook: unknown price — keeping current plan', [
                'subscription' => $stripeId,
                'price' => $priceId,
            ]);

            return $existing->plan;
        }

        Log::error('stripe webhook: subscription for unmapped price ignored', [
            'subscription' => $stripeId,
            'price' => $priceId,
        ]);

        return null;
    }

    /** Terminate any other live row so the partial unique index holds. */
    private function supersedeLiveSubscription(Tenant $tenant, string $incomingStripeId, string $eventType): void
    {
        /** @var TenantSubscription|null $live */
        $live = TenantSubscription::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->whereNotIn('status', SubscriptionStatus::terminalValues())
            ->where('stripe_subscription_id', '!=', $incomingStripeId)
            ->first();

        if ($live === null) {
            return;
        }

        $live->forceFill([
            'status' => SubscriptionStatus::Canceled,
            'ended_at' => now(),
        ])->save();

        Log::warning('stripe webhook: live subscription superseded', [
            'superseded' => $live->stripe_subscription_id,
            'by' => $incomingStripeId,
            'event_type' => $eventType,
        ]);
    }

    /** @param  array<string, mixed>  $invoice */
    private function paymentFailed(array $invoice): void
    {
        $tenant = $this->resolveTenant($invoice['customer'] ?? null, 'invoice.payment_failed');

        if ($tenant === null) {
            return;
        }

        // Status transitions (→ past_due/unpaid) arrive via
        // customer.subscription.updated; this handler keeps the recovery
        // trail. Identifiers only — never amounts or payment details.
        $this->context->runAs($tenant, function () use ($tenant, $invoice): void {
            $this->audit->record('billing.payment_failed', $tenant, [
                'invoice' => is_string($invoice['id'] ?? null) ? $invoice['id'] : null,
                'attempt_count' => (int) ($invoice['attempt_count'] ?? 0),
            ]);
        });

        Log::warning('stripe webhook: invoice payment failed', [
            'tenant_id' => $tenant->id,
            'invoice' => $invoice['id'] ?? null,
        ]);
    }

    /** @param  array<string, mixed>  $invoice */
    private function paymentSucceeded(array $invoice): void
    {
        $tenant = $this->resolveTenant($invoice['customer'] ?? null, 'invoice.payment_succeeded');

        if ($tenant === null) {
            return;
        }

        // Only a RECOVERY is audit-worthy (the subscription.updated event
        // syncs the status itself); routine renewals stay quiet.
        $subscription = TenantSubscription::liveFor((int) $tenant->id);

        if ($subscription !== null && $subscription->status === SubscriptionStatus::PastDue) {
            $this->context->runAs($tenant, function () use ($tenant, $invoice): void {
                $this->audit->record('billing.payment_recovered', $tenant, [
                    'invoice' => is_string($invoice['id'] ?? null) ? $invoice['id'] : null,
                ]);
            });
        }
    }

    private function resolveTenant(mixed $customerId, string $eventType): ?Tenant
    {
        if (! is_string($customerId) || $customerId === '') {
            Log::warning('stripe webhook: event without customer', ['event_type' => $eventType]);

            return null;
        }

        $tenant = Tenant::query()->where('stripe_customer_id', $customerId)->first();

        if ($tenant === null) {
            // Not retryable — a foreign or stale customer id. Acknowledge
            // and leave a structured trace (id only, never payload).
            Log::warning('stripe webhook: unknown customer', [
                'customer' => $customerId,
                'event_type' => $eventType,
            ]);
        }

        return $tenant;
    }

    private function timestamp(mixed $epoch): ?Carbon
    {
        return is_int($epoch) && $epoch > 0 ? Carbon::createFromTimestamp($epoch) : null;
    }
}
