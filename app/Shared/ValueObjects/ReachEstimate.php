<?php

namespace App\Shared\ValueObjects;

use App\Shared\Enums\MetricTier;
use InvalidArgumentException;

/**
 * The ReachEstimate envelope — reach is never a plain public count.
 *
 * Shape canonical in docs/30-data-model/00-data-model.md#envelopes. The tier
 * is constrained to ESTIMATED or CONFIRMED (PUBLIC/DERIVED are invalid for
 * reach), and the estimation method must always be stated (transparency
 * requirement). CONFIRMED reach is deferred in v1 (DEF-003) — its surfaces
 * render "unavailable".
 */
final readonly class ReachEstimate
{
    public function __construct(
        public float $amount,
        public MetricTier $tier,
        /** The estimation method/model that produced the amount. */
        public string $method,
    ) {
        if (! in_array($this->tier, [MetricTier::Estimated, MetricTier::Confirmed], true)) {
            throw new InvalidArgumentException(
                "ReachEstimate tier must be ESTIMATED or CONFIRMED, got [{$this->tier->value}] "
                .'— PUBLIC/DERIVED are invalid for reach (data model, DP-001).'
            );
        }

        if ($this->method === '') {
            throw new InvalidArgumentException('ReachEstimate.method must state the estimation method (DP-001).');
        }
    }

    /** @return array{amount: float, tier: string, method: string} */
    public function toArray(): array
    {
        return [
            'amount' => $this->amount,
            'tier' => $this->tier->value,
            'method' => $this->method,
        ];
    }

    /** @param array{amount: float|int, tier: string, method: string} $data */
    public static function fromArray(array $data): self
    {
        return new self(
            amount: (float) $data['amount'],
            tier: MetricTier::from($data['tier']),
            method: $data['method'],
        );
    }
}
