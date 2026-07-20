<?php

namespace App\Platform\Enrichment\VlmVerification\Verdicts;

/**
 * A validated, exactly-covering verdict set: one CandidateVerdict per
 * request candidate, normalized to the REQUEST's candidate (rank) order —
 * deterministic input for banding (VlmBandMapper) and persistence
 * (VlmRunRecorder). outcome is one of PRODUCT_CONFIRMED / PRODUCT_ABSENT /
 * INCONCLUSIVE — INCONCLUSIVE is first-class and never "product absent".
 */
final readonly class VerdictSet
{
    public function __construct(
        public string $outcome,
        /** @var list<CandidateVerdict> */
        public array $verdicts,
    ) {}
}
