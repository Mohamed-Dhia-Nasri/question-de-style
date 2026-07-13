<?php

use App\Modules\Billing\BillingServiceProvider;
use App\Modules\CRM\CrmServiceProvider;
use App\Modules\Discovery\DiscoveryServiceProvider;
use App\Modules\Monitoring\MonitoringServiceProvider;
use App\Platform\PlatformServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\AuthServiceProvider;
use App\Providers\FortifyServiceProvider;
use App\Shared\Tenancy\TenancyServiceProvider;

return [
    AppServiceProvider::class,
    FortifyServiceProvider::class,

    // Cross-tenant authorization backstop (ADR-0019): a fail-closed
    // Gate::before that denies any ability against a model owned by a
    // different tenant, ahead of every permission-based policy.
    AuthServiceProvider::class,

    // Centralized tenant context (ADR-0019): scoped TenantContext binding
    // plus queue payload propagation. Registered before the platform and
    // module providers so every downstream service resolves the same
    // per-lifecycle context.
    TenancyServiceProvider::class,

    // Shared platform services (SVC-Ingestion, SVC-EnrichmentAI,
    // SVC-SnapshotScheduler, SVC-Analytics, SVC-Export).
    PlatformServiceProvider::class,

    // Product modules — exactly three, per the vision-and-scope law.
    MonitoringServiceProvider::class,
    DiscoveryServiceProvider::class,
    CrmServiceProvider::class,

    // SaaS billing & team management (ADR-0021) — commercial
    // infrastructure, not a fourth product module: it owns subscriptions,
    // seats, and invitations, with no influencer-domain surface.
    BillingServiceProvider::class,
];
