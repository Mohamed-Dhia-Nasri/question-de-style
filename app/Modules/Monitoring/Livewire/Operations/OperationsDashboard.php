<?php

namespace App\Modules\Monitoring\Livewire\Operations;

use App\Modules\Monitoring\Models\MetricSnapshot;
use App\Modules\Monitoring\Models\Story;
use App\Modules\Monitoring\Models\VisualMatchRun;
use App\Modules\Monitoring\Models\VlmVerificationRun;
use App\Platform\AiBudget\Models\AiUsageCounter;
use App\Platform\Export\Models\ExportJob;
use App\Platform\Export\Support\ExportJobStatus;
use App\Platform\Ingestion\Models\IngestionAlert;
use App\Platform\Ingestion\Models\IngestionCycle;
use App\Platform\Ingestion\Observability\ProviderHealthService;
use App\Platform\Ingestion\SourceRegistry;
use App\Shared\Authorization\PermissionsCatalog;
use App\Shared\Enums\VisualMatchOutcome;
use App\Shared\Enums\VlmRunOutcome;
use App\Shared\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
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

    public function render(ProviderHealthService $health, TenantContext $context): View
    {
        $tenantId = $context->id();

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
            // Freshness reflects the VIEWER's own ingest activity only —
            // metric_snapshots/stories are tenant-owned, so a raw max() would
            // leak whether other tenants are actively ingesting (ADR-0019).
            'snapshotFreshness' => [
                'account' => MetricSnapshot::query()->whereNotNull('platform_account_id')->max('captured_at'),
                'content' => MetricSnapshot::query()->whereNotNull('content_item_id')->max('captured_at'),
            ],
            'storyFreshness' => Story::query()->max('captured_at'),
            'analyticsRefresh' => $lastRefresh,
            'failedExports' => ExportJob::query()
                ->where('status', ExportJobStatus::Failed)
                ->latest('failed_at')
                ->limit(5)
                ->get(),
            // Data-quality alerts carry the tenant whose roster their message
            // names; show the viewer's own plus the global provider-level
            // incidents (tenant_id NULL), never a competitor's roster.
            'alerts' => IngestionAlert::query()
                ->where(function ($q) use ($tenantId): void {
                    $q->whereNull('tenant_id')
                        ->when($tenantId !== null, fn ($q) => $q->orWhere('tenant_id', $tenantId));
                })
                ->latest('created_at')
                ->limit(8)
                ->get(),
            // Spec §10 AI-spend panel: own-tenant usage + anonymous platform totals.
            'aiSpend' => $this->aiSpendPanel($tenantId),
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
        $embeddings = (string) config('services.google_embeddings.credentials_path') !== ''
            && (string) config('services.google_embeddings.project_id') !== '';
        $vlm = (string) config('services.google_vlm.credentials_path') !== ''
            && (string) config('services.google_vlm.project_id') !== '';
        $speechV2 = (string) config('services.google_speech_v2.credentials_path') !== ''
            && (string) config('services.google_speech_v2.project_id') !== '';

        $configured = [];

        foreach (SourceRegistry::all() as $source) {
            $configured[$source] = match (true) {
                str_starts_with($source, 'SRC-apify-'), $source === SourceRegistry::CLOCKWORKS_TIKTOK_SCRAPER => $apify,
                $source === SourceRegistry::YOUTUBE_DATA_API_V3 => $youtube,
                $source === SourceRegistry::GOOGLE_CLOUD_VISION => $vision,
                // Speech v2 runs on service-account credentials; the v1 API
                // key is the legacy rollback path — either marks the source.
                $source === SourceRegistry::GOOGLE_SPEECH_TO_TEXT => $speech || $speechV2,
                $source === SourceRegistry::GOOGLE_VIDEO_INTELLIGENCE => $video,
                $source === SourceRegistry::GOOGLE_GEMINI_EMBEDDINGS => $embeddings,
                $source === SourceRegistry::GOOGLE_GEMINI_VLM => $vlm,
                default => false,
            };
        }

        return $configured;
    }

    /**
     * AI-spend + visual-match quality panel (spec §10). ADR-0019: this
     * dashboard is viewed by TENANT staff, so only the viewer's own usage
     * is itemized; platform figures are anonymous aggregates (same
     * posture as queue depth) — the spec's per-tenant "top spenders"
     * table is deliberately narrowed to own-tenant + platform totals,
     * because naming another tenant here would break the isolation
     * contract the cross-tenant alert tests pin down.
     *
     * @return array{capabilities: list<array<string, mixed>>, visual: array<string, mixed>|null, vlm: array<string, mixed>|null}
     */
    private function aiSpendPanel(?int $tenantId): array
    {
        $today = CarbonImmutable::now()->toDateString();
        $monthStart = CarbonImmutable::now()->startOfMonth()->toDateString();

        $capabilities = [];

        foreach (array_keys((array) config('qds.ai_budget.capabilities')) as $capability) {
            // tenant_id 0 matches nothing: platform context itemizes nobody.
            $own = fn () => AiUsageCounter::query()
                ->where('capability', $capability)
                ->where('usage_date', '>=', $monthStart)
                ->where('tenant_id', $tenantId ?? 0);

            $global = fn () => AiUsageCounter::query()
                ->where('capability', $capability)
                ->where('usage_date', '>=', $monthStart);

            $ownMonthCostMicro = (int) $own()->sum('estimated_cost_micro_usd');
            $ownPostsProcessed = (int) $own()->sum('posts_processed');

            $capabilities[] = [
                'capability' => $capability,
                'own_today_units' => (int) $own()->where('usage_date', $today)->sum('units'),
                'own_month_units' => (int) $own()->sum('units'),
                'own_month_cost_usd' => $ownMonthCostMicro / 1_000_000,
                'own_skipped_budget' => (int) $own()->sum('posts_skipped_budget'),
                'own_skipped_no_candidates' => (int) $own()->sum('posts_skipped_no_candidates'),
                'avg_cost_per_post_usd' => $ownPostsProcessed > 0 ? $ownMonthCostMicro / $ownPostsProcessed / 1_000_000 : null,
                'global_today_units' => (int) $global()->where('usage_date', $today)->sum('units'),
                'global_month_units' => (int) $global()->sum('units'),
            ];
        }

        return [
            'capabilities' => $capabilities,
            'visual' => $this->visualRunAggregates($tenantId),
            'vlm' => $this->vlmRunAggregates($tenantId),
        ];
    }

    /**
     * Quality/efficiency aggregates over the last 7 days of visual-match
     * runs. VisualMatchRun is TenantScoped, but TenantScope is a NO-OP with
     * no active context — so a null $tenantId is filtered explicitly here
     * too (same defensive pattern as aiSpendPanel()'s `own()`), rather than
     * trusting the scope alone to keep a null-context render from silently
     * becoming an all-tenant aggregate. Null when there are no recent runs.
     *
     * @return array<string, mixed>|null
     */
    private function visualRunAggregates(?int $tenantId): ?array
    {
        $recent = fn () => VisualMatchRun::query()
            ->where('tenant_id', $tenantId ?? 0)
            ->where('created_at', '>=', CarbonImmutable::now()->subDays(7));

        $runs = (int) $recent()->count();

        if ($runs === 0) {
            return null;
        }

        $billed = (int) $recent()->sum('embedding_calls');
        $cacheHits = (int) $recent()->sum('cache_hits');

        return [
            'runs' => $runs,
            'embeddings_created' => $billed,
            'cache_hit_rate' => ($billed + $cacheHits) > 0 ? $cacheHits / ($billed + $cacheHits) : null,
            'skipped_format' => (int) $recent()->sum('frames_skipped_format'),
            'skipped_quality' => (int) $recent()->sum('frames_skipped_quality'),
            'deduped' => (int) $recent()->sum('frames_deduped'),
            'budget_denials' => (int) $recent()->where('outcome', VisualMatchOutcome::SkippedBudget->value)->count(),
            'avg_candidates' => round((float) $recent()->avg('candidates_checked'), 1),
            'avg_processing_ms' => (int) round((float) $recent()->avg('processing_ms')),
        ];
    }

    /**
     * Quality/spend aggregates over the last 7 days of VLM verification
     * runs, mirroring visualRunAggregates() — the same explicit
     * null-tenant filter keeps a null-context render from silently
     * becoming an all-tenant aggregate. Budget denials are read from the
     * AI-usage counters, NOT from run outcomes: a budget-deferred
     * verification writes no run row at all (spec §10 — the anchor stays
     * unconsumed for the sweep). Null when there are no recent runs.
     *
     * @return array<string, mixed>|null
     */
    private function vlmRunAggregates(?int $tenantId): ?array
    {
        $recent = fn () => VlmVerificationRun::query()
            ->where('tenant_id', $tenantId ?? 0)
            ->where('created_at', '>=', CarbonImmutable::now()->subDays(7));

        $runs = (int) $recent()->count();

        if ($runs === 0) {
            return null;
        }

        // toBase() so outcome keys stay raw strings (no enum cast on pluck).
        $outcomes = $recent()
            ->toBase()
            ->select('outcome', DB::raw('count(*) as total'))
            ->groupBy('outcome')
            ->orderBy('outcome')
            ->pluck('total', 'outcome')
            ->map(fn ($total): int => (int) $total)
            ->all();

        return [
            'runs' => $runs,
            'outcomes' => $outcomes,
            'avg_attempts' => round((float) $recent()->avg('attempts'), 1),
            'avg_latency_ms' => (int) round((float) $recent()->avg('latency_ms')),
            'unverifiable' => (int) ($outcomes[VlmRunOutcome::Unverifiable->value] ?? 0),
            'budget_denials' => (int) AiUsageCounter::query()
                ->where('capability', 'vlm_verification')
                ->where('tenant_id', $tenantId ?? 0)
                ->where('usage_date', '>=', CarbonImmutable::now()->subDays(7)->toDateString())
                ->sum('posts_skipped_budget'),
        ];
    }
}
