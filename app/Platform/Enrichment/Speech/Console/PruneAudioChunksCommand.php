<?php

namespace App\Platform\Enrichment\Speech\Console;

use App\Modules\Monitoring\Models\SpeechAudioChunk;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Chunk-artifact lifecycle backstop (sub-project D, DP-005 retention
 * limits): TranscribeExtendedAudioJob deletes each chunk's row + blob on
 * successful transcription, so anything older than chunk_orphan_days was
 * left behind by a failure — prune it whatever its status, blob first,
 * row only once the blob is confirmed gone (the story-media M31 pattern;
 * like keyframes, a chunk row without its file is meaningless).
 *
 * The window is GLOBAL operational config (transient working data, not
 * an archive — ADR-0025 per-tenant retention does not apply). The
 * scheduler runs tenant-less (TenantScope is a no-op), and the scope
 * bypass below keeps that explicit.
 */
class PruneAudioChunksCommand extends Command
{
    protected $signature = 'qds:prune-audio-chunks';

    protected $description = 'Delete speech audio chunk rows and blobs older than the orphan window (DP-005)';

    public function handle(): int
    {
        $days = max(1, (int) config('qds.enrichment.speech.chunk_orphan_days'));
        $cutoff = CarbonImmutable::now()->subDays($days);
        $pruned = 0;

        SpeechAudioChunk::query()
            ->withoutGlobalScopes()
            ->where('created_at', '<', $cutoff)
            ->chunkById(200, function ($chunks) use (&$pruned): void {
                foreach ($chunks as $chunk) {
                    $disk = Storage::disk((string) $chunk->storage_disk);
                    $path = (string) $chunk->storage_path;

                    try {
                        $deleted = $disk->delete($path);
                    } catch (\Throwable) {
                        // Some disks throw instead of returning false.
                        $deleted = false;
                    }

                    // Row goes only once the blob is confirmed gone (M31) —
                    // a failed delete is left for the next daily run.
                    if (! $deleted && $disk->exists($path)) {
                        continue;
                    }

                    $chunk->delete();
                    $pruned++;
                }
            });

        $this->info("Pruned {$pruned} orphaned speech audio chunks past the {$days}-day window.");

        return self::SUCCESS;
    }
}
