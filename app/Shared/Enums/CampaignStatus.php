<?php

namespace App\Shared\Enums;

/**
 * ENUM-CampaignStatus — canonical values:
 * docs/00-meta/03-glossary.md#enum-campaignstatus.
 */
enum CampaignStatus: string
{
    case Draft = 'DRAFT';
    case Planned = 'PLANNED';
    case Active = 'ACTIVE';
    case Paused = 'PAUSED';
    case Completed = 'COMPLETED';
    case Cancelled = 'CANCELLED';

    /** Human-facing label (presentation only — same convention as RoleName). */
    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Planned => 'Planned',
            self::Active => 'Active',
            self::Paused => 'Paused',
            self::Completed => 'Completed',
            self::Cancelled => 'Cancelled',
        };
    }
}
