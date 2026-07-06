<?php

namespace App\Platform\Analytics;

use App\Platform\Analytics\Contracts\AnalyticsService;
use App\Shared\Exceptions\NotYetImplemented;

class PendingAnalyticsService implements AnalyticsService
{
    public function refreshRollups(): int
    {
        throw NotYetImplemented::service('SVC-Analytics', 'remaining P0');
    }
}
