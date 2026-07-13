<?php

namespace App\Platform\Ingestion;

use App\Modules\CRM\Models\PlatformAccount;
use App\Platform\Ingestion\Contracts\IngestionService;
use App\Platform\Ingestion\Jobs\IngestContentJob;
use App\Platform\Ingestion\Jobs\IngestProfileJob;
use App\Platform\Ingestion\Jobs\IngestStoriesJob;
use App\Platform\Ingestion\Jobs\RunCreatorCycleJob;
use App\Platform\Ingestion\Jobs\RunMonitoringCycleJob;
use App\Shared\Enums\Platform;
use Illuminate\Support\Str;

/**
 * SVC-Ingestion (L2). Polls ONLY roster accounts that already exist —
 * ENT-PlatformAccount and ENT-Creator are Module 3 CRM's to create
 * (ownership matrix); profile data flows back through the
 * PlatformAccountProfileSync cross-module contract. No open-web keyword or
 * hashtag listening exists here (DEF-006, ADR-0011).
 */
class DefaultIngestionService implements IngestionService
{
    public function ingestPlatformAccount(Platform $platform, string $handle): void
    {
        /** @var PlatformAccount $account */
        $account = PlatformAccount::query()
            ->where('platform', $platform->value)
            ->where('handle', $handle)
            ->firstOrFail();

        $correlationId = (string) Str::uuid();

        IngestProfileJob::dispatchSync($account->id, null, $correlationId);
        IngestContentJob::dispatchSync($account->id, null, $correlationId);

        if ($platform === Platform::Instagram) {
            IngestStoriesJob::dispatchSync($account->id, null, $correlationId);
        }
    }

    public function startMonitoringCycle(bool $storiesOnly = false): void
    {
        RunMonitoringCycleJob::dispatch($storiesOnly);
    }

    public function startCreatorCycle(int $creatorId): void
    {
        RunCreatorCycleJob::dispatch($creatorId);
    }
}
