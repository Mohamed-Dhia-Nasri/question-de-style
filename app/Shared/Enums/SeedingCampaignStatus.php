<?php

namespace App\Shared\Enums;

/**
 * ENUM-SeedingCampaignStatus — canonical values:
 * docs/00-meta/03-glossary.md#enum-seedingcampaignstatus.
 */
enum SeedingCampaignStatus: string
{
    case Draft = 'DRAFT';
    case Planned = 'PLANNED';
    case Active = 'ACTIVE';
    case Shipping = 'SHIPPING';
    case Completed = 'COMPLETED';
    case Cancelled = 'CANCELLED';

    /** Human-facing label (presentation only — same convention as RoleName). */
    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Planned => 'Planned',
            self::Active => 'Active',
            self::Shipping => 'Shipping',
            self::Completed => 'Completed',
            self::Cancelled => 'Cancelled',
        };
    }
}
