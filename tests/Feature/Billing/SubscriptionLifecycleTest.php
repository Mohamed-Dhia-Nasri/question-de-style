<?php

namespace Tests\Feature\Billing;

use App\Models\Tenant;
use App\Modules\Billing\Models\SubscriptionPlan;
use App\Modules\Billing\Models\TenantSubscription;
use App\Modules\Billing\Services\SeatLimiter;
use App\Modules\Billing\Services\SubscriptionSynchronizer;
use App\Shared\Audit\AuditLog;
use App\Shared\Enums\RoleName;
use App\Shared\Enums\SubscriptionStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\InteractsWithStripe;
use Tests\TestCase;

/**
 * Subscription lifecycle synchronization (ADR-0021).
 *
 * Drives SubscriptionSynchronizer::handle() DIRECTLY with verified-shaped
 * Stripe event arrays — the HTTP endpoint (signature verification, event-id
 * dedup ledger) is covered by the webhook test file. Here we pin the state
 * machine itself: TenantSubscription rows attach to the tenant resolved via
 * the trusted stripe_customer_id mapping, plans resolve by stripe_price_id,
 * statuses map through SubscriptionStatus::fromStripe(), the event.created
 * watermark rejects out-of-order events, replay converges (idempotent sync),
 * a new live subscription supersedes the old one, and unknown customers or
 * prices are acknowledged without inventing state.
 */
class SubscriptionLifecycleTest extends TestCase
{
    use InteractsWithStripe;
    use RefreshDatabase;

    /** Wire the default test tenant to a Stripe customer id, as BillingManager would. */
    private function connectStripeCustomer(string $customerId): Tenant
    {
        // stripe_customer_id is deliberately NOT fillable (trusted mapping,
        // ADR-0021) — set it the way production code does, via forceFill.
        $this->defaultTenant->forceFill(['stripe_customer_id' => $customerId])->save();

        return $this->defaultTenant->refresh();
    }

    /** @param array<string, mixed> $event */
    private function sync(array $event): void
    {
        app(SubscriptionSynchronizer::class)->handle($event);
    }

    /** Unscoped lookup — the synchronizer runs in platform context, so must we. */
    private function subscriptionRow(string $stripeSubscriptionId): ?TenantSubscription
    {
        return TenantSubscription::query()
            ->withoutGlobalScopes()
            ->where('stripe_subscription_id', $stripeSubscriptionId)
            ->first();
    }

    private function subscriptionCount(): int
    {
        return TenantSubscription::query()->withoutGlobalScopes()->count();
    }

    public function test_subscription_created_event_creates_row_attached_to_tenant_and_plan(): void
    {
        $tenant = $this->connectStripeCustomer('cus_created');
        $plan = SubscriptionPlan::factory()->create();

        $periodEnd = now()->addMonth()->getTimestamp();
        $eventCreated = now()->getTimestamp();

        $this->sync($this->stripeEvent(
            'customer.subscription.created',
            $this->stripeSubscriptionObject('sub_created', 'cus_created', (string) $plan->stripe_price_id, 'active', [
                'current_period_end' => $periodEnd,
            ]),
            created: $eventCreated,
        ));

        $row = $this->subscriptionRow('sub_created');

        $this->assertNotNull($row, 'a created event for a known customer + price must persist a row');
        $this->assertSame((int) $tenant->id, (int) $row->tenant_id);
        $this->assertSame($plan->id, $row->subscription_plan_id, 'plan must resolve by stripe_price_id');
        $this->assertSame(SubscriptionStatus::Active, $row->status);
        $this->assertSame($periodEnd, $row->current_period_ends_at?->getTimestamp());
        $this->assertSame($eventCreated, $row->last_stripe_event_at?->getTimestamp(), 'the event.created watermark must be stamped');
    }

