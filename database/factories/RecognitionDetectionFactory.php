<?php

namespace Database\Factories;

use App\Modules\Monitoring\Models\ContentItem;
use App\Modules\Monitoring\Models\RecognitionDetection;
use App\Modules\Monitoring\Models\Story;
use App\Platform\Ingestion\SourceRegistry;
use App\Shared\Enums\ConfidenceLevel;
use App\Shared\Enums\RecognitionType;
use App\Shared\Enums\VerificationStatus;
use App\Shared\ValueObjects\ConfidenceAssessment;
use App\Shared\ValueObjects\Provenance;
use Carbon\CarbonImmutable;
use Database\Factories\Concerns\ResolvesTenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Synthetic data only (DP-005). Recognition comes from the Google AI
 * sources (REQ-M1-008); low-confidence detections route to review (DP-004).
 *
 * @extends Factory<RecognitionDetection>
 */
class RecognitionDetectionFactory extends Factory
{
    use ResolvesTenant;

    protected $model = RecognitionDetection::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $brand = fake()->company();

        return [
            'tenant_id' => fn () => $this->defaultTenantId(),
            'content_item_id' => ContentItem::factory(),
            'story_id' => null,
            'recognition_type' => RecognitionType::Logo,
            'detected_text' => null,
            'detected_brand' => $brand,
            'provider_label' => $brand,
            'assessment' => new ConfidenceAssessment(
                $brand,
                ConfidenceLevel::High,
                ['logo-match-score:0.94'],
                VerificationStatus::AiAssessed,
            ),
            'provenance' => new Provenance(
                SourceRegistry::GOOGLE_CLOUD_VISION,
                CarbonImmutable::now(),
                'test-fixture-v1',
            ),
        ];
    }

    /** Detection inside a story instead of a content item. */
    public function inStory(): static
    {
        return $this->state(fn (array $attributes) => [
            'content_item_id' => null,
            'story_id' => Story::factory(),
        ]);
    }

    /** Low-confidence detection — review-queue candidate (AC-M1-009). */
    public function lowConfidence(): static
    {
        return $this->state(fn (array $attributes) => [
            'assessment' => new ConfidenceAssessment(
                $attributes['detected_brand'] ?? 'unknown-brand',
                ConfidenceLevel::Low,
                ['logo-match-score:0.41'],
                VerificationStatus::AiAssessed,
            ),
        ]);
    }
}
