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

    /** One-line plain-language description (presentation only). */
    public function description(): string
    {
        return match ($this) {
            self::Draft => 'Being set up — not counted in results yet.',
            self::Planned => 'Ready to go — waiting for the start date.',
            self::Active => 'Running now — content and results are tracked.',
            self::Paused => 'On hold — nothing new starts.',
            self::Completed => 'Finished — results are final.',
            self::Cancelled => 'Called off — kept for the records.',
        };
    }

    /**
     * Lifecycle guard (M04). Completed and Cancelled are terminal — a
     * finished or called-off campaign is never revived — and nothing returns
     * to the setup-only Draft state once it has left it. Every other
     * forward/lateral move (and a no-op to the same status) is allowed.
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
