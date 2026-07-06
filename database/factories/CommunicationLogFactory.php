<?php

namespace Database\Factories;

use App\Modules\CRM\Models\CommunicationLog;
use App\Modules\CRM\Models\Creator;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Synthetic data only (DP-005). Channel/direction are canonical string
 * fields (no glossary enum).
 *
 * @extends Factory<CommunicationLog>
 */
class CommunicationLogFactory extends Factory
{
    protected $model = CommunicationLog::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'creator_id' => Creator::factory(),
            'campaign_id' => null,
            'channel' => 'email',
            'direction' => 'outbound',
            'summary' => fake()->sentence(),
            'occurred_at' => now()->subDay(),
        ];
    }
}
