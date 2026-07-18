<?php

namespace App\Shared\ValueObjects;

use App\Shared\Enums\MetricTier;

/**
 * The MetricValue envelope — wraps any single quantitative metric so its
 * ENUM-MetricTier travels with the number (DP-001).
 *
 * Shape canonical in docs/30-data-model/00-data-model.md#envelopes. Never
 * hand-roll a bare metric number: a metric without a tier is a doctrine
 * violation, and an ESTIMATED value must never be presented as fact.
 */
final readonly class MetricValue
{
    public function __construct(
        public float $amount,
        public MetricTier $tier,
        /**
         * Optional metric label (e.g. 'followers', 'views', 'likes') so a
         * list of MetricValues remains attributable — needed to reconstruct
         * per-metric growth series from snapshots (AC-M1-021).
         * FLAGGED DEVIATION: the canonical MetricValue shape defines only
         * amount + tier; this optional label is a schema-level addition
         * awaiting a data-model doc amendment (same class as external_id).
         */
        public ?string $metric = null,
    ) {
        // Invariant: the amount must be a finite number. A non-finite value
        // (INF from an overflowing input like 1e400, or NaN) cannot be
        // JSON-encoded by the AsValueObject cast, so reject it loudly here
        // rather than letting it crash at persist time (M05).
        if (! is_finite($amount)) {
            throw new \InvalidArgumentException('MetricValue amount must be a finite number.');
        }
    }

    /** @return array{amount: float, tier: string, metric?: string} */
    public function toArray(): array
    {
        return [
            'amount' => $this->amount,
            'tier' => $this->tier->value,
            ...($this->metric !== null ? ['metric' => $this->metric] : []),
        ];
    }

    /** @param array{amount: float|int, tier: string, metric?: string|null} $data */
    public static function fromArray(array $data): self
    {
        return new self(
            amount: (float) $data['amount'],
            tier: MetricTier::from($data['tier']),
            metric: $data['metric'] ?? null,
        );
    }
}
