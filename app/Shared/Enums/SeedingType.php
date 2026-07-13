<?php

namespace App\Shared\Enums;

/**
 * The four seeding variants (docs/50-modules/module-3-crm-seeding.md §2.5,
 * REQ-M3-006 / AC-M3-010): gifting, gifting-with-post, paid+product, organic.
 *
 * FLAGGED DEVIATION (D1, reviews/SPEC-module3-step1-data-foundation.md): the
 * variants are canonical prose in module-3 §2.5 but not yet a glossary
 * `ENUM-*`; these tokens were confirmed by the product owner (2026-07-05)
 * and await a glossary `ENUM-SeedingType` amendment. The organic variant
 * never justifies asserting a Mention as PAID/SEEDED by itself (AC-M3-011).
 */
enum SeedingType: string
{
    case Gifting = 'GIFTING';
    case GiftingWithPost = 'GIFTING_WITH_POST';
    case PaidPlusProduct = 'PAID_PLUS_PRODUCT';
    case Organic = 'ORGANIC';

    /** Human-facing label (presentation only — same convention as RoleName). */
    public function label(): string
    {
        return match ($this) {
            self::Gifting => 'Gifting',
            self::GiftingWithPost => 'Gifting with post',
            self::PaidPlusProduct => 'Paid + product',
            self::Organic => 'Organic',
        };
    }
}
