<?php

namespace App\Modules\Billing\Models;

use App\Shared\Enums\BillingInterval;
use Database\Factories\SubscriptionPlanFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * ENT-SubscriptionPlan (ADR-0021) — the commercial plan catalog:
 * docs/30-data-model/00-data-model.md#ent-subscriptionplan. Write-owner:
 * Billing module (config sync only — no UI write path).
 *
 * GLOBAL (deliberately NOT BelongsToTenant): plans are platform
 * configuration shared by every tenant, like the spatie role definitions.
 * Rows are upserted from config/billing.php by SubscriptionPlanSync; the
 * fillable list exists for that sync and the factory only.
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string|null $stripe_price_id
 * @property BillingInterval $billing_interval
 * @property int $max_seats
 * @property array<int|string, mixed> $features
 * @property bool $is_active
 */
class SubscriptionPlan extends Model
{
    /** @use HasFactory<SubscriptionPlanFactory> */
    use HasFactory;

    protected $fillable = [
        'code',
        'name',
        'stripe_price_id',
        'billing_interval',
        'max_seats',
        'features',
        'is_active',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'billing_interval' => BillingInterval::class,
            'max_seats' => 'integer',
            'features' => 'array',
            'is_active' => 'boolean',
        ];
    }

    /** @return HasMany<TenantSubscription, $this> */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(TenantSubscription::class);
    }

    /** A plan can be sold only when active AND wired to a Stripe price. */
    public function isPurchasable(): bool
    {
        return $this->is_active && $this->stripe_price_id !== null;
    }
}
