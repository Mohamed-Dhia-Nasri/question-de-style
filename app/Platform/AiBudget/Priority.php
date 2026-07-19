<?php

namespace App\Platform\AiBudget;

/**
 * Budget priority tier (spec §7/§10): High = active-campaign work and
 * user-triggered photo embeds — ignores tenant soft caps and global soft
 * budgets, stops only at the global HARD caps, read-only mode, or the
 * provider breaker (the breaker consult lives in the matcher). Medium =
 * shipment outside an active campaign — stops at ANY exhausted budget.
 * Low never reaches the guard: an empty candidate set is already skipped.
 */
enum Priority: string
{
    case High = 'high';
    case Medium = 'medium';
}
