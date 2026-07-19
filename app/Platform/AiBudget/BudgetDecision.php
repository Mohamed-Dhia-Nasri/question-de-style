<?php

namespace App\Platform\AiBudget;

/**
 * Pre-spend budget verdict (spec §10). $reason is set only on deny —
 * e.g. 'tenant-daily-exhausted', 'global-hard-exhausted', 'read-only'.
 */
final readonly class BudgetDecision
{
    public function __construct(public bool $allowed, public ?string $reason = null) {}
}
