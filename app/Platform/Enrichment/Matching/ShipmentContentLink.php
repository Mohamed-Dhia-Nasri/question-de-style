<?php

namespace App\Platform\Enrichment\Matching;

/**
 * Outcome of linking one content item to one shipment through the
 * ShipmentContentLinker contract (REQ-M3-008). Carries what the platform
 * layer needs — whether the pivot row is new, and the shipment's parent
 * campaign for XMC-002 mention attribution — without exposing M3 rows.
 */
final readonly class ShipmentContentLink
{
    public function __construct(
        public bool $newlyLinked,
        public ?int $campaignId,
    ) {}
}
