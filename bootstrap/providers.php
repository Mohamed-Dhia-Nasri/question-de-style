<?php

use App\Modules\CRM\CrmServiceProvider;
use App\Modules\Discovery\DiscoveryServiceProvider;
use App\Modules\Monitoring\MonitoringServiceProvider;
use App\Platform\PlatformServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\FortifyServiceProvider;

return [
    AppServiceProvider::class,
    FortifyServiceProvider::class,

    // Shared platform services (SVC-Ingestion, SVC-EnrichmentAI,
    // SVC-SnapshotScheduler, SVC-Analytics, SVC-Export).
    PlatformServiceProvider::class,

    // Product modules — exactly three, per the vision-and-scope law.
    MonitoringServiceProvider::class,
    DiscoveryServiceProvider::class,
    CrmServiceProvider::class,
];
