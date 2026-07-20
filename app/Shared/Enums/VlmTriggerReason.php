<?php

namespace App\Shared\Enums;

/**
 * Why a vlm_verification_runs row exists (sub-project D, spec §4/§8.1).
 * review-band / no-band-shipment mirror C's needs_verification semantics
 * (the inline stage's fresh path); sweep-catchup marks rows the daily
 * qds:vlm-verify sweep dispatched; the unverifiable:* reasons mark
 * DEF-021 discovery rows — shipped, in-window posts whose visual outcome
 * is missing (no C run at all) or skipped (skipped_* latest run),
 * recorded as "we could not look" and never sent to Gemini.
 */
enum VlmTriggerReason: string
{
    case ReviewBand = 'review-band';
    case NoBandShipment = 'no-band-shipment';
    case SweepCatchup = 'sweep-catchup';
    case UnverifiableNoRun = 'unverifiable:no-run';
    case UnverifiableSkippedRun = 'unverifiable:skipped-run';
}
