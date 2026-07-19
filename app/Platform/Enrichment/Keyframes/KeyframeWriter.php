<?php

namespace App\Platform\Enrichment\Keyframes;

use App\Modules\Monitoring\Models\ContentItem;
use App\Modules\Monitoring\Models\Keyframe;
use App\Modules\Monitoring\Models\Story;
use App\Shared\Enums\KeyframeKind;
use App\Shared\ValueObjects\Provenance;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Persists extracted frames to the private media disk (the story-media
 * path convention) and inserts their rows in ONE transaction — a set is
 * all-or-nothing, so "frames exist" always means "the COMPLETE set
 * exists" (extract-once depends on this; tier C embeddings FK these ids).
 *
 * Failure semantics:
 *  - unique-key loss to a concurrent pass → rollback, return 0, KEEP the
 *    files (deterministic sampling makes them byte-identical to the
 *    winner's, so deleting would destroy the winner's frames);
 *  - any other failure → rollback, DELETE the files we wrote (no orphan
 *    blobs), rethrow (the run fails loudly; a retry re-extracts cleanly).
 */
class KeyframeWriter
{
    /**
     * @param  list<array{tempPath: string, timestampMs: int|null, kind: KeyframeKind, extension: string, sourceChecksum: string}>  $frames
     * @return int rows written (0 when a concurrent pass won)
     */
    public function persist(ContentItem|Story $owner, array $frames): int
    {
        $diskName = (string) config('qds.ingestion.media_disk');
        $disk = Storage::disk($diskName);
        $writtenPaths = [];
        $rows = [];

        try {
            foreach ($frames as $ordinal => $frame) {
                $path = $this->pathFor($owner, $ordinal, $frame['extension']);
                $stream = @fopen($frame['tempPath'], 'rb');

                if ($stream === false) {
                    // An unreadable frame fails the WHOLE batch (complete-set
                    // doctrine): the generic catch below compensates the files
                    // already written and rethrows — a retry re-extracts cleanly.
                    throw new \RuntimeException("Keyframe temp file unreadable: ordinal {$ordinal}");
                }

                try {
                    $disk->put($path, $stream);
                } finally {
                    if (is_resource($stream)) {
                        fclose($stream);
                    }
                }

                $writtenPaths[] = $path;
                [$width, $height] = $this->dimensions($frame['tempPath']);

                $rows[] = [
                    'owner_type' => $owner->getMorphClass(),
                    'owner_id' => $owner->id,
                    'ordinal' => $ordinal,
                    'timestamp_ms' => $frame['timestampMs'],
                    'storage_disk' => $diskName,
                    'storage_path' => $path,
                    'width' => $width,
                    'height' => $height,
                    'kind' => $frame['kind'],
                    'checksum' => (string) hash_file('sha256', $frame['tempPath']),
                    'source_checksum' => $frame['sourceChecksum'],
                    'provenance' => new Provenance($owner->provenance->source, CarbonImmutable::now(), 'keyframes-v1'),
                ];
            }

            DB::transaction(function () use ($rows): void {
                foreach ($rows as $row) {
                    Keyframe::query()->create($row);
                }
            });
        } catch (UniqueConstraintViolationException) {
            // A concurrent pass won the (owner, ordinal) key — its rows
            // stand, our identical files are harmless (see class doc).
            return 0;
        } catch (Throwable $e) {
            foreach ($writtenPaths as $path) {
                try {
                    $disk->delete($path);
                } catch (Throwable) {
                    // Best-effort compensation; the retention sweep is the backstop.
                }
            }

            throw $e;
        }

        return count($rows);
    }

    /** tenants/{tenant}/keyframes/{platform}/{account}/{content|story}-{external}/{ordinal}.{ext} */
    private function pathFor(ContentItem|Story $owner, int $ordinal, string $extension): string
    {
        $ownerSegment = ($owner instanceof ContentItem ? 'content-' : 'story-').$owner->external_id;

        return sprintf(
            'tenants/%d/keyframes/%s/%d/%s/%d.%s',
            $owner->tenant_id,
            strtolower($owner->platform->value),
            $owner->platform_account_id,
            $ownerSegment,
            $ordinal,
            $extension,
        );
    }

    /** @return array{0: int|null, 1: int|null} best-effort (null when undecodable, e.g. HEIC) */
    private function dimensions(string $path): array
    {
        $info = @getimagesize($path);

        return is_array($info) ? [(int) $info[0], (int) $info[1]] : [null, null];
    }
}
