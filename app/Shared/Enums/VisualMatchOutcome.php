<?php

namespace App\Shared\Enums;

/**
 * Run-level outcome of one visual product-match analysis (sub-project C,
 * spec §8). The no_match / inconclusive split is load-bearing: no_match =
 * "we looked properly and did not see it" (clean coverage); inconclusive =
 * "we could not look properly" (unavailable ≠ false) — sub-project D and
 * reviewers rely on the distinction. The skipped_* cases record runs that
 * never scored (budget / read-only / provider), never treated as absence.
 */
enum VisualMatchOutcome: string
{
    case Matched = 'matched';
    case Review = 'review';
    case NoMatch = 'no_match';
    case Inconclusive = 'inconclusive';
    case SkippedBudget = 'skipped_budget';
    case SkippedReadOnly = 'skipped_read_only';
    case SkippedProvider = 'skipped_provider';
}
