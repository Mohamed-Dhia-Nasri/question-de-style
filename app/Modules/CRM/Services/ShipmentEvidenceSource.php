<?php

namespace App\Modules\CRM\Services;

use App\Modules\CRM\Models\Shipment;
use App\Modules\Monitoring\Models\ContentItem;
use App\Modules\Monitoring\Models\Story;
use App\Platform\Enrichment\Attribution\ShipmentEvidence;
use App\Platform\Enrichment\Contracts\SeedingEvidenceSource;
use Carbon\CarbonImmutable;

/**
 * Module 3's real SeedingEvidenceSource (replaces the P1 Null binding):
 * supplies the creator's documented shipments to attribution so seeded
 * content can be classified SEEDED with a proving record (AC-M1-003,
 * AC-M1-020) — SVC-EnrichmentAI never reads M3 tables directly.
 *
 * Only DISPATCHED shipments (shippedAt set) are evidence — an unshipped
 * product cannot have caused content (Step-3 spec D1). Alignment, timing
 * windows, and confidence stay entirely in the P1 MentionClassifier; this
 * class only reports facts.
 */
class ShipmentEvidenceSource implements SeedingEvidenceSource
{
    /** @return list<ShipmentEvidence> */
    public function forTarget(ContentItem|Story $target): array
    {
        $creatorId = $target->platformAccount?->creator_id;

        if ($creatorId === null) {
            return [];
        }

        return Shipment::query()
            ->where('creator_id', $creatorId)
            ->whereNotNull('shipped_at')
            ->with(['seedingCampaign.brand', 'product'])
            ->orderBy('id')
            ->get()
            ->map(fn (Shipment $shipment): ShipmentEvidence => new ShipmentEvidence(
                reference: ShipmentEvidence::referenceFor($shipment->id),
                brandId: $shipment->seedingCampaign->brand_id,
                brandName: $shipment->seedingCampaign->brand->name,
                productLabel: $shipment->product->name,
                shippedAt: $shipment->shipped_at instanceof CarbonImmutable ? $shipment->shipped_at : null,
                deliveredAt: $shipment->delivered_at instanceof CarbonImmutable ? $shipment->delivered_at : null,
                campaignId: $shipment->seedingCampaign->campaign_id,
            ))
            ->all();
    }
}
