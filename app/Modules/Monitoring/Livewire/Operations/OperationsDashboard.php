<?php

namespace App\Modules\Monitoring\Livewire\Operations;

use App\Platform\Export\Models\ExportJob;
use App\Platform\Export\Support\ExportJobStatus;
use App\Platform\Ingestion\Models\IngestionAlert;
use App\Platform\Ingestion\Models\IngestionCycle;
use App\Platform\Ingestion\Observability\ProviderHealthService;
use App\Platform\Ingestion\SourceRegistry;
use App\Shared\Authorization\PermissionsCatalog;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

/**
 * Authorized internal observability (operations.view — staff only, never
 * CLIENT_VIEWER): provider configuration + health, last successful
 * ingestion cycles, queue/failed-job state, snapshot / analytics /
 * story-polling freshness, export failures, and recent alerts.
 *
 * Everything shown is sanitized operational telemetry — provider errors
 * arrive pre-sanitized from the call recorder; no payloads, credentials,
 * or personal data appear here (DP-005 privacy-safe logging).
 */
class OperationsDashboard extends Component
{
    public function mount(): void
    {
        abort_unless(auth()->user()?->can(PermissionsCatalog::OPERATIONS_VIEW) === true, 403);
    }

    public function render(ProviderHealthService $health): View
    {
        $lastCycle = IngestionCycle::query()
            ->where('stories_only', false)
            ->latest('started_at')
            ->first();

        $lastStoryCycle = IngestionCycle::query()
            ->where('stories_only', true)
            ->latest('started_at')
            ->first();

        $lastRefresh = DB::table('analytics_refreshes')
            ->orderByDesc('started_at')
            ->first();

        return view('livewire.monitoring.operations-dashboard', [
            'providerHealth' => $health->overview(),
            'providerConfig' => $this->providerConfiguration(),
            'lastCycle' => $lastCycle,
            'lastStoryCycle' => $lastStoryCycle,
            'queueDepth' => DB::table('jobs')->count(),
            'failedJobs' => DB::table('failed_jobs')->count(),
            'recentFailedJobs' => DB::table('failed_jobs')
                ->orderByDesc('failed_at')
                ->limit(5)
                ->get(['id', 'queue', 'failed_at']),
            'snapshotFreshness' => [
                'account' => DB::table('metric_snapshots')->whereNotNull('platform_account_id')->max('captured_at'),
                'content' => DB::table('metric_snapshots')->whereNotNull('content_item_id')->max('captured_at'),
            ],
            'storyFreshness' => DB::table('stories')->max('captured_at'),
            'analyticsRefresh' => $lastRefresh,
            'failedExports' => ExportJob::query()
                ->where('status', ExportJobStatus::Failed)
                ->latest('failed_at')
                ->limit(5)
                ->get(),
            'alerts' => IngestionAlert::query()
                ->latest('created_at')
                ->limit(8)
                ->get(),
        ]);
    }

    /**
     * Which frozen SRC-* providers have credentials configured — presence
     * booleans only, never the secrets themselves.
     *
     * @return array<string, bool>
     */
    private function providerConfiguration(): array
    {
        $apify = config('services.apify.token') !== null && config('services.apify.token') !== '';
        $youtube = config('services.youtube.api_key') !== null && config('services.youtube.api_key') !== '';
        $vision = (bool) config('services.google_vision.api_key');
        $speech = (bool) config('services.google_speech.api_key');
        $video = (bool) config('services.google_video_intelligence.api_key');

        $configured = [];

        foreach (SourceRegistry::all() as $source) {
            $configured[$source] = match (true) {
                str_starts_with($source, 'SRC-apify-'), $source === SourceRegistry::CLOCKWORKS_TIKTOK_SCRAPER => $apify,
                $source === SourceRegistry::YOUTUBE_DATA_API_V3 => $youtube,
                $source === SourceRegistry::GOOGLE_CLOUD_VISION => $vision,
                $source === SourceRegistry::GOOGLE_SPEECH_TO_TEXT => $speech,
                $source === SourceRegistry::GOOGLE_VIDEO_INTELLIGENCE => $video,
                default => false,
            };
        }

        return $configured;
    }
}
