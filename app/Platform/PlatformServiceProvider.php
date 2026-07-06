<?php

namespace App\Platform;

use App\Modules\CRM\Services\IngestedProfileSync;
use App\Platform\Analytics\Console\RefreshRollupsCommand;
use App\Platform\Analytics\Contracts\AnalyticsService;
use App\Platform\Analytics\NeonAnalyticsService;
use App\Platform\Enrichment\Attribution\NullSeedingEvidenceSource;
use App\Platform\Enrichment\Console\RunEnrichmentCommand;
use App\Platform\Enrichment\Contracts\EnrichmentService;
use App\Platform\Enrichment\Contracts\ReachEstimator;
use App\Platform\Enrichment\Contracts\SeedingEvidenceSource;
use App\Platform\Enrichment\Contracts\SentimentClassifier;
use App\Platform\Enrichment\DefaultEnrichmentService;
use App\Platform\Enrichment\Reach\UnavailableReachEstimator;
use App\Platform\Enrichment\Sentiment\UnavailableSentimentClassifier;
use App\Platform\Export\Console\PruneExpiredExportsCommand;
use App\Platform\Export\Contracts\ExportService;
use App\Platform\Export\DefaultExportService;
use App\Platform\Export\Models\ExportJob;
use App\Platform\Export\Policies\ExportJobPolicy;
use App\Platform\Ingestion\Console\ProviderHealthCommand;
use App\Platform\Ingestion\Console\PruneIngestionDataCommand;
use App\Platform\Ingestion\Console\RefreshIngestionStatusCommand;
use App\Platform\Ingestion\Console\RunMonitoringCycleCommand;
use App\Platform\Ingestion\Contracts\IngestionService;
use App\Platform\Ingestion\Contracts\PlatformAccountProfileSync;
use App\Platform\Ingestion\DefaultIngestionService;
use App\Platform\Ingestion\Models\ProviderResponseSample;
use App\Platform\Ingestion\Observability\Policies\ProviderResponseSamplePolicy;
use App\Platform\Snapshots\Console\CaptureSnapshotsCommand;
use App\Platform\Snapshots\Contracts\SnapshotScheduler;
use App\Platform\Snapshots\DatabaseSnapshotScheduler;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Registers the shared platform service boundaries (SVC-Ingestion,
 * SVC-EnrichmentAI, SVC-SnapshotScheduler, SVC-Analytics, SVC-Export — see
 * docs/60-architecture/00-system-architecture.md). SVC-Ingestion and
 * SVC-SnapshotScheduler are live (P1); the remaining contracts stay bound
 * to Pending* implementations until their roadmap phase delivers them —
 * swapping the binding here is the only change call-sites will see.
 */
class PlatformServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(IngestionService::class, DefaultIngestionService::class);
        $this->app->bind(SnapshotScheduler::class, DatabaseSnapshotScheduler::class);

        // Cross-module write contract: ENT-PlatformAccount profile fields
        // are applied by the CRM-owned implementation (ownership matrix).
        $this->app->bind(PlatformAccountProfileSync::class, IngestedProfileSync::class);

        // SVC-EnrichmentAI is live (P1). The inner boundaries with no
        // canonical model/provider decision stay bound to honest
        // "unavailable" implementations (never fabricate):
        //  - sentiment: no NLP model/provider is canonically decided;
        //  - reach: no estimation method is canonically documented;
        //  - seeding evidence: ENT-Shipment/ENT-SeedingCampaign are P3
        //    (Module 3) — until then no documented seeding records exist.
        $this->app->bind(EnrichmentService::class, DefaultEnrichmentService::class);
        $this->app->bind(SentimentClassifier::class, UnavailableSentimentClassifier::class);
        $this->app->bind(ReachEstimator::class, UnavailableReachEstimator::class);
        $this->app->bind(SeedingEvidenceSource::class, NullSeedingEvidenceSource::class);

        // SVC-Analytics is live (P0 analytics foundation + P1 fact loaders):
        // star schema on Neon Postgres, scheduled matview rollups (ADR-0013).
        $this->app->bind(AnalyticsService::class, NeonAnalyticsService::class);

        // SVC-Export is live (P1, REQ-M1-012): PDF/EXCEL/CSV rendered from
        // approved rollups into private, expiring storage.
        $this->app->bind(ExportService::class, DefaultExportService::class);
    }

    public function boot(): void
    {
        Gate::policy(ProviderResponseSample::class, ProviderResponseSamplePolicy::class);
        Gate::policy(ExportJob::class, ExportJobPolicy::class);

        // Analytics DDL lives apart from OLTP migrations so the OLAP layer
        // can move to a columnar engine later without touching OLTP history
        // (ADR-0010 ClickHouse escape hatch).
        $this->loadMigrationsFrom(database_path('migrations/analytics'));

        if ($this->app->runningInConsole()) {
            $this->commands([
                CaptureSnapshotsCommand::class,
                RefreshRollupsCommand::class,
                RunMonitoringCycleCommand::class,
                RefreshIngestionStatusCommand::class,
                PruneIngestionDataCommand::class,
                ProviderHealthCommand::class,
                RunEnrichmentCommand::class,
                PruneExpiredExportsCommand::class,
            ]);
        }
    }
}
