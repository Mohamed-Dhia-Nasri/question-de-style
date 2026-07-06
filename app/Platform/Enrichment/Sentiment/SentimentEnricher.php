<?php

namespace App\Platform\Enrichment\Sentiment;

use App\Modules\Monitoring\Models\ContentItem;
use App\Modules\Monitoring\Models\SentimentAnalysis;
use App\Platform\Enrichment\Contracts\SentimentClassifier;
use App\Platform\Enrichment\Support\ConfidenceScore;
use App\Platform\Enrichment\Support\HumanPrecedence;
use App\Shared\Enums\ConfidenceLevel;
use App\Shared\Enums\SentimentLabel;
use App\Shared\Enums\VerificationStatus;
use App\Shared\ValueObjects\ConfidenceAssessment;

/**
 * Sentiment stage (REQ-M1-009): analyzes the ContentItem CAPTION (and a
 * transcript when one is supplied in-flight by recognition). Comments are
 * NEVER analyzed — REQ-M1-010 is deferred (DEF-005/ADR-0009), so this
 * stage only ever writes content-linked SentimentAnalysis rows and the
 * comment_id column stays unused.
 *
 * Ambiguous (MIXED/UNKNOWN) or low-confidence results route to review via
 * the envelope (DP-004). A human-reviewed/corrected analysis is never
 * overwritten by a re-run.
 */
class SentimentEnricher
{
    public function __construct(private readonly SentimentClassifier $classifier) {}

    /**
     * @return string stage outcome: 'completed' | 'unavailable' | 'no-input' | 'human-precedence'
     */
    public function enrich(ContentItem $contentItem, ?string $transcript = null): string
    {
        $text = trim(implode("\n\n", array_filter([
            $contentItem->caption,
            $transcript,
        ], static fn (?string $part): bool => is_string($part) && trim($part) !== '')));

        if ($text === '') {
            return 'no-input';
        }

        $existing = SentimentAnalysis::query()
            ->where('content_item_id', $contentItem->id)
            ->first();

        if ($existing !== null && ! HumanPrecedence::allowsAiUpdate($existing->assessment)) {
            return 'human-precedence';
        }

        $prediction = $this->classifier->classify($text);

        if ($prediction === null) {
            // No canonical sentiment model is decided — stays unavailable.
            return 'unavailable';
        }

        $level = $prediction->score !== null
            ? ConfidenceScore::toLevel($prediction->score)
            : ConfidenceLevel::Unknown;

        // Ambiguity is uncertainty: MIXED/UNKNOWN labels never carry more
        // than LOW confidence, so they always route to review (DP-004).
        if (in_array($prediction->label, [SentimentLabel::Mixed, SentimentLabel::Unknown], true)
            && in_array($level, [ConfidenceLevel::High, ConfidenceLevel::Medium], true)) {
            $level = ConfidenceLevel::Low;
        }

        $analysis = $existing ?? new SentimentAnalysis(['content_item_id' => $contentItem->id]);

        $analysis->fill([
            'label' => $prediction->label,
            'context_summary' => $prediction->contextSummary,
            'assessment' => new ConfidenceAssessment(
                value: $prediction->label->value,
                confidenceLevel: $level,
                signals: $prediction->signals !== [] ? $prediction->signals : ['caption-tone'],
                verificationStatus: VerificationStatus::AiAssessed,
            ),
        ]);

        $analysis->save();

        return 'completed';
    }
}