    public function test_trialing_status_maps_to_trialing_and_allows_product_access(): void
    {
        $this->connectStripeCustomer('cus_trial');
        $plan = SubscriptionPlan::factory()->create();
        $trialEnd = now()->addDays(14)->getTimestamp();

        $this->sync($this->stripeEvent(
            'customer.subscription.created',
            $this->stripeSubscriptionObject('sub_trial', 'cus_trial', (string) $plan->stripe_price_id, 'trialing', [
                'trial_end' => $trialEnd,
            ]),
        ));

        $row = $this->subscriptionRow('sub_trial');

        $this->assertNotNull($row);
        $this->assertSame(SubscriptionStatus::Trialing, $row->status);
        $this->assertSame($trialEnd, $row->trial_ends_at?->getTimestamp());
        $this->assertTrue($row->allowsProductAccess(), 'TRIALING is a fully entitled state (ADR-0021)');
    }

    public function test_upgrade_to_a_different_known_price_switches_plan_and_seat_limit(): void
    {
        $this->connectStripeCustomer('cus_upgrade');
        $starter = SubscriptionPlan::factory()->seats(5)->create();
        $growth = SubscriptionPlan::factory()->seats(10)->create();
        $t0 = now()->getTimestamp();

        $this->sync($this->stripeEvent(
            'customer.subscription.created',
            $this->stripeSubscriptionObject('sub_up', 'cus_upgrade', (string) $starter->stripe_price_id),
            created: $t0,
        ));

        // Stripe sends the plan change as subscription.updated with the new price.
        $this->sync($this->stripeEvent(
            'customer.subscription.updated',
            $this->stripeSubscriptionObject('sub_up', 'cus_upgrade', (string) $growth->stripe_price_id),
            created: $t0 + 60,
        ));

        $row = $this->subscriptionRow('sub_up');

        $this->assertNotNull($row);
        $this->assertSame($growth->id, $row->subscription_plan_id, 'the updated price must re-resolve the plan');
        $this->assertSame(10, $row->seatLimit(), 'the seat allowance must follow the new plan');
        $this->assertSame(1, $this->subscriptionCount(), 'an upgrade updates the existing row — it never forks a second one');
    }

    public function test_downgrade_below_active_member_count_keeps_users_and_reports_over_limit(): void
    {
        $this->seedRoles();
        $tenant = $this->connectStripeCustomer('cus_down');

        // Three active members — each active user consumes one seat.
        $this->makeUser(RoleName::Admin);
        $this->makeUser(RoleName::Analyst);
        $this->makeUser(RoleName::CampaignManager);

        $big = SubscriptionPlan::factory()->seats(5)->create();
        $small = SubscriptionPlan::factory()->seats(2)->create();
        $t0 = now()->getTimestamp();

        $this->sync($this->stripeEvent(
            'customer.subscription.created',
            $this->stripeSubscriptionObject('sub_down', 'cus_down', (string) $big->stripe_price_id),
            created: $t0,
        ));

        $this->sync($this->stripeEvent(
            'customer.subscription.updated',
            $this->stripeSubscriptionObject('sub_down', 'cus_down', (string) $small->stripe_price_id),
            created: $t0 + 60,
        ));

        $row = $this->subscriptionRow('sub_down');

        // The downgrade APPLIES even though it strands seats — nothing is
        // ever auto-removed; enforcement happens at the next team mutation.
        $this->assertNotNull($row);
        $this->assertSame($small->id, $row->subscription_plan_id);
        $this->assertSame(2, $row->seatLimit());

        $limiter = app(SeatLimiter::class);

        $this->assertSame(3, $limiter->activeSeats((int) $tenant->id), 'a downgrade must never delete or deactivate member rows');

        config(['billing.enforced' => true]);

        $this->assertTrue($limiter->overLimit($tenant), 'with enforcement on, 3 active members on a 2-seat plan is over limit');
    }

