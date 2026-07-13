<?php

namespace App\Shared\Enums;

/**
 * ENUM-BillingInterval — canonical values:
 * docs/00-meta/03-glossary.md#enum-billinginterval.
 *
 * The billing cadence a subscription plan renews on (ADR-0021). Matches
 * Stripe's price `recurring.interval` vocabulary (month/year) — informational
 * on the plan catalog; the authoritative renewal schedule lives in Stripe.
 */
enum BillingInterval: string
{
    case Month = 'MONTH';
    case Year = 'YEAR';

    public function label(): string
    {
        return match ($this) {
            self::Month => 'Monthly',
            self::Year => 'Yearly',
        };
    }
}
