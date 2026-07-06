<?php

namespace Database\Factories;

use App\Modules\CRM\Models\Creator;
use App\Modules\CRM\Models\DocumentAttachment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Synthetic data only (DP-005). creator_id/campaign_id are both nullable
 * per canonical shape; the default state attaches to a creator so the row
 * is anchored either way.
 *
 * @extends Factory<DocumentAttachment>
 */
class DocumentAttachmentFactory extends Factory
{
    protected $model = DocumentAttachment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'creator_id' => Creator::factory(),
            'campaign_id' => null,
            'file_name' => fake()->word().'.pdf',
            'storage_url' => fake()->url(),
            'uploaded_at' => now(),
        ];
    }
}
