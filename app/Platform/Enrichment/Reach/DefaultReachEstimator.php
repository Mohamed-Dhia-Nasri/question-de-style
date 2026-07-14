<?php

namespace App\Platform\Enrichment\Reach;

use App\Modules\Monitoring\Models\ContentItem;
use App\Platform\Enrichment\Contracts\ReachEstimator;
use App\Shared\ValueObjects\ReachEstimate;

/**
 * The real reach estimator (ADR-0022): delegates to ReachCalculator's pure
 * computation. Replaces UnavailableReachEstimator now that a documented,
 * operator-configured reach method exists. Returns null (honestly
 * unavailable) when no configuration is active or no input is observed.
 */
class DefaultReachEstimator implements ReachEstimator
{
    public function __construct(private readonly ReachCalculator $calculator) {}

    public function estimate(ContentItem $content): ?ReachEstimate
    {
        return $this->calculator->estimateFor($content);
    }
}
