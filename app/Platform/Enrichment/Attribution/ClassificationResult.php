<?php

namespace App\Platform\Enrichment\Attribution;

use App\Shared\Enums\ConfidenceLevel;
use App\Shared\Enums\MentionType;

/**
 * Outcome of classifying one evidence bundle: the mention type, the
 * confidence level, and the full signal list that justifies it (DP-003 —
 * the signals feed the human-review loop and future rules).
 */
final readonly class ClassificationResult
{
    public function __construct(
        public MentionType $mentionType,
        public ConfidenceLevel $confidenceLevel,
        /** @var list<string> */
        public array $signals,
    ) {}
}
