<?php

namespace App\Platform\Enrichment\Contracts;

use App\Modules\Monitoring\Models\ContentItem;
use App\Shared\ValueObjects\ReachEstimate;

/**
 * Reach estimation boundary (REQ-M1-006, MET-EstimatedReach).
 *
 * Reach is NEVER a view count and unique viewers are NEVER inferred from
 * views — GL-PublicViews: public view/play counts "are not unique reach".
 * An estimate must disclose its method (ReachEstimate.method, enforced by
 * the envelope) and carries tier ESTIMATED; CONFIRMED reach is deferred
 * (DEF-003) and renders "unavailable".
 *
 * Returning null means reach is UNAVAILABLE for that content — the only
 * honest answer while no canonical estimation method exists.
 */
interface ReachEstimator
{
    public function estimate(ContentItem $content): ?ReachEstimate;
}
