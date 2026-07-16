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

    /** One-line plain-language description (presentation only). */
    public function description(): string
    {
        return match ($this) {
            self::Draft => 'Being set up — add creators and a product first.',
            self::Planned => 'Ready — creators picked, nothing sent yet.',
            self::Active => 'Running — outreach and preparation in progress.',
            self::Shipping => 'Products are on their way to creators.',
            self::Completed => 'Finished — results are final.',
            self::Cancelled => 'Called off — kept for the records.',
        };
    }
}
