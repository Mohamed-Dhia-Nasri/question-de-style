<?php

namespace App\Shared\Enums;

/**
 * ENUM-MetricTier — canonical values: docs/00-meta/03-glossary.md#enum-metrictier.
 *
 * Rule (F7, DP-001): engagement rate, average performance, and median
 * performance are DERIVED, never PUBLIC. Estimated reach is ESTIMATED and is
 * never presented as fact.
 */
enum MetricTier: string
{
    case Public = 'PUBLIC';
    case Derived = 'DERIVED';
    case Estimated = 'ESTIMATED';
    case Confirmed = 'CONFIRMED';

    /** Human-facing label (presentation only — same convention as RoleName). */
    public function label(): string
    {
        return match ($this) {
            self::Public => 'From platform',
            self::Derived => 'Calculated',
            self::Estimated => 'Estimate',
            self::Confirmed => 'Entered by you',
        };
    }

    /** One-line plain-language description (presentation only). */
    public function description(): string
    {
        return match ($this) {
            self::Public => 'Reported directly by Instagram, TikTok, or YouTube.',
            self::Derived => 'Calculated by QDS from platform numbers (for example, likes + comments).',
            self::Estimated => 'A modelled estimate — an indication, not a fact.',
            self::Confirmed => 'Entered by your team (for example, spend or product value).',
        };
    }
}
