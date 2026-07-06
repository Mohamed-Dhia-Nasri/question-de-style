<?php

namespace App\Shared\ValueObjects;

use App\Platform\Ingestion\SourceRegistry;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * The Provenance envelope — mandatory on every externally-sourced record.
 *
 * Shape canonical in docs/30-data-model/00-data-model.md#envelopes; doctrine
 * in DP-002 / ADR-0008. Embedded value object: persisted as a JSON column on
 * the owning entity, never as a standalone table.
 */
final readonly class Provenance
{
    public function __construct(
        /** Exact provider contract id (SRC-*) from the closed registry. */
        public string $source,
        public CarbonImmutable $fetchedAt,
        /** Actor/API version or dataset revision, for reproducibility. */
        public string $sourceVersion,
    ) {
        if (! SourceRegistry::isRegistered($this->source)) {
            throw new InvalidArgumentException(
                "Provenance source [{$this->source}] is not a registered SRC-* id. "
                .'The provider stack is frozen (ADR-0001 / DP-006).'
            );
        }

        if ($this->sourceVersion === '') {
            throw new InvalidArgumentException('Provenance sourceVersion must not be empty (DP-002).');
        }
    }

    /** @return array{source: string, fetchedAt: string, sourceVersion: string} */
    public function toArray(): array
    {
        return [
            'source' => $this->source,
            'fetchedAt' => $this->fetchedAt->toIso8601String(),
            'sourceVersion' => $this->sourceVersion,
        ];
    }

    /** @param array{source: string, fetchedAt: string, sourceVersion: string} $data */
    public static function fromArray(array $data): self
    {
        return new self(
            source: $data['source'],
            fetchedAt: CarbonImmutable::parse($data['fetchedAt']),
            sourceVersion: $data['sourceVersion'],
        );
    }
}
