<?php

namespace App\Platform;

use App\Modules\CRM\Services\IngestedProfileSync;
use App\Modules\CRM\Services\ShipmentContentWriter;
use App\Modules\CRM\Services\ShipmentEvidenceSource;
use App\Platform\AiBudget\Console\AiQuotaCommand;
use App\Platform\AiBudget\Console\AiReadOnlyCommand;
use App\Platform\Analytics\Console\RefreshRollupsCommand;
use App\Platform\Analytics\Contracts\AnalyticsService;
use App\Platform\Analytics\NeonAnalyticsService;
use App\Platform\Enrichment\Console\EvalDetectionCommand;
use App\Platform\Enrichment\Console\PruneKeyframesCommand;
use App\Platform\Enrichment\Console\RunEnrichmentCommand;
use App\Platform\Enrichment\Contracts\EnrichmentService;
use App\Platform\Enrichment\Contracts\ReachEstimator;
use App\Platform\Enrichment\Contracts\SeedingEvidenceSource;
use App\Platform\Enrichment\Contracts\SentimentClassifier;
use App\Platform\Enrichment\Contracts\ShipmentContentLinker;
use App\Platform\Enrichment\DefaultEnrichmentService;
use App\Platform\Enrichment\Matching\Console\LinkSeededContentCommand;
use App\Platform\Enrichment\Reach\DefaultReachEstimator;
use App\Platform\Enrichment\Sentiment\UnavailableSentimentClassifier;
use App\Platform\Enrichment\VisualMatch\Console\EmbedProductPhotosCommand;
use App\Platform\Enrichment\VisualMatch\Console\VisualMatchBackfillCommand;
use App\Platform\Enrichment\VisualMatch\Contracts\EmbeddingProvider;
use App\Platform\Enrichment\VisualMatch\Http\GeminiMultimodalEmbeddingProvider;
use App\Platform\Export\Console\PruneExpiredExportsCommand;
use App\Platform\Export\Contracts\ExportService;
use App\Platform\Export\DefaultExportService;
use App\Platform\Export\Models\ExportJob;
use App\Platform\Export\Policies\ExportJobPolicy;
use App\Platform\Ingestion\Console\CheckDataQualityCommand;
use App\Platform\Ingestion\Console\ProviderHealthCommand;
use App\Platform\Ingestion\Console\PruneIngestionDataCommand;
use App\Platform\Ingestion\Console\PruneStoryMediaCommand;
use App\Platform\Ingestion\Console\RefreshCampaignContentCommand;
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
        //  - reach: DefaultReachEstimator (ADR-0022) now computes a
        //    documented, operator-configured estimate — still unavailable
        //    (null) whenever no configuration is active or no input exists.
        $this->app->bind(EnrichmentService::class, DefaultEnrichmentService::class);
        $this->app->bind(SentimentClassifier::class, UnavailableSentimentClassifier::class);
        $this->app->bind(ReachEstimator::class, DefaultReachEstimator::class);

        // Sub-project C (ADR-0029): the embedding seam for visual product
        // matching. Gemini Embedding 2 is the only v1 implementation; a
        // second provider is a new binding + model_version — never a
        // call-site change (no selection knob until one exists, YAGNI).
        $this->app->bind(EmbeddingProvider::class, GeminiMultimodalEmbeddingProvider::class);

        // Cross-module contracts with Module 3 (P3, live since M3 Step 3):
        // seeding evidence is read from — and resulting-content links are
        // written by — the CRM-owned implementations (ownership matrix;
        // same pattern as PlatformAccountProfileSync above).
        $this->app->bind(SeedingEvidenceSource::class, ShipmentEvidenceSource::class);
        $this->app->bind(ShipmentContentLinker::class, ShipmentContentWriter::class);

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
                RefreshCampaignContentCommand::class,
                RefreshIngestionStatusCommand::class,
                PruneIngestionDataCommand::class,
                PruneStoryMediaCommand::class,
                CheckDataQualityCommand::class,
                ProviderHealthCommand::class,
                RunEnrichmentCommand::class,
                LinkSeededContentCommand::class,
                PruneExpiredExportsCommand::class,
                EvalDetectionCommand::class,
                PruneKeyframesCommand::class,
                EmbedProductPhotosCommand::class,
                AiReadOnlyCommand::class,
                AiQuotaCommand::class,
                VisualMatchBackfillCommand::class,
            ]);
        }
    }
}
