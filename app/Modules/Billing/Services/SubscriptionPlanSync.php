<?php

namespace App\Modules\Billing\Services;

use App\Modules\Billing\Models\SubscriptionPlan;

/**
 * Idempotent config → DB plan-catalog sync (ADR-0021), the
 * RolePermissionSeeder pattern: config/billing.php 'plans' is the single
 * source; rows are upserted by their stable code. Plans absent from config
 * are DEACTIVATED (never deleted — historical subscriptions keep their FK).
 */
class SubscriptionPlanSync
{
    /** @return int number of plans synced */
    public function sync(): int
    {
        /** @var list<array{code: string, name: string, stripe_price_id: string|null, billing_interval: string, max_seats: int, features: array<int|string, mixed>, is_active: bool}> $plans */
        $plans = config('billing.plans', []);

        $codes = [];

        foreach ($plans as $plan) {
            $codes[] = $plan['code'];

            SubscriptionPlan::query()->updateOrCreate(
                ['code' => $plan['code']],
                [
                    'name' => $plan['name'],
                    'stripe_price_id' => $plan['stripe_price_id'],
                    'billing_interval' => $plan['billing_interval'],
                    'max_seats' => $plan['max_seats'],
                    'features' => $plan['features'],
                    'is_active' => $plan['is_active'],
                ],
            );
        }

        SubscriptionPlan::query()
            ->whereNotIn('code', $codes)
            ->update(['is_active' => false]);

        return count($codes);
    }
}
