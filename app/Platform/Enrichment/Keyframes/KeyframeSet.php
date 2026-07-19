<?php

namespace App\Platform\Enrichment\Keyframes;

use App\Modules\Monitoring\Models\Keyframe;

/**
 * The frame set tiers C/D consume for one owner — the swappable contract:
 * neither tier touches the keyframes table directly.
 */
final readonly class KeyframeSet
{
    public function __construct(
        /** @var list<Keyframe> ordered by ordinal */
        public array $frames,
        /** 'extracted' | 'empty' (run-level skip detail lives on EnrichmentRun.stages) */
        public string $status,
    ) {}

    public function isEmpty(): bool
    {
        return $this->frames === [];
    }
}
