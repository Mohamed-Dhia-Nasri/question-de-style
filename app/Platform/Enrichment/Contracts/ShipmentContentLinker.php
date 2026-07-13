<?php

namespace App\Platform\Enrichment\Contracts;

use App\Modules\Monitoring\Models\ContentItem;
use App\Platform\Enrichment\Matching\ShipmentContentLink;

/**
 * Cross-module write contract (XMC-*, ownership matrix rule 3):
 * ENT-Shipment (posted/postedAt and the resulting-content join) is
 * write-owned by Module 3 CRM, so the SeededContentLinker (SVC-EnrichmentAI,
 * REQ-M3-008) never writes shipments or shipment_resulting_content directly.
 * Same pattern as PlatformAccountProfileSync.
 */
interface ShipmentContentLinker
{
    /**
     * Record that a content item resulted from a shipment. Idempotent.
     * Returns null when the shipment does not exist or its recipient is not
     * the content's creator (a stale/foreign reference is never linked).
     */
    public function link(int $shipmentId, ContentItem $content): ?ShipmentContentLink;
}