    public function test_cancel_at_period_end_sets_flag_but_keeps_status_active(): void
    {
        $this->connectStripeCustomer('cus_cape');
        $plan = SubscriptionPlan::factory()->create();
        $t0 = now()->getTimestamp();

        $this->sync($this->stripeEvent(
            'customer.subscription.created',
            $this->stripeSubscriptionObject('sub_cape', 'cus_cape', (string) $plan->stripe_price_id),
            created: $t0,
        ));

        // User cancels on the Stripe portal: the subscription stays active
        // until period end — Stripe only flips cancel_at_period_end.
        $this->sync($this->stripeEvent(
            'customer.subscription.updated',
            $this->stripeSubscriptionObject('sub_cape', 'cus_cape', (string) $plan->stripe_price_id, 'active', [
                'cancel_at_period_end' => true,
            ]),
            created: $t0 + 60,
        ));

        $row = $this->subscriptionRow('sub_cape');

        $this->assertNotNull($row);
        $this->assertTrue($row->cancel_at_period_end);
        $this->assertSame(SubscriptionStatus::Active, $row->status, 'a scheduled cancellation must not terminate the row early');
        $this->assertNull($row->ended_at);
        $this->assertTrue($row->allowsProductAccess(), 'access is retained until the period actually ends');
    }

    public function test_deleted_event_terminates_the_subscription(): void
    {
        $tenant = $this->connectStripeCustomer('cus_del');
        $plan = SubscriptionPlan::factory()->create();
        $t0 = now()->getTimestamp();
        $endedAt = $t0 + 90;

        $this->sync($this->stripeEvent(
            'customer.subscription.created',
            $this->stripeSubscriptionObject('sub_del', 'cus_del', (string) $plan->stripe_price_id),
            created: $t0,
        ));

        $this->sync($this->stripeEvent(
            'customer.subscription.deleted',
            $this->stripeSubscriptionObject('sub_del', 'cus_del', (string) $plan->stripe_price_id, 'canceled', [
                'ended_at' => $endedAt,
            ]),
            created: $t0 + 120,
        ));

        $row = $this->subscriptionRow('sub_del');

        $this->assertNotNull($row);
        $this->assertSame(SubscriptionStatus::Canceled, $row->status);
        $this->assertSame($endedAt, $row->ended_at?->getTimestamp(), 'ended_at must come from the payload');
        $this->assertNull(TenantSubscription::liveFor((int) $tenant->id), 'a canceled subscription is terminal — no live row remains');
    }

    public function test_past_due_keeps_access_and_recovery_returns_to_active(): void
    {
        $this->connectStripeCustomer('cus_dunning');
        $plan = SubscriptionPlan::factory()->create();
        $t0 = now()->getTimestamp();

        $this->sync($this->stripeEvent(
            'customer.subscription.created',
            $this->stripeSubscriptionObject('sub_dun', 'cus_dunning', (string) $plan->stripe_price_id),
            created: $t0,
        ));

        // Renewal charge fails: Stripe moves the subscription to past_due.
        $this->sync($this->stripeEvent(
            'customer.subscription.updated',
            $this->stripeSubscriptionObject('sub_dun', 'cus_dunning', (string) $plan->stripe_price_id, 'past_due'),
            created: $t0 + 60,
        ));

        $row = $this->subscriptionRow('sub_dun');

        $this->assertNotNull($row);
        $this->assertSame(SubscriptionStatus::PastDue, $row->status);
        $this->assertTrue($row->allowsProductAccess(), 'PAST_DUE is the dunning grace window — never hard-lock mid-recovery');

        // Card updated, retry succeeds: Stripe flips it back to active.
        $this->sync($this->stripeEvent(
            'customer.subscription.updated',
            $this->stripeSubscriptionObject('sub_dun', 'cus_dunning', (string) $plan->stripe_price_id, 'active'),
            created: $t0 + 120,
        ));

        $this->assertSame(SubscriptionStatus::Active, $this->subscriptionRow('sub_dun')?->status);
    }

