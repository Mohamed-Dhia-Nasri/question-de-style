<?php

namespace App\Platform\Ingestion\Console;

use App\Platform\Ingestion\Models\ProviderCall;
use App\Platform\Ingestion\Models\ProviderResponseSample;
use App\Platform\Ingestion\Models\QuarantinedRecord;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Retention enforcement for operational ingestion data (DP-005 retention
 * limits; response samples have a SHORT retention by design): expired
 * response samples and quarantined records are deleted, and provider-call
 * telemetry is pruned past its retention window.
 */
class PruneIngestionDataCommand extends Command
{
    protected $signature = 'qds:prune-ingestion-data';

    protected $description = 'Prune expired response samples, quarantined records, and old provider-call telemetry';

    public function handle(): int
    {
        $now = CarbonImmutable::now();

        $samples = ProviderResponseSample::query()->where('expires_at', '<=', $now)->delete();
        $quarantined = QuarantinedRecord::query()->where('expires_at', '<=', $now)->delete();

        $telemetryDays = max(1, (int) config('qds.ingestion.telemetry_retention_days'));
        $calls = ProviderCall::query()
            ->where('started_at', '<', $now->subDays($telemetryDays))
            ->delete();

        $this->info("Pruned {$samples} response samples, {$quarantined} quarantined records, {$calls} provider calls.");

        return self::SUCCESS;
    }
}
