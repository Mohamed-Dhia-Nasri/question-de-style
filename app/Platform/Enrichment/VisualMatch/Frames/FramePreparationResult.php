<?php

namespace App\Platform\Enrichment\VisualMatch\Frames;

/**
 * Outcome of local frame preparation with full coverage accounting —
 * a skipped frame is coverage LOSS ("unavailable ≠ false"), never treated
 * as absence of the product.
 */
final readonly class FramePreparationResult
{
    public function __construct(
        /** @var list<PreparedFrame> ordered by ordinal */
        public array $frames,
        public int $framesAvailable,
        public int $skippedFormat,
        public int $skippedQuality,
        public int $deduped,
    ) {}

    /**
     * Coverage may be insufficient: stored frames were skipped, or nothing
     * survived preparation. Drives the NO_MATCH vs INCONCLUSIVE run-outcome
     * split (spec §8). Budget truncation deliberately does NOT degrade
     * coverage — it is a cost guard.
     */
    public function coverageDegraded(): bool
    {
        return $this->skippedFormat + $this->skippedQuality > 0 || $this->frames === [];
    }
}
