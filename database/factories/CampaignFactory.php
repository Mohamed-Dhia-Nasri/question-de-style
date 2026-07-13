<?php

namespace Database\Factories;

use App\Modules\CRM\Models\Brand;
use App\Modules\CRM\Models\Campaign;
use App\Shared\Enums\CampaignStatus;
use Database\Factories\Concerns\ResolvesTenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Synthetic data only (DP-005).
 *
 * @extends Factory<Campaign>
 */
class CampaignFactory extends Factory
{
    use ResolvesTenant;

    protected $model = Campaign::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => fn () => $this->defaultTenantId(),
            // High-cardinality unique name: fake()->word() draws from a small
            // fixed Lorem list (~182 values) and overflows past that in a
            // single process (seeders, ->count(200)); numerify gives 10^8.
            'name' => 'Campaign '.fake()->unique()->numerify('####-####'),
            'brand_id' => Brand::factory(),
            'status' => CampaignStatus::Active,
            'start_at' => now()->subDays(14),
            'end_at' => now()->addDays(14),
            'spend' => null,
        ];
    }
}
