<?php

namespace App\Shared\Enums;

/**
 * Per-candidate confidence band (sub-project C, spec §8). AUTO writes a
 * HIGH VISUAL_PRODUCT detection, REVIEW writes LOW (queues for humans),
 * REJECT writes nothing — scores stay in visual_match_candidates only.
 */
enum VisualMatchBand: string
{
    case Auto = 'auto';
    case Review = 'review';
    case Reject = 'reject';
}
