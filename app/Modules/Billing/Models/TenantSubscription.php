<?php

namespace App\Modules\Billing\Models;

use App\Shared\Enums\SubscriptionStatus;
use App\Shared\Tenancy\BelongsToTenant;
use Database\Factories\TenantSubscriptionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * ENT-TenantSubscription (ADR-0021) — a tenant's Stripe subscription:
 * docs/30-data-model/00-data-model.md#ent-tenantsubscription. Write-owner:
 * Billing module (webhook synchronizer only).
 *
 * Tenant-owned. A mirror of Stripe's canonical subscription object — the
 * webhook synchronizer is the ONLY writer; the app never transitions this
 * state itself (upgrades, cancellations and payment recovery all happen on
 * Stripe-hosted surfaces and arrive here as events). Terminal rows are kept
 * as billing history; tenant_subscriptions_one_live_index enforces at most
 * one live row per tenant.
 *
 * @property int $id
 * @property int|null $tenant_id
 * @property int $subscription_plan_id
 * @property string $stripe_subscription_id
 * @property SubscriptionStatus $status
 * @property int|null $seats_override
 * @property bool $cancel_at_period_end
 * @property Carbon|null $trial_ends_at
 * @property Carbon|null $current_period_ends_at
 * @property Carbon|null $ended_at
 * @property Carbon|null $last_stripe_event_at
 */
class TenantSubscription extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<TenantSubscriptionFactory> */
    use HasFactory;

    /**
     * Only the webhook synchronizer and the factory create rows; the
     * Stripe identifiers and lifecycle stamps still stay out of mass
     * assignment (forceFill from trusted payloads, the audit-log pattern).
     */
    protected $fillable = [
        'subscription_plan_id',
        'status',
        'seats_override',
        'cancel_at_period_end',
        'trial_ends_at',
        'current_period_ends_at',
        'ended_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => SubscriptionStatus::class,
            'seats_override' => 'integer',
            'cancel_at_period_end' => 'boolean',
            'trial_ends_at' => 'datetime',
            'current_period_ends_at' => 'datetime',
            'ended_at' => 'datetime',
            'last_stripe_event_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<SubscriptionPlan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class, 'subscription_plan_id');
    }

    /**
     * The tenant's live (non-terminal) subscription, or null. Explicitly
     * tenant-parameterised and unscoped so platform-context callers (the
     * webhook synchronizer, the enforcement middleware before any query
     * scope applies) resolve the same row an in-context query would.
     */
    public static function liveFor(int $tenantId): ?self
    {
        return self::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereNotIn('status', SubscriptionStatus::terminalValues())
            ->latest('id')
            ->first();
    }

    /** Effective seat allowance: bespoke override, else the plan's. */
    public function seatLimit(): int
    {
        return $this->seats_override ?? $this->plan->max_seats;
    }

    public function allowsProductAccess(): bool
    {
        return $this->status->allowsProductAccess();
    }
}
