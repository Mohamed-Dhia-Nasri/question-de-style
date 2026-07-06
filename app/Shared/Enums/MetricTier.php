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
}
