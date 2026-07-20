<?php

namespace App\Platform\Enrichment\VisualMatch\Console;

use App\Models\Tenant;
use App\Modules\Monitoring\Models\ContentItem;
use App\Modules\Monitoring\Models\Story;
use App\Platform\Enrichment\Attribution\AttributionService;
use App\Platform\Enrichment\Support\EnrichmentRunStatus;
use App\Platform\Enrichment\VisualMatch\VisualProductMatcher;
use App\Shared\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Day-one rollout tool (sub-project C, spec §14): re-runs the visual_match
 * stage — and, when it completes, attribution — over recent posts that
 * ALREADY have keyframes and a completed enrichment run. Every embed goes
 * through the normal AiBudgetGuard with normally computed priorities, so a
 * backfill can never blow the budget: exhaustion surfaces as
 * skipped:budget-exhausted markers in the tally, never as failures.
 * Historic EnrichmentRun rows are never rewritten (append-only telemetry)
 * — backfilled evidence lives in visual_match_runs and the refreshed
 * Mention.
 */
class VisualMatchBackfillCommand extends Command
{
    protected $signature = 'qds:visual-match-backfill {--days=30} {--tenant=} {--dry-run}';

    protected $description = 'Re-run visual product matching (+ attribution) over recent posts that already have keyframes';

    private VisualProductMatcher $matcher;

    private AttributionService $attribution;

    private TenantContext $context;

    /** @var array<string, int> */
    private array $markers = [];

    private int $processed = 0;

    private int $attributionReruns = 0;

    public function handle(VisualProductMatcher $matcher, AttributionService $attribution, TenantContext $context): int
    {
        if (! (bool) config('qds.enrichment.visual_match.enabled')) {
            $this->warn('Visual matching is disabled (qds.enrichment.visual_match.enabled) — nothing to do.');

            return self::SUCCESS;
        }

        $this->matcher = $matcher;
        $this->attribution = $attribution;
        $this->context = $context;

        $days = max(1, (int) $this->option('days'));
        $since = CarbonImmutable::now()->subDays($days);
        $dryRun = (bool) $this->option('dry-run');
        $correlationId = (string) Str::uuid();

        $tenantIds = $this->option('tenant') !== null
            ? [(int) $this->option('tenant')]
            : Tenant::query()->orderBy('id')->pluck('id')->map(static fn ($id): int => (int) $id)->all();

        $this->info("Visual-match backfill over the last {$days} day(s) [correlation {$correlationId}].");

        foreach ($tenantIds as $tenantId) {
            $contentIds = $this->eligibleIds(ContentItem::class, 'published_at', $tenantId, $since);
            $storyIds = $this->eligibleIds(Story::class, 'captured_at', $tenantId, $since);

            if ($dryRun) {
                $this->line(sprintf(
                    'Tenant %d: would process %d content item(s), %d story(ies) [dry-run].',
                    $tenantId,
                    count($contentIds),
                    count($storyIds),
                ));

                continue;
            }

            $this->line(sprintf(
                'Tenant %d: %d content item(s), %d story(ies) eligible.',
                $tenantId,
                count($contentIds),
                count($storyIds),
            ));

            $this->process(ContentItem::class, $contentIds, $tenantId, $correlationId);
            $this->process(Story::class, $storyIds, $tenantId, $correlationId);
        }

        if ($dryRun) {
            $this->info('Dry run — nothing executed.');

            return self::SUCCESS;
        }

        ksort($this->markers);

        foreach ($this->markers as $marker => $count) {
            $this->line("  {$marker} ×{$count}");
        }

        $this->info("Backfill done: {$this->processed} target(s) processed, {$this->attributionReruns} attribution re-run(s).");

        return self::SUCCESS;
    }

    /**
     * Eligibility (spec §14): in the window, ≥ 1 keyframe, and a COMPLETED
     * enrichment run. The command runs tenant-less, so ownership is an
     * EXPLICIT tenant_id predicate on every table touched with global
     * scopes removed (the ADR-0025 command convention) — this also keeps
     * the query correct when a tenant context IS bound (tests).
     *
     * @param class-string<ContentItem|Story> $model
     * @return list<int>
     */
    private function eligibleIds(string $model, string $publishedColumn, int $tenantId, CarbonImmutable $since): array
    {
        return $model::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where($publishedColumn, '>=', $since)
            ->whereHas('keyframes', static function ($query) use ($tenantId): void {
                $query->withoutGlobalScopes()->where('keyframes.tenant_id', $tenantId);
            })
            ->whereHas('enrichmentRuns', static function ($query) use ($tenantId): void {
                $query->withoutGlobalScopes()
                    ->where('enrichment_runs.tenant_id', $tenantId)
                    ->where('status', EnrichmentRunStatus::Completed->value);
            })
            ->orderBy($publishedColumn)
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * @param class-string<ContentItem|Story> $model
     * @param list<int> $ids
     */
    private function process(string $model, array $ids, int $tenantId, string $correlationId): void
    {
        foreach ($ids as $id) {
            /** @var string $marker */
            $marker = $this->context->runAs($tenantId, function () use ($model, $id, $correlationId): string {
                /** @var ContentItem|Story $target */
                $target = $model::query()->findOrFail($id);

                $marker = $this->matcher->enrich($target, $correlationId);

                if (str_starts_with($marker, 'completed:')) {
                    // Re-classify in the SAME tenant context so the fresh
                    // VISUAL_PRODUCT evidence lands on the Mention now, not
                    // at the next natural enrichment.
                    $this->attribution->enrich($target);
                    $this->attributionReruns++;
                }

                return $marker;
            });

            $this->markers[$marker] = ($this->markers[$marker] ?? 0) + 1;
            $this->processed++;
        }
    }
}
