<?php

namespace App\Platform\Enrichment\VlmVerification\Verdicts;

/**
 * One validated per-candidate verdict (spec §6): grounded on a catalog
 * key (the productId comes from the request's candidate, never from model
 * text), with frame references already mapped to VALIDATED timestamps —
 * the model can only cite frames that were actually sent, so timestamps
 * can never be fabricated. A reference to a sent-but-unstamped frame
 * (carousel image, thumbnail) is valid but contributes no timestamp.
 */
final readonly class CandidateVerdict
{
    public function __construct(
        public string $productKey,
        public int $productId,
        public bool $visible,
        public bool $spoken,
        public bool $giftingCue,
        /** Rounded to 4 decimals — the numeric(5,4) verdict column. */
        public float $confidence,
        /** @var list<int> validated, deduped, ascending */
        public array $frameTimestampsMs,
        public string $rationale,
    ) {}
}
