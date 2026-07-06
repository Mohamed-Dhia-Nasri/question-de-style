<?php

namespace App\Platform\Ingestion\Jobs;

use App\Modules\CRM\Models\PlatformAccount;
use App\Platform\Ingestion\Contracts\PlatformAccountProfileSync;
use App\Platform\Ingestion\DTO\ProfileData;
use App\Platform\Ingestion\Jobs\Concerns\IngestionJobBehaviour;
use App\Platform\Ingestion\Observability\ProviderCallRecorder;
use App\Platform\Ingestion\Persistence\PersistenceResult;
use App\Platform\Ingestion\Providers\ProviderResolver;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Ingests one roster account's public profile (REQ-M1-001/005): fetch via
 * the platform's frozen profile provider, normalize, and apply through the
 * CRM-owned PlatformAccountProfileSync contract (ownership matrix — M1
 * never writes ENT-PlatformAccount directly). Provenance is attached by
 * the adapter on every record (DP-002).
 */
class IngestProfileJob implements ShouldQueue
{
    use IngestionJobBehaviour;
    use Queueable;

    public int $tries = 4;

    public int $timeout = 300;

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
        PlatformAccountProfileSync $profileSync,
    ): void {
        $this->attachLogContext();

        $account = PlatformAccount::query()->find($this->platformAccountId);

        if ($account === null) {
            $this->completeCycleSlot(failed: false);

            return;
        }

        $provider = $resolver->profileProvider($account->platform);
        $this->source = $provider->source();

        $context = $recorder->start(
            source: $provider->source(),
            operation: 'profile.fetch',
            correlationId: $this->correlationId,
            jobId: $this->job?->uuid(),
            platformAccountId: $account->id,
            retryCount: max(0, $this->attempts() - 1),
        );

        try {
            $batch = $provider->fetchProfile($account->handle);
        } catch (Throwable $e) {
            $recorder->recordFailure($context, $e);
            $this->handleProviderFailure($e);

            return;
        }

        $profile = collect($batch->items)->first(
            fn (object $item): bool => $item instanceof ProfileData,
        );

        $result = $profile instanceof ProfileData
            ? $profileSync->apply($account, $profile)
            : new PersistenceResult;

        $recorder->recordCompletion($context, $batch, $result);

        $this->completeCycleSlot(failed: false);
    }
}
