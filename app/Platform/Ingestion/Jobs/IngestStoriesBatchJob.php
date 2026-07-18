<?php

namespace App\Platform\Ingestion\Jobs;

use App\Modules\CRM\Models\PlatformAccount;
use App\Platform\Ingestion\Contracts\BatchStoryProvider;
use App\Platform\Ingestion\DTO\StoryData;
use App\Platform\Ingestion\Exceptions\ProviderCallException;
use App\Platform\Ingestion\Jobs\Concerns\IngestionJobBehaviour;
use App\Platform\Ingestion\Observability\ProviderCallRecorder;
use App\Platform\Ingestion\Observability\ProviderCircuitBreaker;
use App\Platform\Ingestion\Persistence\PersistenceResult;
use App\Platform\Ingestion\Persistence\StoryPersister;
use App\Platform\Ingestion\Providers\ProviderResolver;
use App\Shared\Enums\Platform;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Ingests live stories for a CHUNK of roster accounts in ONE actor run
 * (REQ-M1-004; cost plan rec 3): the story actor bills a per-run start fee
 * that dwarfs its per-username fee, so the story cycle sends batches of
 * qds.ingestion.story_batch_size handles instead of one run per account —
 * ~92% cheaper at roster scale.
 *
 * Items are attributed back to accounts via StoryData::$ownerHandle
 * (case-insensitive). A single-account batch attributes ownerless items to
 * that account; in larger batches ownerless items are dropped with a log —
 * misattributing a story across creators would be worse than missing it.
 *
 * The ProviderCall row carries platform_account_id NULL (it spans the
 * chunk). One chunk = one cycle slot.
 */
class IngestStoriesBatchJob implements ShouldQueue
{
    use IngestionJobBehaviour;
    use Queueable;

    public int $tries;

    /** Async actor runs poll up to services.apify.async_timeout. */
    public int $timeout = 1200;

    public ?string $source = null;

    /**
     * @param  list<int>  $platformAccountIds
     */
    public function __construct(
        public readonly array $platformAccountIds,
        public readonly ?int $cycleId,
        public readonly string $correlationId,
    ) {
        $this->onQueue('ingestion');
        $this->tries = $this->configuredTries();
    }

    public function handle(
        ProviderResolver $resolver,
        ProviderCallRecorder $recorder,
        StoryPersister $persister,
        ProviderCircuitBreaker $breaker,
    ): void {
        $this->attachLogContext();

        /** @var list<PlatformAccount> $accounts */
        $accounts = PlatformAccount::query()
            ->whereIn('id', $this->platformAccountIds)
            ->where('platform', Platform::Instagram->value)
            ->get()
            ->all();

        if ($accounts === []) {
            $this->completeCycleSlot(failed: false);

            return;
        }

        $providers = array_values(array_filter(
            $resolver->storyProviders(Platform::Instagram),
            fn (object $provider): bool => $provider instanceof BatchStoryProvider,
        ));

        if ($providers === []) {
            Log::warning('qds.ingestion: story batch skipped — no batch-capable story provider is bound.');
            $this->completeCycleSlot(failed: true);

            return;
        }

        $handles = array_map(fn (PlatformAccount $a): string => $a->handle, $accounts);

        $transientFailure = null;
        $allFailed = true;

        foreach ($providers as $provider) {
            $this->source = $provider->source();

            if ($breaker->shouldSkip($provider->source())) {
                continue;
            }

            $context = $recorder->start(
                source: $provider->source(),
                operation: 'stories.fetch',
                correlationId: $this->correlationId,
                jobId: $this->job?->uuid(),
                platformAccountId: null,
                retryCount: max(0, $this->attempts() - 1),
            );

            try {
                $batch = $provider->fetchStoriesForHandles($handles);
            } catch (Throwable $e) {
                $recorder->recordFailure($context, $e);

                if ($e instanceof ProviderCallException && $e->category->isTransient()) {
                    $transientFailure = $e;
                }

                continue;
            }

            /** @var list<StoryData> $items */
            $items = array_values(array_filter(
                $batch->items,
                fn (object $item): bool => $item instanceof StoryData,
            ));

            $recorder->recordCompletion($context, $batch, $this->persistPerAccount($accounts, $items, $persister));

            $allFailed = false;
        }

        if ($transientFailure !== null && $allFailed) {
            $this->handleProviderFailure($transientFailure);

            return;
        }

        $this->completeCycleSlot(failed: $allFailed);
    }

    /**
     * Group items by owner handle, persist per account, dispatch archival.
     *
     * @param  list<PlatformAccount>  $accounts
     * @param  list<StoryData>  $items
     */
    private function persistPerAccount(array $accounts, array $items, StoryPersister $persister): PersistenceResult
    {
        $byHandle = [];
        $unattributed = 0;

        foreach ($items as $item) {
            $owner = $item->ownerHandle !== null ? mb_strtolower($item->ownerHandle) : null;

            if ($owner === null && count($accounts) === 1) {
                $owner = mb_strtolower($accounts[0]->handle);
            }

            if ($owner === null) {
                $unattributed++;

                continue;
            }

            $byHandle[$owner][] = $item;
        }

        if ($unattributed > 0) {
            Log::warning('qds.ingestion: story batch items dropped — no owner handle to attribute them by.', [
                'dropped' => $unattributed,
            ]);
        }

        $created = 0;
        $duplicates = 0;
        $skipped = $unattributed;
        $persistenceMs = 0.0;
        $mediaMs = 0.0;

        foreach ($accounts as $account) {
            $accountItems = $byHandle[mb_strtolower($account->handle)] ?? [];

            if ($accountItems === []) {
                continue;
            }

            // ADR-0019: one batch spans MULTIPLE tenants' accounts — the
            // tenant context is established per account (never left ambient
            // across units of work) so persistence and the archival job
            // payloads stamp the owning account's tenant.
            $result = app(TenantContext::class)->runAs($account->tenant_id, function () use ($persister, $account, $accountItems): PersistenceResult {
                ['result' => $result, 'toArchive' => $toArchive] = $persister->persist($account, $accountItems);

                foreach ($toArchive as $archival) {
                    ArchiveStoryMediaJob::dispatch(
                        $archival['story']->id,
                        $archival['mediaSourceUrl'],
                        $this->correlationId,
                    );
                }

                return $result;
            });

            $created += $result->created;
            $duplicates += $result->duplicates;
            $skipped += $result->skipped;
            $persistenceMs += $result->persistenceMs;
            $mediaMs += $result->mediaMs;
        }

        return new PersistenceResult(
            created: $created,
            duplicates: $duplicates,
            skipped: $skipped,
            persistenceMs: $persistenceMs,
            mediaMs: $mediaMs,
            unattributed: $unattributed,
        );
    }
}
