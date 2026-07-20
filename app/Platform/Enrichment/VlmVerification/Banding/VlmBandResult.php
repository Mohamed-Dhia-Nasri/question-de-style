<?php

namespace App\Platform\Enrichment\VlmVerification\Banding;

use App\Platform\Enrichment\VlmVerification\Verdicts\CandidateVerdict;
use App\Shared\Enums\VlmBand;

/**
 * One candidate verdict banded (spec §7): AUTO writes a HIGH VLM_PRODUCT
 * detection, REVIEW writes LOW (queues for humans), REJECT writes none.
 * rejectionReason names the decisive rule for REJECT rows and the
 * blocked-from-AUTO rule for REVIEW rows; captionEcho records that the §6
 * echo text-match held for this candidate (the AUTO→REVIEW cap fired only
 * when rejectionReason is 'caption-echo').
 */
final readonly class VlmBandResult
{
    public function __construct(
        public CandidateVerdict $verdict,
        public VlmBand $band,
        /** 'below-review-threshold'|'negative-claim'|'run-absent'|'margin-ambiguous'|'caption-echo'|'not-visible'|'no-frame-reference'|null */
        public ?string $rejectionReason,
        public bool $captionEcho,
    ) {}
}
