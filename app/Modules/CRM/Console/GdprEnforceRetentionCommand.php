<?php

namespace App\Modules\CRM\Console;

use App\Models\Tenant;
use App\Modules\CRM\Models\CommunicationLog;
use App\Shared\Settings\MonitoringSettingsResolver;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * GDPR retention enforcement (P4 hardening, DP-005 retention limits):
 *
 *  - communication logs (free-form correspondence — the longest-lived PII
 *    the CRM accumulates) are deleted past their retention window, resolved
 *    PER TENANT (ADR-0025) — config default of 0 keeps history forever for
 *    tenants that never saved Settings → Monitoring;
 *  - finished GDPR export files (gdpr/ on the exports disk) are deleted
 *    past the standard export TTL — they contain the FULL data-subject
 *    dossier and must not accumulate.
 */
class GdprEnforceRetentionCommand extends Command
{
    protected $signature = 'qds:gdpr-enforce-retention';

    protected $description = 'Enforce GDPR retention limits: old communication logs and leftover GDPR export files (DP-005)';

    public function handle(MonitoringSettingsResolver $settings): int
    {
        $logs = $this->pruneCommunicationLogs($settings);
        $files = $this->pruneGdprExportFiles();

        $this->info("Pruned {$logs} communication logs and {$files} GDPR export files.");

        return self::SUCCESS;
    }

    private function pruneCommunicationLogs(MonitoringSettingsResolver $settings): int
    {
        $pruned = 0;

        // ADR-0025: per-tenant keep-time; 0 = keep forever for that tenant.
        foreach (Tenant::query()->pluck('id') as $tenantId) {
            $retentionDays = $settings->communicationRetentionDaysFor((int) $tenantId);

            if ($retentionDays <= 0) {
                continue;
            }

            $pruned += CommunicationLog::query()
                ->withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('occurred_at', '<', CarbonImmutable::now()->subDays($retentionDays))
                ->delete();
        }

        return $pruned;
    }

    private function pruneGdprExportFiles(): int
    {
        $disk = Storage::disk((string) config('qds.exports.disk'));
        $expiredBefore = CarbonImmutable::now()
            ->subHours((int) config('qds.exports.ttl_hours'))
            ->getTimestamp();

        $deleted = 0;

        // Legacy pre-tenancy location AND the per-tenant locations
        // (ADR-0019: new exports land under tenants/{id}/gdpr/) — dossiers
        // must never outlive the TTL wherever they were written.
        $directories = ['gdpr'];

        foreach ($disk->directories('tenants') as $tenantDir) {
            $directories[] = $tenantDir.'/gdpr';
        }

        foreach ($directories as $directory) {
            foreach ($disk->files($directory) as $file) {
                if ($disk->lastModified($file) < $expiredBefore && $disk->delete($file)) {
                    $deleted++;
                }
            }
        }

        return $deleted;
    }
}
