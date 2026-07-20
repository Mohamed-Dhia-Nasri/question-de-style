<?php

namespace App\Platform\Enrichment\VisualMatch\Matching;

/**
 * The best reference-photo similarity of ONE prepared frame against ONE
 * candidate product (sub-project C). timestampMs/representedFrames carry
 * the frame's dedup-group evidence forward into BandMapper's support
 * counting and visibility estimation.
 */
final readonly class FrameScore
{
    public function __construct(
        public int $keyframeId,
        public int $ordinal,
        public ?int $timestampMs,
        public float $similarity,
        public int $photoId,
        public int $representedFrames,
    ) {}
}
