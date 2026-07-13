<?php

namespace App\Modules\CRM\Console;

use App\Modules\CRM\Models\CommunicationLog;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * GDPR retention enforcement (P4 hardening, DP-005 retention limits):
 *
 *  - communication logs (free-form correspondence — the longest-lived PII
 *    the CRM accumulates) are deleted past their retention window. The
 *    period is NOT canonically decided (same flagged class as the other
 *    cadences) — 0 disables it until an ADR fixes the policy;
 *  - finished GDPR export files (gdpr/ on the exports disk) are deleted
 *    past the standard export TTL — they contain the FULL data-subject
 *    dossier and must not accumulate.
 */
class GdprEnforceRetentionCommand extends Command
{
    protected $signature = 'qds:gdpr-enforce-retention';

    protected $description = 'Enforce GDPR retention limits: old communication logs and leftover GDPR export files (DP-005)';

    public function handle(): int
    {
        $logs = $this->pruneCommunicationLogs();
        $files = $this->pruneGdprExportFiles();

        $this->info("Pruned {$logs} communication logs and {$files} GDPR export files.");

        return self::SUCCESS;
    }

    private function pruneCommunicationLogs(): int
    {
        $retentionDays = (int) config('qds.gdpr.communication_log_retention_days');

        if ($retentionDays <= 0) {
            return 0; // disabled until a retention ADR fixes the period
        }

        return CommunicationLog::query()
            ->where('occurred_at', '<', CarbonImmutable::now()->subDays($retentionDays))
            ->delete();
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
