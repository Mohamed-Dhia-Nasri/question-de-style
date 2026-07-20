<?php

namespace App\Shared\Enums;

/**
 * Per-candidate VLM verdict band (sub-project D, spec §7). AUTO writes a
 * HIGH VLM_PRODUCT detection, REVIEW writes LOW (queues for humans),
 * REJECT writes nothing — the verdict stays in vlm_candidate_verdicts
 * only. Thresholds are explicitly-placeholder values sub-project E
 * calibrates.
 */
enum VlmBand: string
{
    case Auto = 'auto';
    case Review = 'review';
    case Reject = 'reject';
}
