<?php

namespace App\Platform\Enrichment\VlmVerification\Console;

use App\Models\Tenant;
use App\Modules\Monitoring\Models\ContentItem;
use App\Modules\Monitoring\Models\Story;
use App\Modules\Monitoring\Models\VisualMatchRun;
use App\Modules\Monitoring\Models\VlmVerificationRun;
use App\Platform\Enrichment\VisualMatch\Candidates\CandidateScope;
use App\Platform\Enrichment\VlmVerification\Jobs\VlmVerificationJob;
use App\Platform\Enrichment\VlmVerification\VlmRunRecorder;
use App\Shared\Enums\VisualMatchOutcome;
use App\Shared\Enums\VlmRunOutcome;
use App\Shared\Enums\VlmTriggerReason;
use App\Shared\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * qds:vlm-verify — sub-project D's scheduled sweep AND day-one backfill
 * tool (spec §4/§10/§14). Self-gated on BOTH qds.enrichment.vlm.enabled
 * and qds.enrichment.visual_match.enabled (D verifies C's candidates —
 * the locked tier order). Three passes per tenant, stale-pending FIRST so
 * a freshly consumed ledger is never re-dispatched and a deleted unbilled
 * one becomes dispatchable again:
 *
 *  1. Stale-pending backstop (§10): pending ledgers untouched for
 *     vlm.pending_stale_hours — attempts=0 → deleted (unconsumed,
 *     retried); attempts>0 → skipped_provider (money spent, nothing
 *     learned — consumed; a model_version bump or new C run re-opens).
 *  2. Catch-up: latest flagged-but-unconsumed visual runs in the window
 *     → dispatch VlmVerificationJob WITHOUT a correlation id (the job
 *     mints its own and stamps trigger_reason = sweep-catchup). Budget
 *     enforcement lives in the jobs — a backfill can never blow the
 *     budget. Deferred skips wrote NO row, so they reappear here.
 *  3. DEF-021 discovery: shipped in-window posts whose visual outcome is
 *     missing or skipped_* → append-only 'unverifiable' rows — NEVER
 *     sent to Gemini (zero frames = nothing to look at). "We could not
 *     look" is recorded as a fact, never as product absence.
 *
 * Runs tenant-less: eligibility queries use explicit tenant_id predicates
 * with global scopes removed (the ADR-0025 command convention); per-row
 * writes run under TenantContext::runAs so every row stamps its owner.
 */
class VlmVerifySweepCommand extends Command
{
    protected $signature = 'qds:vlm-verify {--days=30} {--tenant=} {--dry-run}';

    protected $description = 'Dispatch VLM verification for flagged visual-match runs, record DEF-021 unverifiable posts, finalize stale pending runs';

    /**
     * C's skipped outcomes: the run looked at NOTHING (the C recorder
     * guarantees needs_verification = false on them) — DEF-021 discovery
     * territory, not catch-up territory.
     */
    private const SKIPPED_OUTCOMES = [
        VisualMatchOutcome::SkippedBudget,
        VisualMatchOutcome::SkippedReadOnly,
        VisualMatchOutcome::SkippedProvider,
    ];

    private VlmRunRecorder $recorder;

    private CandidateScope $candidates;

    private TenantContext $context;

    private string $modelVersion;

    private string $correlationId;

    private bool $dryRun = false;

