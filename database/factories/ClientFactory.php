<?php

namespace Database\Factories;

use App\Modules\CRM\Models\Client;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Synthetic data only — factories and seeders must never contain real
 * personal data (DP-005 / personal-data governance).
 *
 * @extends Factory<Client>
 */
class ClientFactory extends Factory
{
    protected $model = Client::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'country' => fake()->randomElement(['DE', 'AT', 'CH']),
        ];
    }
}
