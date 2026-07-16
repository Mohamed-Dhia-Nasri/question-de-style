<?php

namespace App\Shared\Support;

use App\Modules\Monitoring\Models\EmvConfiguration;
use App\Platform\Enrichment\Support\EmvConfigurationStatus;
use App\Shared\Tenancy\TenantContext;

/**
 * The tenant's display currency — the active EMV rate card's ISO code
 * (the only currency the system stores), falling back to EUR. Cached
 * per request per tenant.
 */
class TenantCurrency
{
    /** @var array<int|string, string> */
    private static array $cache = [];

    public static function code(): string
    {
        $tenantId = app(TenantContext::class)->id() ?? auth()->user()?->tenant_id ?? 0;

        return self::$cache[$tenantId] ??= (string) (EmvConfiguration::query()
            ->where('status', EmvConfigurationStatus::Active)
            ->value('currency') ?? 'EUR');
    }
}
