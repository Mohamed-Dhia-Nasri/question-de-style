<?php

namespace App\Shared\Enums;

/**
 * ENUM-VerificationStatus — canonical values:
 * docs/00-meta/03-glossary.md#enum-verificationstatus.
 * The valid AI value is AI_ASSESSED (glossary note F9).
 */
enum VerificationStatus: string
{
    case Unverified = 'UNVERIFIED';
    case AiAssessed = 'AI_ASSESSED';
    case HumanReviewed = 'HUMAN_REVIEWED';
    case HumanCorrected = 'HUMAN_CORRECTED';
    case Confirmed = 'CONFIRMED';
}
