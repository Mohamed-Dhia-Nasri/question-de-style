<?php

namespace App\Platform\Enrichment\Contracts;

use App\Modules\Monitoring\Models\ContentItem;
use App\Modules\Monitoring\Models\Story;
use App\Platform\Enrichment\Attribution\ShipmentEvidence;

/**
 * Supplies documented seeding-activity evidence (Module 3 shipments /
 * seeding campaigns) for the creator behind a piece of content, so seeded
 * attribution can link content to a QDS seeding without SVC-EnrichmentAI
 * reading Module 3 tables directly.
 *
 * Module 3 (CRM & Seeding, phase P3) provides the real implementation once
 * ENT-Shipment / ENT-SeedingCampaign are migrated; until then the bound
 * NullSeedingEvidenceSource reports no records, so SEEDED can only result
 * from manual confirmation through the review workflow.
 */
interface SeedingEvidenceSource
{
    /** @return list<ShipmentEvidence> */
    public function forTarget(ContentItem|Story $target): array;
}
