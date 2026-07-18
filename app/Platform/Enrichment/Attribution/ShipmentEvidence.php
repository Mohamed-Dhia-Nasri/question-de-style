<?php

namespace App\Platform\Enrichment\Attribution;

use Carbon\CarbonImmutable;

/**
 * Evidence of one documented, unpaid QDS seeding activity (a Module 3
 * shipment / seeding-campaign record) available to attribution. Produced
 * by a SeedingEvidenceSource; SVC-EnrichmentAI never reads Module 3 tables
 * directly (single-write-owner, ownership matrix).
 *
 * A shipment is a LINK in the evidence chain — it never proves SEEDED on
 * its own; product/brand relevance must be independently evidenced
 * (ADR-0008 doctrine, AC-M1-020).
 */
final readonly class ShipmentEvidence
{
    /**
     * Signal grammar for shipment references: the SEEDED proving signal the
     * classifier records (AC-M1-003) and the SeededContentLinker later
     * parses back into a shipment id (REQ-M3-008).
     */
    public const REFERENCE_PREFIX = 'shipment-record:';

    public function __construct(
        /** Stable reference for signals, e.g. "shipment-record:42". */
        public string $reference,
        public ?int $brandId = null,
        public ?string $brandName = null,
        public ?string $productLabel = null,
        public ?int $productId = null,
        public ?CarbonImmutable $shippedAt = null,
        public ?CarbonImmutable $deliveredAt = null,
        public ?int $campaignId = null,
    ) {}

    /** The date attribution windows count from (delivery beats shipping). */
    public function anchorDate(): ?CarbonImmutable
    {
        return $this->deliveredAt ?? $this->shippedAt;
    }

    public static function referenceFor(int $shipmentId): string
    {
        return self::REFERENCE_PREFIX.$shipmentId;
    }

    /** Parse a classification signal back into a shipment id, if it is one. */
    public static function shipmentIdFrom(string $signal): ?int
    {
        if (! str_starts_with($signal, self::REFERENCE_PREFIX)) {
            return null;
        }

        $id = substr($signal, strlen(self::REFERENCE_PREFIX));

        return ctype_digit($id) ? (int) $id : null;
    }
}
