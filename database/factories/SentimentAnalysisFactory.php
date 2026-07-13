<?php

namespace Database\Factories;

use App\Modules\Monitoring\Models\ContentItem;
use App\Modules\Monitoring\Models\SentimentAnalysis;
use App\Shared\Enums\ConfidenceLevel;
use App\Shared\Enums\SentimentLabel;
use App\Shared\Enums\VerificationStatus;
use App\Shared\ValueObjects\ConfidenceAssessment;
use Database\Factories\Concerns\ResolvesTenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Synthetic data only (DP-005). v1 sentiment runs on captions/transcripts
 * only (REQ-M1-009); comment sentiment is deferred (DEF-005), so the
 * default subject is always a content item.
 *
 * @extends Factory<SentimentAnalysis>
 */
class SentimentAnalysisFactory extends Factory
{
    use ResolvesTenant;

    protected $model = SentimentAnalysis::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $label = fake()->randomElement([SentimentLabel::Positive, SentimentLabel::Neutral, SentimentLabel::Negative]);

        return [
            'tenant_id' => fn () => $this->defaultTenantId(),
            'content_item_id' => ContentItem::factory(),
            'comment_id' => null,
            'label' => $label,
            'context_summary' => fake()->sentence(),
            'assessment' => new ConfidenceAssessment(
                $label->value,
                ConfidenceLevel::Medium,
                ['caption-tone'],
                VerificationStatus::AiAssessed,
            ),
        ];
    }
}
