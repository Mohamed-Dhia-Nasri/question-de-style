<?php

namespace App\Platform\Ingestion\Jobs;

use App\Modules\CRM\Models\PlatformAccount;
use App\Platform\Ingestion\DTO\ContentData;
use App\Platform\Ingestion\Exceptions\ProviderCallException;
use App\Platform\Ingestion\Jobs\Concerns\IngestionJobBehaviour;
use App\Platform\Ingestion\Observability\ProviderCallRecorder;
use App\Platform\Ingestion\Persistence\ContentItemPersister;
use App\Platform\Ingestion\Providers\ProviderResolver;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Ingests one roster account's recent public content (REQ-M1-003):
 * posts/carousels/reels/videos/shorts via every frozen content provider
 * for the platform (Instagram uses two actors). Persistence is idempotent
 * on (platform, external_id); mutable public metrics refresh in place.
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

    public int $tries = 4;

    public int $timeout = 600;

    public ?string $source = null;

    public function __construct(
        public readonly int $platformAccountId,
        public readonly ?int $cycleId,
        public readonly string $correlationId,
    ) {
        $this->onQueue('ingestion');
    }

    public function handle(
        ProviderResolver $resolver,
        ProviderCallRecorder $recorder,
        ContentItemPersister $persister,
    ): void {
        $this->attachLogContext();

        $account = PlatformAccount::query()->find($this->platformAccountId);

        if ($account === null) {
            $this->completeCycleSlot(failed: false);

            return;
        }

        $transientFailure = null;
        $allFailed = true;

        foreach ($resolver->contentProviders($account->platform) as $provider) {
            $this->source = $provider->source();

            $context = $recorder->start(
                source: $provider->source(),
                operation: 'content.fetch',
                correlationId: $this->correlationId,
                jobId: $this->job?->uuid(),
                platformAccountId: $account->id,
                retryCount: max(0, $this->attempts() - 1),
            );

            try {
                $batch = $provider->fetchContent($account->handle);
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

            $allFailed = false;
        }

        if ($transientFailure !== null && $allFailed) {
            // Nothing succeeded and the failure is retryable — replay the
            // whole job (idempotent persistence makes replay safe).
            $this->handleProviderFailure($transientFailure);

            return;
        }

        $this->completeCycleSlot(failed: $allFailed);
    }
}
