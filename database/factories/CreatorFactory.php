<?php

namespace Database\Factories;

use App\Modules\CRM\Models\Creator;
use App\Shared\Enums\RelationshipStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Synthetic data only (DP-005) — never real creator identities.
 *
 * @extends Factory<Creator>
 */
class CreatorFactory extends Factory
{
    protected $model = Creator::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'display_name' => fake()->name(),
            'primary_language' => 'de',
            'relationship_status' => RelationshipStatus::Active,
        ];
    }
}
