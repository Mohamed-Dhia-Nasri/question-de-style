<?php

namespace App\Platform\Enrichment\VisualMatch\Frames;

use App\Platform\Enrichment\Keyframes\KeyframeSet;
use Illuminate\Support\Facades\Storage;

/**
 * Local + free preparation of a stored KeyframeSet before any embedding
 * spend (spec §8 steps 1–3): format check → quality filter → near-dup
 * removal → frame budget. C consumes the KeyframeSet contract only — it
 * never touches the keyframes table or media acquisition (B owns those).
 */
final class FramePreparation
{
    /** Formats the model officially accepts (spec §5): extension → request mimeType. */
    private const SUPPORTED = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'webp' => 'image/webp',
        'heic' => 'image/heic',
        'heif' => 'image/heif',
    ];

    /**
     * Model-supported but GD-undecodable: local quality/dedup analysis
     * would falsely reject them, so they embed as-is (singleton groups).
     */
    private const ANALYSIS_EXEMPT = ['heic', 'heif'];

    public function __construct(
        private readonly FrameQualityFilter $quality,
        private readonly FrameDeduplicator $deduplicator,
    ) {}

    public function prepare(KeyframeSet $set, int $budget): FramePreparationResult
    {
        $framesAvailable = count($set->frames);
        $skippedFormat = 0;
        $skippedQuality = 0;
        $analyzable = [];
        $exempt = [];

        foreach ($set->frames as $keyframe) {
            $extension = strtolower(pathinfo($keyframe->storage_path, PATHINFO_EXTENSION));
            $mimeType = self::SUPPORTED[$extension] ?? null;
            $disk = Storage::disk($keyframe->storage_disk);

            if ($mimeType === null || ! $disk->exists($keyframe->storage_path)) {
                // Unknown format or missing blob: coverage loss, never absence.
                $skippedFormat++;

                continue;
            }

            $bytes = (string) $disk->get($keyframe->storage_path);
            $frame = ['keyframe' => $keyframe, 'bytes' => $bytes, 'mimeType' => $mimeType];

            if (in_array($extension, self::ANALYSIS_EXEMPT, true)) {
                $exempt[] = $frame;

                continue;
            }

            $reason = $this->quality->rejectionReason($bytes);

            if ($reason === FrameQualityFilter::REASON_UNDECODABLE) {
                // Undecodable content is FORMAT loss (§5), not a quality judgment.
                $skippedFormat++;

                continue;
            }

            if ($reason !== null) {
                $skippedQuality++;

                continue;
            }

            $analyzable[] = $frame;
        }

        $prepared = $this->deduplicator->deduplicate($analyzable);
        $deduped = count($analyzable) - count($prepared);

        foreach ($exempt as $frame) {
            $timestamp = $frame['keyframe']->timestamp_ms === null ? null : (int) $frame['keyframe']->timestamp_ms;
            $prepared[] = new PreparedFrame($frame['keyframe'], $frame['bytes'], $frame['mimeType'], 1, $timestamp, $timestamp);
        }

        usort($prepared, fn (PreparedFrame $a, PreparedFrame $b): int => $a->keyframe->ordinal <=> $b->keyframe->ordinal);

        return new FramePreparationResult(
            frames: array_slice($prepared, 0, max(0, $budget)),
            framesAvailable: $framesAvailable,
            skippedFormat: $skippedFormat,
            skippedQuality: $skippedQuality,
            deduped: $deduped,
        );
    }
}