    public function test_out_of_order_event_older_than_watermark_is_skipped(): void
    {
        $this->connectStripeCustomer('cus_order');
        $planA = SubscriptionPlan::factory()->create();
        $planB = SubscriptionPlan::factory()->create();
        $t0 = now()->getTimestamp();

        $this->sync($this->stripeEvent(
            'customer.subscription.created',
            $this->stripeSubscriptionObject('sub_order', 'cus_order', (string) $planA->stripe_price_id),
            created: $t0,
        ));

        // A delayed, STALE event (created before the applied one) carrying a
        // different status AND price — nothing about it may roll state back.
        $this->sync($this->stripeEvent(
            'customer.subscription.updated',
            $this->stripeSubscriptionObject('sub_order', 'cus_order', (string) $planB->stripe_price_id, 'past_due'),
            created: $t0 - 100,
        ));

        $row = $this->subscriptionRow('sub_order');

        $this->assertNotNull($row);
        $this->assertSame(SubscriptionStatus::Active, $row->status, 'a stale event must not change status');
        $this->assertSame($planA->id, $row->subscription_plan_id, 'a stale event must not change the plan');
        $this->assertSame($t0, $row->last_stripe_event_at?->getTimestamp(), 'the watermark must not move backwards');
    }

    public function test_replaying_the_same_event_payload_is_idempotent(): void
    {
        $this->connectStripeCustomer('cus_replay');
        $plan = SubscriptionPlan::factory()->create();

        $event = $this->stripeEvent(
            'customer.subscription.created',
            $this->stripeSubscriptionObject('sub_replay', 'cus_replay', (string) $plan->stripe_price_id),
            created: now()->getTimestamp(),
        );

        $this->sync($event);

        $snapshot = $this->subscriptionRow('sub_replay')?->only([
            'id',
            'tenant_id',
            'subscription_plan_id',
            'status',
            'cancel_at_period_end',
            'current_period_ends_at',
            'ended_at',
            'last_stripe_event_at',
        ]);

        $this->assertNotNull($snapshot);

        // Stripe retries deliver the exact same payload; the sync must
        // converge to the same row (equal watermark still applies, harmlessly).
        $this->sync($event);

        $this->assertSame(1, $this->subscriptionCount(), 'a replay must never create a second row');
        $this->assertEquals($snapshot, $this->subscriptionRow('sub_replay')?->only(array_keys($snapshot)), 'a replay must leave the row state unchanged');
    }

    public function test_unknown_customer_is_acknowledged_without_creating_rows(): void
    {
        // A plan exists for the price, but NO tenant maps to this customer id
        // (foreign or stale customer — not retryable, must not throw).
        $plan = SubscriptionPlan::factory()->create();

        $this->sync($this->stripeEvent(
            'customer.subscription.created',
            $this->stripeSubscriptionObject('sub_ghost', 'cus_ghost', (string) $plan->stripe_price_id),
        ));

        $this->assertSame(0, $this->subscriptionCount(), 'an unmapped customer must never produce a subscription row');
    }

    public function test_unknown_price_on_a_new_subscription_is_ignored(): void
    {
        $this->connectStripeCustomer('cus_noprice');

        // No SubscriptionPlan carries this price — a NEW subscription cannot
        // be attached to a plan, so it is acknowledged and dropped (never guessed).
        $this->sync($this->stripeEvent(
            'customer.subscription.created',
            $this->stripeSubscriptionObject('sub_noprice', 'cus_noprice', 'price_unmapped'),
        ));

        $this->assertSame(0, $this->subscriptionCount());
    }

    public function test_unknown_price_on_an_existing_row_syncs_status_but_keeps_plan(): void
    {
        $this->connectStripeCustomer('cus_keep');
        $plan = SubscriptionPlan::factory()->create();
        $t0 = now()->getTimestamp();

        $this->sync($this->stripeEvent(
            'customer.subscription.created',
            $this->stripeSubscriptionObject('sub_keep', 'cus_keep', (string) $plan->stripe_price_id),
            created: $t0,
        ));

        // The price mapping broke (catalog drift) but the status transition
        // is still trustworthy — it must apply while the plan is preserved.
        $this->sync($this->stripeEvent(
            'customer.subscription.updated',
            $this->stripeSubscriptionObject('sub_keep', 'cus_keep', 'price_unmapped', 'past_due'),
            created: $t0 + 60,
        ));

        $row = $this->subscriptionRow('sub_keep');

        $this->assertNotNull($row);
        $this->assertSame(SubscriptionStatus::PastDue, $row->status, 'the status must still sync');
        $this->assertSame($plan->id, $row->subscription_plan_id, 'the existing plan must be kept, never guessed');
    }

