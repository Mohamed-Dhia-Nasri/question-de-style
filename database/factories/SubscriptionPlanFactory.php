<?php

namespace Database\Factories;

use App\Modules\Billing\Models\SubscriptionPlan;
use App\Shared\Enums\BillingInterval;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SubscriptionPlan>
 */
class SubscriptionPlanFactory extends Factory
{
    protected $model = SubscriptionPlan::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'code' => strtoupper(fake()->unique()->lexify('PLAN_??????')),
            'name' => ucfirst(fake()->word()).' plan',
            'stripe_price_id' => 'price_'.fake()->unique()->lexify('????????????'),
            'billing_interval' => BillingInterval::Month,
            'max_seats' => 5,
            'features' => [],
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }

    public function seats(int $maxSeats): static
    {
        return $this->state(['max_seats' => $maxSeats]);
    }

    /** A catalogued plan not yet wired to a Stripe price (not purchasable). */
    public function withoutStripePrice(): static
    {
        return $this->state(['stripe_price_id' => null]);
    }
}
