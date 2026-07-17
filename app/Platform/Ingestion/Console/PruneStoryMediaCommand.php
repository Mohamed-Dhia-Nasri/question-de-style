<?php

namespace App\Platform\Ingestion\Console;

use App\Models\Tenant;
use App\Modules\Monitoring\Models\Story;
use App\Shared\Settings\MonitoringSettingsResolver;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Media storage lifecycle (P4 hardening, DP-005 retention limits): archived
 * story media older than the retention window is deleted from the private
 * media disk. The retention window is resolved PER TENANT (ADR-0025) — the
 * story row itself is kept — only the media file goes — with
 * `media_pruned_at` stamped so archival is never re-attempted.
 */
class PruneStoryMediaCommand extends Command
{
    protected $signature = 'qds:prune-story-media';

    protected $description = 'Delete archived story media past the retention window (DP-005)';

    public function handle(MonitoringSettingsResolver $settings): int
    {
        $disk = Storage::disk((string) config('qds.ingestion.media_disk'));
        $pruned = 0;

        // ADR-0025: retention is per tenant. The scheduler runs tenant-less
        // (TenantScope is a no-op), so ownership is an EXPLICIT predicate.
        foreach (Tenant::query()->pluck('id') as $tenantId) {
            $retentionDays = $settings->storyRetentionDaysFor((int) $tenantId);

            if ($retentionDays <= 0) {
                continue; // this workspace keeps story files forever
            }

            $cutoff = CarbonImmutable::now()->subDays($retentionDays);

            Story::query()
                ->withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->whereNotNull('media_url')
                ->where('captured_at', '<', $cutoff)
                ->chunkById(100, function ($stories) use ($disk, &$pruned) {
                    foreach ($stories as $story) {
                        $disk->delete((string) $story->media_url);

                        $story->update([
                            'media_url' => null,
                            'media_pruned_at' => CarbonImmutable::now(),
                        ]);

                        $pruned++;
                    }
                });
        }

        $this->info("Pruned archived media for {$pruned} stories past their workspace's keep-time.");

        return self::SUCCESS;
    }
}