    public function handle(VlmRunRecorder $recorder, CandidateScope $candidates, TenantContext $context): int
    {
        if (! (bool) config('qds.enrichment.vlm.enabled')) {
            $this->warn('VLM verification is disabled (qds.enrichment.vlm.enabled) — nothing to do.');

            return self::SUCCESS;
        }

        if (! (bool) config('qds.enrichment.visual_match.enabled')) {
            $this->warn("VLM verification requires visual matching (qds.enrichment.visual_match.enabled) — D verifies C's candidates.");

            return self::SUCCESS;
        }

        $this->recorder = $recorder;
        $this->candidates = $candidates;
        $this->context = $context;
        $this->modelVersion = (string) config('qds.enrichment.vlm.model_version');
        $this->correlationId = (string) Str::uuid();
        $this->dryRun = (bool) $this->option('dry-run');

        $days = max(1, (int) $this->option('days'));
        $since = CarbonImmutable::now()->subDays($days);

        $tenantIds = $this->option('tenant') !== null
            ? [(int) $this->option('tenant')]
            : Tenant::query()->orderBy('id')->pluck('id')->map(static fn ($id): int => (int) $id)->all();

        $this->info("VLM verification sweep over the last {$days} day(s) [correlation {$this->correlationId}].");

        $totals = ['finalized' => 0, 'deleted' => 0, 'dispatched' => 0, 'unverifiable' => 0];

        foreach ($tenantIds as $tenantId) {
            [$finalized, $deleted] = $this->finalizeStalePending($tenantId);
            $dispatched = $this->dispatchCatchup($tenantId, $since);
            $unverifiable = $this->discoverUnverifiable($tenantId, $since);

            $totals['finalized'] += $finalized;
            $totals['deleted'] += $deleted;
            $totals['dispatched'] += $dispatched;
            $totals['unverifiable'] += $unverifiable;

            // One metric per line: Laravel's expectsOutputToContain is
            // Mockery-backed and routes each written line to exactly ONE
            // matching substring expectation, so several asserted substrings
            // cannot share a single output line (the dry-run test asserts all
            // three at once).
            if ($this->dryRun) {
                $this->line(sprintf('Tenant %d [dry-run]: would finalize %d and delete %d stale pending row(s).', $tenantId, $finalized, $deleted));
                $this->line(sprintf('Tenant %d [dry-run]: would dispatch %d job(s).', $tenantId, $dispatched));
                $this->line(sprintf('Tenant %d [dry-run]: would record %d unverifiable post(s).', $tenantId, $unverifiable));
            } else {
                $this->line(sprintf('Tenant %d: finalized %d and deleted %d stale pending row(s).', $tenantId, $finalized, $deleted));
                $this->line(sprintf('Tenant %d: dispatched %d job(s).', $tenantId, $dispatched));
                $this->line(sprintf('Tenant %d: recorded %d unverifiable post(s).', $tenantId, $unverifiable));
            }
        }

        if ($this->dryRun) {
            $this->info('Dry run — nothing executed.');

            return self::SUCCESS;
        }

        $this->info(sprintf(
            'Sweep done: %d job(s) dispatched, %d unverifiable post(s) recorded, %d stale row(s) finalized, %d deleted.',
            $totals['dispatched'],
            $totals['unverifiable'],
            $totals['finalized'],
            $totals['deleted'],
        ));

        return self::SUCCESS;
    }

    /**
     * §10 crash backstop. Staleness reads updated_at (a resumed execution
     * touches the row; the longest queue backoff is 1800 s, far inside
     * the 6 h default). attempts=0 → nothing billed → delete (unconsume);
     * attempts>0 → skipped_provider (consumed — never silent re-billing).
     *
     * @return array{0: int, 1: int} [finalized, deleted]
     */
    private function finalizeStalePending(int $tenantId): array
    {
        $staleHours = max(1, (int) config('qds.enrichment.vlm.pending_stale_hours'));
        $cutoff = CarbonImmutable::now()->subHours($staleHours);

        $stale = VlmVerificationRun::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('outcome', VlmRunOutcome::Pending->value)
            ->where('updated_at', '<', $cutoff)
            ->orderBy('id')
            ->get();

        $finalized = 0;
        $deleted = 0;

        foreach ($stale as $run) {
            if ((int) $run->attempts === 0) {
                $deleted++;

                if (! $this->dryRun) {
                    $this->context->runAs($tenantId, fn () => $this->recorder->deleteUnbilled($run));
                }

                continue;
            }

            $finalized++;

            if (! $this->dryRun) {
                $this->context->runAs($tenantId, fn () => $this->recorder->finalize(
                    $run,
                    VlmRunOutcome::SkippedProvider,
                    null,
                    [],
                    null,
                    null,
                    null,
                    0,
                    'stale-pending',
                ));
            }
        }

        return [$finalized, $deleted];
    }

