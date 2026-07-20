<?php

namespace App\Platform\Enrichment\Speech;

use App\Modules\Monitoring\Models\ContentItem;
use App\Modules\Monitoring\Models\SpeechAudioChunk;
use App\Modules\Monitoring\Models\Story;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Throwable;

/**
 * Persists extension audio chunks (sub-project D, spec §8.3) to the
 * private media disk so TranscribeExtendedAudioJob can transcribe them
 * AFTER the pipeline's video temp file is gone. Ordinals are 1-based by
 * contract: chunk 0 is the in-pipeline sync pass and is never persisted.
 *
 * Lifecycle: rows are created `pending`; the job flips them and calls
 * deleteChunk() after successful transcription; qds:prune-audio-chunks
 * (orphan window) and the CreatorEraser are the backstops — a chunk blob
 * is transient working data, never an archive (DP-005 retention limits).
 *
 * Concurrency: idempotent on the (owner_type, owner_id, ordinal) partial
 * unique. A lost race overwrites the winner's blob with byte-identical
 * content (AudioChunker extraction is deterministic — the KeyframeWriter
 * doctrine), so the reloaded winner row is simply returned.
 */
final class SpeechAudioChunkWriter
{
    public function persist(
        ContentItem|Story $target,
        int $ordinal,
        int $offsetMs,
        int $durationMs,
        string $bytes,
    ): SpeechAudioChunk {
        if ($ordinal < 1) {
            throw new InvalidArgumentException(
                'Speech audio chunk ordinals are 1-based — chunk 0 is the in-pipeline sync pass and is never persisted.',
            );
        }

        if ($bytes === '') {
            throw new InvalidArgumentException('Refusing to persist an empty speech audio chunk.');
        }

        $existing = $this->find($target, $ordinal);

        if ($existing !== null) {
            return $existing; // idempotent replay — the stored artifact stands
        }

        $diskName = (string) config('qds.ingestion.media_disk');
        $path = $this->pathFor($target, $ordinal);

        Storage::disk($diskName)->put($path, $bytes);

        try {
            return SpeechAudioChunk::query()->create([
                'owner_type' => $target->getMorphClass(),
                'owner_id' => $target->getKey(),
                'ordinal' => $ordinal,
                'offset_ms' => $offsetMs,
                'duration_ms' => $durationMs,
                'storage_disk' => $diskName,
                'storage_path' => $path,
                'byte_size' => strlen($bytes),
                'checksum' => hash('sha256', $bytes),
                'status' => 'pending',
            ]);
        } catch (UniqueConstraintViolationException) {
            // A concurrent pass won the (owner, ordinal) key — its row
            // stands; our byte-identical blob write was harmless.
            $winner = $this->find($target, $ordinal);

            if ($winner === null) {
                throw new \RuntimeException('Speech audio chunk unique-violation winner row not found.');
            }

            return $winner;
        } catch (Throwable $e) {
            try {
                Storage::disk($diskName)->delete($path); // no orphan blobs
            } catch (Throwable) {
                // Best-effort compensation; the orphan prune is the backstop.
            }

            throw $e;
        }
    }

    /** Row + blob — blob FIRST, row only once the blob is confirmed gone (M31). */
    public function deleteChunk(SpeechAudioChunk $chunk): void
    {
        $disk = Storage::disk((string) $chunk->storage_disk);
        $path = (string) $chunk->storage_path;

        try {
            $deleted = $disk->delete($path);
        } catch (Throwable) {
            // Some disks throw instead of returning false.
            $deleted = false;
        }

        // A surviving blob keeps its row so qds:prune-audio-chunks can
        // retry — deleting the row now would orphan the file invisibly.
        if (! $deleted && $disk->exists($path)) {
            return;
        }

        $chunk->delete();
    }

    private function find(ContentItem|Story $target, int $ordinal): ?SpeechAudioChunk
    {
        return SpeechAudioChunk::query()
            ->where('owner_type', $target->getMorphClass())
            ->where('owner_id', $target->getKey())
            ->where('ordinal', $ordinal)
            ->first();
    }

    /** tenants/{tenant}/audio-chunks/{platform}/{owner_id}/{ordinal}.flac (frozen contract). */
    private function pathFor(ContentItem|Story $target, int $ordinal): string
    {
        return sprintf(
            'tenants/%d/audio-chunks/%s/%d/%d.flac',
            $target->tenant_id,
            strtolower($target->platform->value),
            $target->getKey(),
            $ordinal,
        );
    }
}
