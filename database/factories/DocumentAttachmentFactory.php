<?php

namespace Database\Factories;

use App\Modules\CRM\Models\Creator;
use App\Modules\CRM\Models\DocumentAttachment;
use Database\Factories\Concerns\ResolvesTenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Synthetic data only (DP-005). creator_id/campaign_id/seeding_campaign_id
 * are all nullable (seeding anchor: spec D6); the default state attaches to
 * a creator so the row is anchored either way.
 *
 * @extends Factory<DocumentAttachment>
 */
class DocumentAttachmentFactory extends Factory
{
    use ResolvesTenant;

    protected $model = DocumentAttachment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => fn () => $this->defaultTenantId(),
            'creator_id' => Creator::factory(),
            'campaign_id' => null,
            'seeding_campaign_id' => null,
            'file_name' => fake()->word().'.pdf',
            'storage_url' => fake()->url(),
            'uploaded_at' => now(),
        ];
    }
}
