<?php

namespace App\Platform\Enrichment\Console;

use App\Models\Tenant;
use App\Modules\Monitoring\Models\Keyframe;
use App\Shared\Settings\MonitoringSettingsResolver;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Derived-media lifecycle (sub-project B, DP-005 retention limits):
 * persisted keyframes older than the per-tenant keep-time are deleted —
 * file first, row only once the blob is confirmed gone (the story-media
 * M31 pattern). Unlike stories, the ROW goes too: a keyframe row without
 * its file is meaningless to tiers C/D.
 */
class PruneKeyframesCommand extends Command
{
    protected $signature = 'qds:prune-keyframes';

    protected $description = 'Delete persisted keyframes past the retention window (DP-005)';

    public function handle(MonitoringSettingsResolver $settings): int
    {
        $pruned = 0;

        // ADR-0025: retention is per tenant. The scheduler runs tenant-less
        // (TenantScope is a no-op), so ownership is an EXPLICIT predicate.
        foreach (Tenant::query()->pluck('id') as $tenantId) {
            $retentionDays = $settings->keyframeRetentionDaysFor((int) $tenantId);

            if ($retentionDays <= 0) {
                continue; // this workspace keeps keyframes forever
            }

            $cutoff = CarbonImmutable::now()->subDays($retentionDays);

            Keyframe::query()
                ->withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('created_at', '<', $cutoff)
                ->chunkById(200, function ($keyframes) use (&$pruned): void {
                    foreach ($keyframes as $keyframe) {
                        $disk = Storage::disk((string) $keyframe->storage_disk);
                        $path = (string) $keyframe->storage_path;

                        try {
                            $deleted = $disk->delete($path);
                        } catch (\Throwable) {
                            // Some disks throw instead of returning false.
                            $deleted = false;
                        }

                        // Row goes only once the blob is confirmed gone (M31).
                        if (! $deleted && $disk->exists($path)) {
                            continue;
                        }

                        $keyframe->delete();
                        $pruned++;
                    }
                });
        }

        $this->info("Pruned {$pruned} keyframes past their workspace's keep-time.");

        return self::SUCCESS;
    }
}
