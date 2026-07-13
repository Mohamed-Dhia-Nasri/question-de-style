<?php

namespace Database\Factories;

use App\Modules\CRM\Models\Creator;
use App\Modules\Discovery\Models\GeoAttribution;
use App\Shared\Enums\ConfidenceLevel;
use App\Shared\Enums\VerificationStatus;
use App\Shared\ValueObjects\ConfidenceAssessment;
use Database\Factories\Concerns\ResolvesTenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<GeoAttribution> */
class GeoAttributionFactory extends Factory
{
    use ResolvesTenant;

    protected $model = GeoAttribution::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => fn () => $this->defaultTenantId(),
            'creator_id' => Creator::factory(),
            'country_code' => 'DE',
            'region' => 'Bavaria',
            'city' => 'Munich',
            // Operator entry: a human assertion, not an inference (ADR-0018).
            'assessment' => new ConfidenceAssessment(
                'DE',
                ConfidenceLevel::High,
                ['operator-entry'],
                VerificationStatus::HumanReviewed,
            ),
        ];
    }
}
