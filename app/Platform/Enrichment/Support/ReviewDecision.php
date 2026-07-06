<?php

namespace App\Platform\Enrichment\Support;

/**
 * A reviewer's decision on one AI output (DP-004). Internal operational
 * vocabulary — NOT a canonical ENUM-*. The verification lifecycle itself is
 * the canonical ENUM-VerificationStatus: APPROVE moves the envelope to
 * HUMAN_REVIEWED, CORRECT and REJECT move it to HUMAN_CORRECTED, and
 * UNRESOLVED leaves it at AI_ASSESSED (the item stays in the queue).
 */
enum ReviewDecision: string
{
    case Approve = 'APPROVE';
    case Correct = 'CORRECT';
    case Reject = 'REJECT';
    case Unresolved = 'UNRESOLVED';
}
