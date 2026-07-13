<?php

namespace App\Modules\CRM\Services;

use App\Modules\CRM\Models\Shipment;
use App\Modules\Monitoring\Models\ContentItem;
use App\Platform\Enrichment\Contracts\ShipmentContentLinker;
use App\Platform\Enrichment\Matching\ShipmentContentLink;
use Illuminate\Support\Facades\DB;

/**
 * SVC-CRM's sanctioned write path for the shipment↔content join
 * (REQ-M3-008): shipment_resulting_content rows plus the derived
 * posted/postedAt lifecycle fields on ENT-Shipment (data model: postedAt =
 * publish time of the resulting content; Step-3 spec D6 = earliest linked
 * publish time, recomputed on unlink).
 *
 * Implements the ShipmentContentLinker platform contract for the automatic
 * path (SeededContentLinker); the shipments panel calls link()/unlink()
 * directly for the operator's manual confirm/deny (XMC-002's M3 side).
 */
class ShipmentContentWriter implements ShipmentContentLinker
{
    public function link(int $shipmentId, ContentItem $content): ?ShipmentContentLink
    {
        $shipment = Shipment::query()->with('seedingCampaign')->find($shipmentId);

        // ADR-0019: matching must only ever pair rows of the SAME tenant.
        // TenantScope already filters the lookup when a context is set (the
        // linker runs each mention under its content's tenant), but the
        // equality check is explicit — explicit beats ambient in pipeline
        // code, and this write path is also reachable from panels/tests.
        if ($shipment === null
            || $shipment->tenant_id !== $content->tenant_id
            || $shipment->creator_id !== $content->platformAccount?->creator_id) {
            return null;
        }

        $newlyLinked = false;

        DB::transaction(function () use ($shipment, $content, &$newlyLinked): void {
            $result = $shipment->resultingContent()->syncWithoutDetaching([$content->id]);
            $newlyLinked = $result['attached'] !== [];

            $this->refreshPostedState($shipment);
        });

        return new ShipmentContentLink(
            newlyLinked: $newlyLinked,
            campaignId: $shipment->seedingCampaign->campaign_id,
        );
    }

    public function unlink(Shipment $shipment, ContentItem $content): void
    {
        DB::transaction(function () use ($shipment, $content): void {
            $shipment->resultingContent()->detach($content->id);

            $this->refreshPostedState($shipment);
        });
    }

    /**
     * posted = any resulting content exists; postedAt = earliest publish
     * time among the linked content (null when none carries one).
     */
    private function refreshPostedState(Shipment $shipment): void
    {
        $earliest = $shipment->resultingContent()->min('published_at');

        $shipment->forceFill([
            'posted' => $shipment->resultingContent()->exists(),
            'posted_at' => $earliest,
        ])->save();
    }
}
