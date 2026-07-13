<?php

namespace App\Modules\Billing\Exceptions;

use RuntimeException;

/**
 * A seat-consuming mutation would break active_members <= seat_limit
 * (ADR-0021). Thrown inside SeatLimiter::reserve() while the tenant row
 * lock is held — the surrounding transaction rolls the mutation back, so
 * the invariant holds even under concurrent acceptance.
 */
class SeatLimitExceeded extends RuntimeException
{
    public function __construct(
        public readonly int $seatsUsed,
        public readonly int $seatLimit,
        public readonly bool $wasAlreadyOver,
    ) {
        parent::__construct(
            $wasAlreadyOver
                ? "The team is over its seat limit ({$seatsUsed} active members, {$seatLimit} seats) — reduce active members before making further team changes."
                : "No seat available: the plan allows {$seatLimit} seats and the team already has {$seatsUsed} active members."
        );
    }
}
