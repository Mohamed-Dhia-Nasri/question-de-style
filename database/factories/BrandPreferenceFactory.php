<?php

namespace Database\Factories;

use App\Modules\CRM\Models\BrandPreference;
use App\Modules\CRM\Models\Creator;
use Database\Factories\Concerns\ResolvesTenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Synthetic data only (DP-005). Preferred/restricted brands are lists of
 * STRING per the canonical ENT-BrandPreference shape — not brand FKs.
 *
 * @extends Factory<BrandPreference>
 */
class BrandPreferenceFactory extends Factory
{
    use ResolvesTenant;

    protected $model = BrandPreference::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => fn () => $this->defaultTenantId(),
            'creator_id' => Creator::factory(),
            'preferred_brands' => [fake()->company(), fake()->company()],
            'restricted_brands' => [fake()->company()],
            'notes' => fake()->sentence(),
        ];
    }
}