    public function test_checkout_session_completed_fetches_the_subscription_and_creates_the_row(): void
    {
        $this->fakeStripeCredentials();

        $tenant = $this->connectStripeCustomer('cus_chk');
        $plan = SubscriptionPlan::factory()->create();

        // The session event carries only the subscription ID — the
        // synchronizer must fetch the canonical object from the API.
        Http::fake([
            'api.stripe.com/v1/subscriptions/*' => Http::response(
                $this->stripeSubscriptionObject('sub_chk', 'cus_chk', (string) $plan->stripe_price_id),
            ),
        ]);

        $this->sync($this->stripeEvent('checkout.session.completed', [
            'object' => 'checkout.session',
            'mode' => 'subscription',
            'customer' => 'cus_chk',
            'subscription' => 'sub_chk',
        ]));

        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
            && str_contains($request->url(), '/v1/subscriptions/sub_chk'));

        $row = $this->subscriptionRow('sub_chk');

        $this->assertNotNull($row, 'the row must be created from the fetched subscription object');
        $this->assertSame((int) $tenant->id, (int) $row->tenant_id);
        $this->assertSame($plan->id, $row->subscription_plan_id);
        $this->assertSame(SubscriptionStatus::Active, $row->status);
    }

    public function test_a_second_live_subscription_supersedes_the_first(): void
    {
        $tenant = $this->connectStripeCustomer('cus_twice');
        $plan = SubscriptionPlan::factory()->create();
        $t0 = now()->getTimestamp();

        $this->sync($this->stripeEvent(
            'customer.subscription.created',
            $this->stripeSubscriptionObject('sub_first', 'cus_twice', (string) $plan->stripe_price_id),
            created: $t0,
        ));

        // A second checkout (e.g. after a cancellation raced past us) mints
        // a NEW Stripe subscription id — the old live row must terminate so
        // the one-live-per-tenant invariant holds.
        $this->sync($this->stripeEvent(
            'customer.subscription.created',
            $this->stripeSubscriptionObject('sub_second', 'cus_twice', (string) $plan->stripe_price_id),
            created: $t0 + 300,
        ));

        $first = $this->subscriptionRow('sub_first');
        $second = $this->subscriptionRow('sub_second');

        $this->assertNotNull($first);
        $this->assertNotNull($second);
        $this->assertSame(SubscriptionStatus::Canceled, $first->status, 'the superseded row must be terminated');
        $this->assertNotNull($first->ended_at);
        $this->assertSame(SubscriptionStatus::Active, $second->status);
        $this->assertSame(2, $this->subscriptionCount(), 'terminal rows are kept as billing history');
        $this->assertSame(
            'sub_second',
            TenantSubscription::liveFor((int) $tenant->id)?->stripe_subscription_id,
            'exactly the new subscription must be the live one',
        );
    }

    public function test_invoice_payment_failed_is_audit_logged_for_the_resolved_tenant(): void
    {
        $tenant = $this->connectStripeCustomer('cus_fail');

        $this->sync($this->stripeEvent('invoice.payment_failed', [
            'id' => 'in_failed_1',
            'object' => 'invoice',
            'customer' => 'cus_fail',
            'attempt_count' => 2,
        ]));

        /** @var AuditLog|null $log */
        $log = AuditLog::query()->where('action', 'billing.payment_failed')->first();

        $this->assertNotNull($log, 'a failed invoice must leave a billing.payment_failed audit event');
        $this->assertSame((int) $tenant->id, (int) $log->tenant_id, 'the event must be stamped with the resolved tenant');
        $this->assertSame((int) $tenant->id, (int) $log->subject_id, 'the tenant is the audit subject');
        $this->assertSame('in_failed_1', $log->context['invoice'] ?? null, 'the invoice id (identifier only) is kept for the recovery trail');
        $this->assertSame(2, $log->context['attempt_count'] ?? null);
    }
}
