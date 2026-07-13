<?php

namespace App\Shared\Enums;

/**
 * ENUM-SubscriptionStatus — canonical values:
 * docs/00-meta/03-glossary.md#enum-subscriptionstatus.
 *
 * Mirrors Stripe's canonical subscription states (ADR-0021) — no custom
 * lifecycle states are invented on top. Values are the house SCREAMING
 * convention; `fromStripe()` maps Stripe's lowercase wire values.
 */
enum SubscriptionStatus: string
{
    case Incomplete = 'INCOMPLETE';
    case IncompleteExpired = 'INCOMPLETE_EXPIRED';
    case Trialing = 'TRIALING';
    case Active = 'ACTIVE';
    case PastDue = 'PAST_DUE';
    case Canceled = 'CANCELED';
    case Unpaid = 'UNPAID';
    case Paused = 'PAUSED';

    /** Map a Stripe wire value ("past_due") to the canonical case. */
    public static function fromStripe(string $stripeStatus): self
    {
        return self::from(strtoupper($stripeStatus));
    }

    /**
     * Product access per state (ADR-0021): ACTIVE and TRIALING are fully
     * entitled; PAST_DUE keeps full access as the dunning grace window so
     * a failed renewal never hard-locks a paying customer mid-recovery.
     * Every other state blocks the product modules but never touches data
     * — the account/billing/team surfaces stay reachable for recovery.
     */
    public function allowsProductAccess(): bool
    {
        return match ($this) {
            self::Active, self::Trialing, self::PastDue => true,
            default => false,
        };
    }

    /**
     * Terminal states — the subscription can never leave them (Stripe
     * creates a NEW subscription instead). Non-terminal rows are "live":
     * at most one per tenant (tenant_subscriptions_one_live_index — keep
     * that partial index's WHERE list in sync with this set).
     *
     * @return list<self>
     */
    public static function terminal(): array
    {
        return [self::Canceled, self::IncompleteExpired];
    }

    /** @return list<string> */
    public static function terminalValues(): array
    {
        return array_column(self::terminal(), 'value');
    }

    public function label(): string
    {
        return match ($this) {
            self::Incomplete => 'Incomplete',
            self::IncompleteExpired => 'Incomplete (expired)',
            self::Trialing => 'Trial',
            self::Active => 'Active',
            self::PastDue => 'Past due',
            self::Canceled => 'Canceled',
            self::Unpaid => 'Unpaid',
            self::Paused => 'Paused',
        };
    }
}
