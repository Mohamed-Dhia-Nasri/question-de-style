<?php

namespace App\Platform\Enrichment\Attribution;

use App\Modules\Monitoring\Models\ContentItem;
use App\Modules\Monitoring\Models\Story;
use App\Platform\Enrichment\Contracts\SeedingEvidenceSource;

/**
 * Default binding until Module 3 (P3) migrates ENT-Shipment /
 * ENT-SeedingCampaign: no documented seeding records exist yet, so
 * automated classification can never produce SEEDED — content stays
 * LIKELY_ORGANIC/UNKNOWN unless a human confirms a seeding link through
 * the review workflow (DP-004). This is a service boundary, not a
 * deferred feature: it reports honestly that no records are available.
 */
class NullSeedingEvidenceSource implements SeedingEvidenceSource
{
    /** @return list<ShipmentEvidence> */
    public function forTarget(ContentItem|Story $target): array
    {
        return [];
    }
}
