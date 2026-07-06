<?php

namespace App\Platform\Enrichment\Sentiment;

use App\Shared\Enums\SentimentLabel;

/**
 * One sentiment classification produced by a SentimentClassifier: a
 * canonical ENUM-SentimentLabel, an optional numeric confidence, the
 * contributing signals (DP-003), and an optional short context rationale.
 */
final readonly class SentimentPrediction
{
    public function __construct(
        public SentimentLabel $label,
        public ?float $score,
        /** @var list<string> */
        public array $signals,
        public ?string $contextSummary = null,
    ) {}
}
