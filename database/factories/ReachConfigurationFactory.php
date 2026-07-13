<?php

namespace Database\Factories;

use App\Modules\Monitoring\Models\ReachConfiguration;
use App\Platform\Enrichment\Support\ReachConfigurationStatus;
use Database\Factories\Concerns\ResolvesTenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/** Synthetic data only (DP-005). @extends Factory<ReachConfiguration> */
class ReachConfigurationFactory extends Factory
{
    use ResolvesTenant;

    protected $model = ReachConfiguration::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $version = fake()->unique()->numberBetween(1, 100000);

        return [
            'tenant_id' => fn () => $this->defaultTenantId(),
            'name' => 'Test reach model v'.$version,
            'method' => 'qds-estimated-reach',
            'formula_version' => 'reach-v'.$version,
            'params' => ['view_weight' => 0.7, 'follower_weight' => 0.1],
            'effective_from' => now()->subDay()->toDateString(),
            'status' => ReachConfigurationStatus::Draft,
            'notes' => 'Synthetic test configuration.',
            'assumptions' => ['fixture' => true],
        ];
    }

    public function active(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ReachConfigurationStatus::Active,
            'activated_at' => now(),
        ]);
    }
}
