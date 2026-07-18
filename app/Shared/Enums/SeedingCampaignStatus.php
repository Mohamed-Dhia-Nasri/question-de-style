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

    /**
     * Lifecycle guard, symmetric to CampaignStatus (M04 twin). Completed and
     * Cancelled are terminal — a finished or called-off run is never revived —
     * and nothing returns to the setup-only Draft state once it has left it.
     * Every other forward/lateral move (and a no-op) is allowed.
     */
    public function canTransitionTo(self $to): bool
    {
        if ($to === $this) {
            return true;
        }

        if ($this === self::Completed || $this === self::Cancelled) {
            return false;
        }

        return $to !== self::Draft;
    }
}
