<?php

namespace App\Platform\Ingestion\Observability;

use App\Platform\Ingestion\Models\IngestionAlert;
use App\Platform\Ingestion\Support\AlertType;
use Carbon\CarbonImmutable;

/**
 * Deduplicated alerting for ingestion incidents. One OPEN alert per
 * (type, source) fingerprint — repeats bump `count`/`last_occurred_at`
 * instead of creating noise. Messages MUST already be sanitized (no
 * provider secrets, no raw payloads).
 */
class AlertService
{
    public function raise(AlertType $type, ?string $source, string $message, string $severity = 'warning'): IngestionAlert
    {
        $fingerprint = sha1($type->value.'|'.($source ?? '-'));

        $open = IngestionAlert::query()
            ->where('fingerprint', $fingerprint)
            ->whereNull('resolved_at')
            ->first();

        if ($open !== null) {
            $open->update([
                'message' => $message,
                'severity' => $severity,
                'count' => $open->count + 1,
                'last_occurred_at' => CarbonImmutable::now(),
            ]);

            return $open;
        }

        return IngestionAlert::query()->create([
            'alert_type' => $type,
            'source' => $source,
            'fingerprint' => $fingerprint,
            'severity' => $severity,
            'message' => $message,
            'count' => 1,
            'first_occurred_at' => CarbonImmutable::now(),
            'last_occurred_at' => CarbonImmutable::now(),
        ]);
    }

    /** Resolve the open alert for (type, source), if any. */
    public function resolve(AlertType $type, ?string $source): void
    {
        IngestionAlert::query()
            ->where('fingerprint', sha1($type->value.'|'.($source ?? '-')))
            ->whereNull('resolved_at')
            ->update(['resolved_at' => CarbonImmutable::now()]);
    }
}
