<?php

namespace App\Platform\Ingestion\Jobs;

use App\Modules\CRM\Models\PlatformAccount;
use App\Platform\Ingestion\Contracts\PlatformAccountProfileSync;
use App\Platform\Ingestion\DTO\ProfileData;
use App\Platform\Ingestion\Jobs\Concerns\IngestionJobBehaviour;
use App\Platform\Ingestion\Observability\ProviderCallRecorder;
use App\Platform\Ingestion\Observability\ProviderCircuitBreaker;
use App\Platform\Ingestion\Persistence\PersistenceResult;
use App\Platform\Ingestion\Providers\ProviderResolver;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
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

    /**
     * Serialize the billable profile call per account (M23); keyed per
     * operation. See IngestContentJob::middleware.
     *
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('qds-account-profile:'.$this->platformAccountId))
                ->releaseAfter(60)
                ->expireAfter(600),
        ];
    }

    public function handle(
        ProviderResolver $resolver,
        ProviderCallRecorder $recorder,
        PlatformAccountProfileSync $profileSync,
        ProviderCircuitBreaker $breaker,
    ): void {
        $this->attachLogContext();

        $account = PlatformAccount::query()->find($this->platformAccountId);

        if ($account === null) {
            $this->completeCycleSlot(failed: false);

            return;
        }

        // ADR-0019: scheduled cycles run tenant-less; derive the tenant from
        // the account row (the aggregate root) for this whole unit of work.
        app(TenantContext::class)->runAs(
            $account->tenant_id,
            fn () => $this->ingestForAccount($account, $resolver, $recorder, $profileSync, $breaker),
        );
    }

    private function ingestForAccount(
        PlatformAccount $account,
        ProviderResolver $resolver,
        ProviderCallRecorder $recorder,
        PlatformAccountProfileSync $profileSync,
        ProviderCircuitBreaker $breaker,
    ): void {
        $provider = $resolver->profileProvider($account->platform);
        $this->source = $provider->source();

        if ($this->attempts() > 1
            && $this->alreadySucceeded($account->id, $provider->source(), 'profile.fetch')) {
            $this->completeCycleSlot(failed: false);

            return;
        }

        if ($breaker->shouldSkip($provider->source())) {
            // Permanent-failure breaker open (cost plan rec 9): no call, no
            // billing; the slot completes as failed so the cycle stays honest.
            $this->completeCycleSlot(failed: true);

            return;
        }

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
