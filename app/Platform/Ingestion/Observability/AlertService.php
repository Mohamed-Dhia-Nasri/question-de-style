<?php

namespace App\Platform\Ingestion\Observability;

use App\Platform\Ingestion\Models\IngestionAlert;
use App\Platform\Ingestion\Support\AlertType;
use Carbon\CarbonImmutable;

/**
 * Deduplicated alerting for ingestion incidents. One OPEN alert per
 * (type, source, tenant) fingerprint — repeats bump `count`/`last_occurred_at`
 * instead of creating noise. Messages MUST already be sanitized (no
 * provider secrets, no raw payloads).
 *
 * Tenant attribution (ADR-0019) is an EXPLICIT opt-in, never read from the
 * ambient context. This matters because provider-level incidents (repeated
 * failures, stale data, story-polling risk, job failures) are frequently
 * raised from INSIDE a per-account ingestion/enrichment job that already runs
 * under runAs(account tenant): reading the ambient tenant would wrongly stamp
 * a SHARED-provider outage with whichever tenant's poll happened to trip it,
 * fragmenting dedup and hiding it from every other tenant's operations view.
 * So those callers pass nothing → tenant_id NULL → global, visible to all.
 * Only genuinely per-tenant alerts (the P4 data-quality scan, which embeds a
 * tenant's own creator handles) pass an explicit $tenantId so an operator
 * sees only their own, never a competitor's roster.
 */
class AlertService
{
    public function raise(AlertType $type, ?string $source, string $message, string $severity = 'warning', ?int $tenantId = null): IngestionAlert
    {
        $fingerprint = $this->fingerprint($type, $source, $tenantId);

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
            'tenant_id' => $tenantId,
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

    /**
     * Resolve the open alert for (type, source) — for the given tenant when
     * one is supplied (per-tenant scans), else the global one.
     */
    public function resolve(AlertType $type, ?string $source, ?int $tenantId = null): void
    {
        IngestionAlert::query()
            ->where('fingerprint', $this->fingerprint($type, $source, $tenantId))
            ->whereNull('resolved_at')
            ->update(['resolved_at' => CarbonImmutable::now()]);
    }

    private function fingerprint(AlertType $type, ?string $source, ?int $tenantId): string
    {
        return sha1($type->value.'|'.($source ?? '-').'|'.($tenantId ?? '-'));
    }
}
