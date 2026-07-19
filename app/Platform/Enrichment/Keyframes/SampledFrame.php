<?php

namespace App\Platform\Enrichment\Keyframes;

/** One extracted frame temp file, before persistence (caller owns cleanup). */
final readonly class SampledFrame
{
    public function __construct(
        public string $tempPath,
        public int $timestampMs,
        public int $ordinal,
    ) {}
}
