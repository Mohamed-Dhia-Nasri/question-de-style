<?php

namespace App\Shared\Enums;

/**
 * ENUM-MentionType — canonical values: docs/00-meta/03-glossary.md#enum-mentiontype.
 *
 * Rule: a mention is PAID or SEEDED only when a record/label proves it;
 * otherwise LIKELY_ORGANIC or UNKNOWN. There is deliberately no
 * CONFIRMED_ORGANIC value — organic is never asserted as fact (ADR-0008).
 */
enum MentionType: string
{
    case Paid = 'PAID';
    case Seeded = 'SEEDED';
    case LikelyOrganic = 'LIKELY_ORGANIC';
    case Unknown = 'UNKNOWN';
}
