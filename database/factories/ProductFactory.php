<?php

namespace Database\Factories;

use App\Modules\CRM\Models\Brand;
use App\Modules\CRM\Models\Product;
use App\Shared\Enums\MetricTier;
use App\Shared\Enums\SectorLabel;
use App\Shared\ValueObjects\MetricValue;
use Database\Factories\Concerns\ResolvesTenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Synthetic data only (DP-005). `unit_value` is tier CONFIRMED — the
 * agency-known price (spec doctrine §4).
 *
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    use ResolvesTenant;

    protected $model = Product::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => fn () => $this->defaultTenantId(),
            'brand_id' => Brand::factory(),
            // High-cardinality unique name (same rationale as CampaignFactory).
            'name' => 'Product '.fake()->unique()->numerify('####-####'),
            'sku' => fake()->unique()->bothify('SKU-####-??'),
            'variant' => null,
            'unit_value' => new MetricValue(fake()->randomFloat(2, 5, 500), MetricTier::Confirmed),
            'category' => SectorLabel::Beauty,
        ];
    }
}
