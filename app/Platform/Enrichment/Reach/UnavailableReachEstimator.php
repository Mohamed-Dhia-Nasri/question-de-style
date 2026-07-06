<?php

namespace App\Platform\Enrichment\Reach;

use App\Modules\Monitoring\Models\ContentItem;
use App\Platform\Enrichment\Contracts\ReachEstimator;
use App\Shared\ValueObjects\ReachEstimate;

/**
 * Default binding: the metrics catalog mandates that estimated reach be
 * "modeled from PUBLIC views/plays and follower signals via a documented
 * method", but NO concrete method/formula is canonically documented
 * (flagged missing decision). Until an ADR documents the model, reach is
 * UNAVAILABLE — never derived from views, never fabricated (DEF-003
 * keeps CONFIRMED reach unavailable regardless).
 */
class UnavailableReachEstimator implements ReachEstimator
{
    public function estimate(ContentItem $content): ?ReachEstimate
    {
        return null;
    }
}
