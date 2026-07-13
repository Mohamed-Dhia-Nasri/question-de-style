<?php

namespace Database\Factories;

use App\Modules\CRM\Models\Brand;
use App\Modules\CRM\Models\Client;
use App\Shared\Enums\SectorLabel;
use Database\Factories\Concerns\ResolvesTenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Synthetic data only (DP-005).
 *
 * @extends Factory<Brand>
 */
class BrandFactory extends Factory
{
    use ResolvesTenant;

    protected $model = Brand::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->company();

        return [
            'tenant_id' => fn () => $this->defaultTenantId(),
            'client_id' => Client::factory(),
            'name' => $name,
            'sector' => fake()->randomElement(SectorLabel::cases()),
            'aliases' => [mb_strtolower($name), '@'.mb_strtolower(str_replace(' ', '', $name))],
        ];
    }
}
