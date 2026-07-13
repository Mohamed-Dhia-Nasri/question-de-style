<?php

namespace Database\Factories;

use App\Modules\Monitoring\Models\EmvConfiguration;
use App\Platform\Enrichment\Emv\EmvConfigurationValidator;
use App\Platform\Enrichment\Support\EmvConfigurationStatus;
use Database\Factories\Concerns\ResolvesTenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Synthetic data only (DP-005). The rates here are TEST FIXTURES, not
 * defaults — production EMV stays unavailable until an authorized user
 * authors and activates a real configuration (REQ-M1-011).
 *
 * @extends Factory<EmvConfiguration>
 */
class EmvConfigurationFactory extends Factory
{
    use ResolvesTenant;

    protected $model = EmvConfiguration::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $version = fake()->unique()->numberBetween(1, 100000);

        return [
            'tenant_id' => fn () => $this->defaultTenantId(),
            'name' => 'Test EMV model v'.$version,
            'formula_version' => 'formula-v'.$version,
            'rate_card_version' => 'rates-v'.$version,
            'currency' => 'EUR',
            'formula' => [
                'model' => EmvConfigurationValidator::MODEL_RATE_CARD_SUM,
                'metrics' => ['views', 'likes', 'comments'],
            ],
            'rates' => [
                'default' => ['views' => 0.01, 'likes' => 0.05, 'comments' => 0.2],
            ],
            'effective_from' => now()->subDay()->toDateString(),
            'status' => EmvConfigurationStatus::Draft,
            'notes' => 'Synthetic test configuration.',
            'assumptions' => ['fixture' => true],
        ];
    }

    public function active(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => EmvConfigurationStatus::Active,
            'activated_at' => now(),
        ]);
    }
}
