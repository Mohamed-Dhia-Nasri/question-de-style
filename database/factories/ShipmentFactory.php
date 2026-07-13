<?php

namespace Database\Factories;

use App\Modules\CRM\Models\Creator;
use App\Modules\CRM\Models\Product;
use App\Modules\CRM\Models\SeedingCampaign;
use App\Modules\CRM\Models\Shipment;
use App\Shared\Enums\MetricTier;
use App\Shared\Enums\ShipmentStatus;
use App\Shared\ValueObjects\MetricValue;
use Database\Factories\Concerns\ResolvesTenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Synthetic data only (DP-005). `product_value_at_ship` is tier CONFIRMED
 * — the agency-known value of goods shipped (spec doctrine §4).
 *
 * @extends Factory<Shipment>
 */
class ShipmentFactory extends Factory
{
    use ResolvesTenant;

    protected $model = Shipment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => fn () => $this->defaultTenantId(),
            'seeding_campaign_id' => SeedingCampaign::factory(),
            'creator_id' => Creator::factory(),
            'status' => ShipmentStatus::Pending,
            'tracking_number' => null,
            'shipped_at' => null,
            'delivered_at' => null,
            'product_id' => Product::factory(),
            'quantity' => 1,
            'product_value_at_ship' => new MetricValue(fake()->randomFloat(2, 5, 500), MetricTier::Confirmed),
            'posting_required' => false,
            'posted' => false,
            'posted_at' => null,
        ];
    }

    public function delivered(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ShipmentStatus::Delivered,
            'tracking_number' => fake()->bothify('TRK-########'),
            'shipped_at' => now()->subDays(5),
            'delivered_at' => now()->subDays(2),
        ]);
    }
}
