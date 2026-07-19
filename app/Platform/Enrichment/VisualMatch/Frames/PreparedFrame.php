<?php

namespace App\Platform\Enrichment\VisualMatch\Frames;

use App\Modules\Monitoring\Models\Keyframe;

/**
 * One frame that survived preparation and is worth embedding. A
 * representative of a near-duplicate group carries the group's size and
 * timestamp span — dedup reduces cost, never evidence (spec §8): the
 * BandMapper's support counting reads representedFrames, and the
 * visibility evidence reads the span.
 */
final readonly class PreparedFrame
{
    public function __construct(
        public Keyframe $keyframe,
        public string $bytes,
        public string $mimeType,
        /** 1 + the dedup-group members this frame represents. */
        public int $representedFrames,
        public ?int $spanStartMs,
        public ?int $spanEndMs,
    ) {}
}
