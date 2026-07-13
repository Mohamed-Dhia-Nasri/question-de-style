<?php

namespace App\Platform\Ingestion\Jobs;

use App\Modules\CRM\Models\PlatformAccount;
use App\Platform\Ingestion\DTO\StoryData;
use App\Platform\Ingestion\Exceptions\ProviderCallException;
use App\Platform\Ingestion\Jobs\Concerns\IngestionJobBehaviour;
use App\Platform\Ingestion\Observability\ProviderCallRecorder;
use App\Platform\Ingestion\Observability\ProviderCircuitBreaker;
use App\Platform\Ingestion\Persistence\StoryPersister;
use App\Platform\Ingestion\Providers\ProviderResolver;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Ingests one Instagram account's live stories before platform expiry
 * (REQ-M1-004, AC-M1-005) via SRC-apify-instagram-story-details. New
 * stories get an ArchiveStoryMediaJob that downloads the media into
 * PRIVATE object storage — the job itself carries only ids and the public
 * CDN URL, never blobs.
 */
class IngestStoriesJob implements ShouldQueue
{
    use IngestionJobBehaviour;
    use Queueable;

    public int $tries;

    public int $timeout = 300;

    public ?string $source = null;

    public function __construct(
        public readonly int $platformAccountId,
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

        $account = PlatformAccount::query()->find($this->platformAccountId);

        if ($account === null) {
            $this->completeCycleSlot(failed: false);

            return;
        }

        $providers = $resolver->storyProviders($account->platform);

        if ($providers === []) {
            // No story capability on this platform in v1 (matrix §2.1).
            $this->completeCycleSlot(failed: false);

            return;
        }

        // ADR-0019: scheduled cycles run tenant-less; the account row is the
        // aggregate root — its tenant scopes persistence AND the archival
        // dispatches (whose payloads then carry the tenant to the worker).
        app(TenantContext::class)->runAs(
            $account->tenant_id,
            fn () => $this->ingestForAccount($account, $providers, $recorder, $persister, $breaker),
        );
    }

    /** @param  list<object>  $providers */
    private function ingestForAccount(
        PlatformAccount $account,
        array $providers,
        ProviderCallRecorder $recorder,
        StoryPersister $persister,
        ProviderCircuitBreaker $breaker,
    ): void {
        $transientFailure = null;
        $allFailed = true;

        foreach ($providers as $provider) {
            $this->source = $provider->source();

            if ($this->attempts() > 1
                && $this->alreadySucceeded($account->id, $provider->source(), 'stories.fetch')) {
                $allFailed = false;

                continue;
            }

            if ($breaker->shouldSkip($provider->source())) {
                continue;
            }

            $context = $recorder->start(
                source: $provider->source(),
                operation: 'stories.fetch',
                correlationId: $this->correlationId,
                jobId: $this->job?->uuid(),
                platformAccountId: $account->id,
                retryCount: max(0, $this->attempts() - 1),
            );

            try {
                $batch = $provider->fetchStories($account->handle);
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

            ['result' => $result, 'toArchive' => $toArchive] = $persister->persist($account, $items);

            $recorder->recordCompletion($context, $batch, $result);

            foreach ($toArchive as $archival) {
                ArchiveStoryMediaJob::dispatch(
                    $archival['story']->id,
                    $archival['mediaSourceUrl'],
                    $this->correlationId,
                );
            }

            $allFailed = false;
        }

        if ($transientFailure !== null && $allFailed) {
            $this->handleProviderFailure($transientFailure);

            return;
        }

        $this->completeCycleSlot(failed: $allFailed);
    }
}
