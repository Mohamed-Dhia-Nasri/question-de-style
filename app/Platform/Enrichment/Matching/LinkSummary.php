<?php

namespace App\Platform\Enrichment\Matching;

/** One SeededContentLinker run's outcome, for command output/telemetry. */
final readonly class LinkSummary
{
    public function __construct(
        public int $linked,
        public int $alreadyLinked,
        public int $staleReferences,
        public int $withoutReferences,
        public int $campaignsConfirmed,
    ) {}
}
