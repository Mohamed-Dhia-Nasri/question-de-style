<?php

namespace Database\Factories;

use App\Modules\Monitoring\Models\ContentItem;
use App\Modules\Monitoring\Models\Mention;
use App\Modules\Monitoring\Models\MonitoredSubject;
use App\Modules\Monitoring\Models\Story;
use App\Platform\Ingestion\SourceRegistry;
use App\Shared\Enums\ConfidenceLevel;
use App\Shared\Enums\MentionType;
use App\Shared\Enums\VerificationStatus;
use App\Shared\ValueObjects\ConfidenceAssessment;
use App\Shared\ValueObjects\Provenance;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Synthetic data only (DP-005). Default classification follows the
 * ENUM-MentionType rule: without a proving record the type is
 * LIKELY_ORGANIC/UNKNOWN, AI-assessed (never asserted as fact — DP-003,
 * AC-M1-002). Use seeded()/paid() to model a proving record (AC-M1-003).
 *
 * @extends Factory<Mention>
 */
class MentionFactory extends Factory
{
    protected $model = Mention::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $type = fake()->randomElement([MentionType::LikelyOrganic, MentionType::Unknown]);

        return [
            'monitored_subject_id' => MonitoredSubject::factory(),
            'content_item_id' => ContentItem::factory(),
            'story_id' => null,
            'campaign_id' => null,
            'mention_type' => $type,
            'classification' => new ConfidenceAssessment(
                $type->value,
                ConfidenceLevel::Medium,
                ['no-disclosure-label', 'no-seeding-record'],
                VerificationStatus::AiAssessed,
            ),
            'provenance' => new Provenance(
                SourceRegistry::APIFY_INSTAGRAM_SCRAPER,
                CarbonImmutable::now(),
                'test-fixture-v1',
            ),
        ];
    }

    /** SEEDED with its proving record in the signals (AC-M1-003). */
    public function seeded(): static
    {
        return $this->state(fn (array $attributes) => [
            'mention_type' => MentionType::Seeded,
            'classification' => new ConfidenceAssessment(
                MentionType::Seeded->value,
                ConfidenceLevel::High,
                ['shipment-record:synthetic-1'],
                VerificationStatus::AiAssessed,
            ),
        ]);
    }

    /** PAID with its proving label in the signals (AC-M1-003). */
    public function paid(): static
    {
        return $this->state(fn (array $attributes) => [
            'mention_type' => MentionType::Paid,
            'classification' => new ConfidenceAssessment(
                MentionType::Paid->value,
                ConfidenceLevel::High,
                ['platform-paid-partnership-label'],
                VerificationStatus::AiAssessed,
            ),
        ]);
    }

    /** Mention detected in a story instead of a content item. */
    public function inStory(): static
    {
        return $this->state(fn (array $attributes) => [
            'content_item_id' => null,
            'story_id' => Story::factory(),
        ]);
    }

    /** Low-confidence AI output — belongs in the review queue (DP-004). */
    public function lowConfidence(): static
    {
        return $this->state(fn (array $attributes) => [
            'mention_type' => MentionType::Unknown,
            'classification' => new ConfidenceAssessment(
                MentionType::Unknown->value,
                ConfidenceLevel::Low,
                ['weak-signal'],
                VerificationStatus::AiAssessed,
            ),
        ]);
    }
}