    /**
     * Catch-up pass: flagged latest runs in the window without a TERMINAL
     * vlm row at the current model version. PENDING rows do not block —
     * the resumed job needs its dispatch back after a crash; ShouldBeUnique
     * dedups an actually-queued twin.
     */
    private function dispatchCatchup(int $tenantId, CarbonImmutable $since): int
    {
        $flagged = VisualMatchRun::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('needs_verification', true)
            ->where('created_at', '>=', $since)
            ->orderBy('id')
            ->get();

        $dispatched = 0;

        foreach ($flagged as $run) {
            $ownerColumn = $run->content_item_id !== null ? 'content_item_id' : 'story_id';
            $ownerId = (int) ($run->content_item_id ?? $run->story_id);

            // "Latest run per post = max id" — C's index contract.
            $latestId = (int) VisualMatchRun::query()
                ->withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where($ownerColumn, $ownerId)
                ->max('id');

            if ($latestId !== (int) $run->id) {
                continue; // superseded flag — the newer run is authoritative
            }

            $consumed = VlmVerificationRun::query()
                ->withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('visual_match_run_id', $run->id)
                ->where('model_version', $this->modelVersion)
                ->whereNot('outcome', VlmRunOutcome::Pending->value)
                ->exists();

            if ($consumed) {
                continue;
            }

            $dispatched++;

            if (! $this->dryRun) {
                // No correlation id: the job mints its own and stamps the
                // run row trigger_reason = sweep-catchup (frozen rule).
                VlmVerificationJob::dispatch($ownerColumn === 'content_item_id' ? 'content' : 'story', $ownerId);
            }
        }

        return $dispatched;
    }

    /**
     * DEF-021 discovery (§4): shipped in-window posts whose visual outcome
     * is missing (no run row at all — frameless / no-creator-at-the-time /
     * disabled) or skipped_*. Recorded as append-only 'unverifiable' rows
     * with a NULL anchor; the recorder dedups on (owner, trigger_reason).
     * NEVER dispatches, NEVER calls a provider — zero spend by design.
     */
    private function discoverUnverifiable(int $tenantId, CarbonImmutable $since): int
    {
        $recorded = 0;

        $sweeps = [
            [ContentItem::class, 'published_at', 'content_item_id'],
            [Story::class, 'captured_at', 'story_id'],
        ];

        foreach ($sweeps as [$model, $publishedColumn, $ownerColumn]) {
            $ids = $model::query()
                ->withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where($publishedColumn, '>=', $since)
                ->whereHas('platformAccount', static function ($query) use ($tenantId): void {
                    $query->withoutGlobalScopes()
                        ->where('platform_accounts.tenant_id', $tenantId)
                        ->whereNotNull('creator_id');
                })
                ->orderBy('id')
                ->pluck('id')
                ->map(static fn ($id): int => (int) $id)
                ->all();

            foreach ($ids as $id) {
                $recorded += (int) $this->context->runAs($tenantId, function () use ($model, $id, $ownerColumn): int {
                    /** @var ContentItem|Story $target */
                    $target = $model::query()->findOrFail($id);

                    $latest = VisualMatchRun::query()
                        ->where($ownerColumn, $target->id)
                        ->orderByDesc('id')
                        ->first();

                    if ($latest !== null && ! in_array($latest->outcome, self::SKIPPED_OUTCOMES, true)) {
                        return 0; // a real match attempt exists — catch-up territory
                    }

                    // Discovery covers SHIPPED posts only: an in-window
                    // shipment means "we should have looked and could not".
                    if (! $this->candidates->forTarget($target)->hasInWindowShipment()) {
                        return 0;
                    }

                    $reason = $latest === null
                        ? VlmTriggerReason::UnverifiableNoRun
                        : VlmTriggerReason::UnverifiableSkippedRun;

                    if ($this->dryRun) {
                        // Count what recordUnverifiable WOULD write (dedup-aware).
                        return VlmVerificationRun::query()
                            ->whereNull('visual_match_run_id')
                            ->where($ownerColumn, $target->id)
                            ->where('trigger_reason', $reason->value)
                            ->exists() ? 0 : 1;
                    }

                    return $this->recorder->recordUnverifiable($target, $reason, $this->modelVersion, $this->correlationId) !== null ? 1 : 0;
                });
            }
        }

        return $recorded;
    }
}
