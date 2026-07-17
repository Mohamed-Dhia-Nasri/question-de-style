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

    /** Human-facing label (presentation only — same convention as RoleName). */
    public function label(): string
    {
        return match ($this) {
            self::Unverified => 'Unverified',
            self::AiAssessed => 'AI Assessed',
            self::HumanReviewed => 'Human Reviewed',
            self::HumanCorrected => 'Human Corrected',
            self::Confirmed => 'Confirmed',
        };
    }
}
