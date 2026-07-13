<?php

namespace Database\Factories;

use App\Modules\Billing\Models\SubscriptionPlan;
use App\Modules\Billing\Models\TenantSubscription;
use App\Shared\Enums\SubscriptionStatus;
use Database\Factories\Concerns\ResolvesTenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TenantSubscription>
 */
class TenantSubscriptionFactory extends Factory
{
    use ResolvesTenant;

    protected $model = TenantSubscription::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'tenant_id' => fn () => $this->defaultTenantId(),
            'subscription_plan_id' => SubscriptionPlan::factory(),
            'stripe_subscription_id' => 'sub_'.fake()->unique()->lexify('????????????????'),
            'status' => SubscriptionStatus::Active,
            'seats_override' => null,
            'cancel_at_period_end' => false,
            'trial_ends_at' => null,
            'current_period_ends_at' => now()->addMonth(),
            'ended_at' => null,
            'last_stripe_event_at' => null,
        ];
    }

    public function status(SubscriptionStatus $status): static
    {
        return $this->state(['status' => $status]);
    }

    public function trialing(): static
    {
        return $this->state([
            'status' => SubscriptionStatus::Trialing,
            'trial_ends_at' => now()->addDays(14),
        ]);
    }

    public function pastDue(): static
    {
        return $this->state(['status' => SubscriptionStatus::PastDue]);
    }

    public function canceled(): static
    {
        return $this->state([
            'status' => SubscriptionStatus::Canceled,
            'ended_at' => now()->subDay(),
        ]);
    }

    public function seatsOverride(int $seats): static
    {
        return $this->state(['seats_override' => $seats]);
    }
}
