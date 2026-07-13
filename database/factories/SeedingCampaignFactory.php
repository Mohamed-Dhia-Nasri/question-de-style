<?php

namespace Database\Factories;

use App\Modules\CRM\Models\Brand;
use App\Modules\CRM\Models\Campaign;
use App\Modules\CRM\Models\Product;
use App\Modules\CRM\Models\SeedingCampaign;
use App\Shared\Enums\SeedingCampaignStatus;
use App\Shared\Enums\SeedingType;
use Database\Factories\Concerns\ResolvesTenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Synthetic data only (DP-005).
 *
 * @extends Factory<SeedingCampaign>
 */
class SeedingCampaignFactory extends Factory
{
    use ResolvesTenant;

    protected $model = SeedingCampaign::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => fn () => $this->defaultTenantId(),
            'campaign_id' => null,
            // High-cardinality unique name (same rationale as CampaignFactory).
            'name' => 'Seeding '.fake()->unique()->numerify('####-####'),
            'seeding_type' => SeedingType::Gifting,
            'brand_id' => Brand::factory(),
            'product_id' => null,
            'status' => SeedingCampaignStatus::Draft,
            'spend' => null,
        ];
    }

    public function ofType(SeedingType $type): static
    {
        return $this->state(fn (array $attributes) => ['seeding_type' => $type]);
    }

    public function forCampaign(?Campaign $campaign = null): static
    {
        return $this->state(fn (array $attributes) => [
            'campaign_id' => $campaign !== null ? $campaign->id : Campaign::factory(),
        ]);
    }

    public function withProduct(?Product $product = null): static
    {
        return $this->state(fn (array $attributes) => [
            'product_id' => $product !== null ? $product->id : Product::factory(),
        ]);
    }
}
