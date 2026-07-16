<?php

namespace App\Shared\Support;

use App\Modules\Monitoring\Models\EmvConfiguration;
use App\Platform\Enrichment\Support\EmvConfigurationStatus;

/**
 * The tenant's display currency — the active EMV rate card's ISO code
 * (the only currency the system stores), falling back to EUR.
 *
 * Request/job-scoped via the container (flushed between Octane requests
 * and queue jobs): one instance per lifecycle, so the memo below can
 * never leak a resolved currency across tenants or requests.
 */
class TenantCurrency
{
    private ?string $cached = null;

    public function code(): string
    {
        return $this->cached ??= (string) (EmvConfiguration::query()
            ->where('status', EmvConfigurationStatus::Active)
            ->value('currency') ?? 'EUR');
    }
}
