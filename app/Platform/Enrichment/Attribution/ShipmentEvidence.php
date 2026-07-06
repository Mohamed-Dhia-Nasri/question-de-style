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
    public function __construct(
        /** Stable reference for signals, e.g. "shipment-record:42". */
        public string $reference,
        public ?int $brandId = null,
        public ?string $brandName = null,
        public ?string $productLabel = null,
        public ?CarbonImmutable $shippedAt = null,
        public ?CarbonImmutable $deliveredAt = null,
        public ?int $campaignId = null,
    ) {}

    /** The date attribution windows count from (delivery beats shipping). */
    public function anchorDate(): ?CarbonImmutable
    {
        return $this->deliveredAt ?? $this->shippedAt;
    }
}
