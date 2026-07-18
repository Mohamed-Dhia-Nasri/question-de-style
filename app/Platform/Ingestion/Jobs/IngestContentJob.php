<?php

namespace App\Platform\Ingestion\Jobs;

use App\Modules\CRM\Models\PlatformAccount;
use App\Platform\Enrichment\PerPullEnrichmentDispatcher;
use App\Platform\Ingestion\Contracts\PlatformAccountProfileSync;
use App\Platform\Ingestion\Contracts\ProvidesProfileFromContent;
use App\Platform\Ingestion\DTO\ContentData;
use App\Platform\Ingestion\Exceptions\ProviderCallException;
use App\Platform\Ingestion\Jobs\Concerns\IngestionJobBehaviour;
use App\Platform\Ingestion\Observability\ProviderCallRecorder;
use App\Platform\Ingestion\Observability\ProviderCircuitBreaker;
use App\Platform\Ingestion\Persistence\ContentItemPersister;
use App\Platform\Ingestion\Providers\ProviderResolver;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Throwable;

/**
 * Ingests one roster account's recent public content (REQ-M1-003):
 * posts/carousels/reels/videos/shorts via every frozen content provider
 * for the platform (Instagram uses two actors). Persistence is idempotent
 * on (platform, external_id); mutable public metrics refresh in place.
 *
 * Cost posture (reviews/PLAN-apify-cost-optimization-2026-07-07.md):
 * - $fullDepth threads the periodic no-date-filter sweep down to the
 *   adapters (rec 1); normal runs fetch only the refresh window.
 * - The replay guard skips providers that already succeeded for this
 *   correlation — a retry replays FAILED providers only, never re-billing
 *   the sibling that worked (rec 9).
 * - The circuit breaker skips providers FAILING with a permanent error
 *   (rec 9) instead of burning budget on a dead actor.
 * - Providers whose payload embeds the profile (TikTok authorMeta, rec 4)
 *   sync it here through the same CRM-owned contract IngestProfileJob
 *   uses — no separate profile call.
 *
 * Partial provider failure (requirement: "partial failure handling"): each
 * provider records its own ProviderCall; one provider failing never blocks
 * the others, and already-persisted data is never rolled back. The job
 * retries (safe replay — upserts) only for transient failures.
 */
class IngestContentJob implements ShouldQueue
{
    use IngestionJobBehaviour;
    use Queueable;

    public int $tries;

    public int $timeout = 600;

    public ?string $source = null;

    public function __construct(
        public readonly int $platformAccountId,
        public readonly ?int $cycleId,
        public readonly string $correlationId,
        public readonly bool $fullDepth = false,
    ) {
        $this->onQueue('ingestion');
        $this->tries = $this->configuredTries();
    }

    /**
     * Serialize the billable provider call + non-atomic upsert per account
     * (M23): an on-demand cycle and the scheduled cycle for the same account
     * would otherwise double-bill and collide on the content unique index.
     * Keyed per operation so content/stories/profile still run in parallel.
     * releaseAfter re-queues the loser (never drops it, or the cycle wedges);
     * expireAfter exceeds $timeout so the lock can't expire mid-run.
     *
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('qds-account-content:'.$this->platformAccountId))
                ->releaseAfter(60)
                ->expireAfter(1200),
        ];
    }

    public function handle(
        ProviderResolver $resolver,
        ProviderCallRecorder $recorder,
        ContentItemPersister $persister,
        ProviderCircuitBreaker $breaker,
        PlatformAccountProfileSync $profileSync,
    ): void {
        $this->attachLogContext();

        $account = PlatformAccount::query()->find($this->platformAccountId);

        if ($account === null) {
            $this->completeCycleSlot(failed: false);

            return;
        }

        // ADR-0019: scheduled cycles run tenant-less; the account row is the
        // aggregate root — its tenant scopes this whole unit of work so the
        // persister and BelongsToTenant stamp new rows correctly.
        app(TenantContext::class)->runAs(
            $account->tenant_id,
            fn () => $this->ingestForAccount($account, $resolver, $recorder, $persister, $breaker, $profileSync),
        );
    }

    private function ingestForAccount(
        PlatformAccount $account,
        ProviderResolver $resolver,
        ProviderCallRecorder $recorder,
        ContentItemPersister $persister,
        ProviderCircuitBreaker $breaker,
        PlatformAccountProfileSync $profileSync,
    ): void {
        $transientFailure = null;
        $allFailed = true;

        foreach ($resolver->contentProviders($account->platform) as $provider) {
            $this->source = $provider->source();

            if ($this->attempts() > 1
                && $this->alreadySucceeded($account->id, $provider->source(), 'content.fetch')) {
                $allFailed = false;

                continue;
            }

            if ($breaker->shouldSkip($provider->source())) {
                continue;
            }

            $context = $recorder->start(
                source: $provider->source(),
                operation: 'content.fetch',
                correlationId: $this->correlationId,
                jobId: $this->job?->uuid(),
                platformAccountId: $account->id,
                retryCount: max(0, $this->attempts() - 1),
            );

            try {
                $batch = $provider->fetchContent($account->handle, $this->fullDepth);
            } catch (Throwable $e) {
                $recorder->recordFailure($context, $e);

                if ($e instanceof ProviderCallException && ($e->category->isTransient())) {
                    $transientFailure = $e;
                }

                continue;
            }

            /** @var list<ContentData> $items */
            $items = array_values(array_filter(
                $batch->items,
                fn (object $item): bool => $item instanceof ContentData,
            ));

            $result = $persister->persist($account, $items);

            $recorder->recordCompletion($context, $batch, $result);

            // ADR-0023: the AI check follows the pull — newly created rows
            // only (refreshed duplicates never re-enrich).
            app(PerPullEnrichmentDispatcher::class)
                ->dispatchForContent($result->createdIds, $this->correlationId);

            if ($provider instanceof ProvidesProfileFromContent
                && ($profile = $provider->profileFromLastFetch()) !== null) {
                $profileSync->apply($account, $profile);
            }

            $allFailed = false;
        }

        if ($transientFailure !== null && $allFailed) {
            // Nothing succeeded and the failure is retryable — replay the
            // whole job (idempotent persistence + the replay guard make
            // replay safe and bill only the providers that failed).
            //
            // KNOWN GAP (review REVIEW-cost-optimization-2026-07-07, cost#7):
            // when a SIBLING succeeded (posts OK, reels transient-fail) this
            // does not retry, so the failed sibling's content is abandoned
            // until the next cadence run. Fixing it means dropping `&&
            // $allFailed` and leaning on the alreadySucceeded replay guard —
            // deferred to a focused change with its own retry-behaviour test.
            $this->handleProviderFailure($transientFailure);

            return;
        }

        $this->completeCycleSlot(failed: $allFailed);
    }
}
