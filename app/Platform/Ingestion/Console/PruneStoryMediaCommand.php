<?php

namespace App\Platform\Ingestion\Console;

use App\Modules\Monitoring\Models\Story;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Media storage lifecycle (P4 hardening, DP-005 retention limits): archived
 * story media older than the retention window is deleted from the private
 * media disk. The story row itself is kept — only the media file goes —
 * with `media_pruned_at` stamped so archival is never re-attempted.
 */
class PruneStoryMediaCommand extends Command
{
    protected $signature = 'qds:prune-story-media';

    protected $description = 'Delete archived story media past the retention window (DP-005)';

    public function handle(): int
    {
        $retentionDays = (int) config('qds.ingestion.media_retention_days');

        if ($retentionDays <= 0) {
            $this->info('Story media retention is disabled (qds.ingestion.media_retention_days <= 0).');

            return self::SUCCESS;
        }

        $disk = Storage::disk((string) config('qds.ingestion.media_disk'));
        $cutoff = CarbonImmutable::now()->subDays($retentionDays);
        $pruned = 0;

        Story::query()
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

        $this->info("Pruned archived media for {$pruned} stories older than {$retentionDays} days.");

        return self::SUCCESS;
    }
}
