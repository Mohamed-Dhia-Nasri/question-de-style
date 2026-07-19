<?php

namespace App\Platform\Enrichment\Keyframes;

use App\Modules\Monitoring\Models\ContentItem;
use App\Modules\Monitoring\Models\Keyframe;
use App\Modules\Monitoring\Models\Story;
use App\Platform\Enrichment\Media\LocalMediaAsset;
use App\Platform\Enrichment\Media\MediaWorkspace;
use App\Shared\Enums\KeyframeKind;
use App\Shared\Enums\Platform;

/**
 * The keyframes pipeline stage (sub-project B): turns the workspace's
 * media into persisted frames. Extract-once: an owner that already has
 * frames is never re-sampled — and because the writer is transactional,
 * "has frames" is equivalent to "has the complete set" (a forced
 * re-extract is a future operator command, not a pipeline behaviour).
 * The returned string is the EnrichmentRun stage summary.
 */
class KeyframeExtractor
{
    public function __construct(
        private readonly KeyframeSampler $sampler,
        private readonly KeyframeWriter $writer,
    ) {}

    public function enrich(ContentItem|Story $target, MediaWorkspace $workspace): string
    {
        $hasFrames = Keyframe::query()
            ->where('owner_type', $target->getMorphClass())
            ->where('owner_id', $target->id)
            ->exists();

        if ($hasFrames) {
            return 'skipped:already-extracted';
        }

        $video = $workspace->video();

        if ($video !== null) {
            if (! $this->sampler->isAvailable()) {
                return 'skipped:ffmpeg-unavailable';
            }

            $frames = $this->sampler->sample($video->tempPath);

            if ($frames === null || $frames === []) {
                return 'skipped:extraction-failed';
            }

            try {
                $written = $this->writer->persist($target, array_map(
                    static fn (SampledFrame $frame): array => [
                        'tempPath' => $frame->tempPath,
                        'timestampMs' => $frame->timestampMs,
                        'kind' => KeyframeKind::VideoSample,
                        'extension' => 'jpg',
                        'sourceChecksum' => $video->sha256,
                    ],
                    $frames,
                ));
            } finally {
                foreach ($frames as $frame) {
                    @unlink($frame->tempPath);
                }
            }

            return "completed:{$written} frame(s)";
        }

        $images = $workspace->images();

        if ($images !== []) {
            // A YouTube ContentItem's single image is the platform poster;
            // any other image (post/carousel/story) IS the frame.
            $kind = $target instanceof ContentItem && $target->platform === Platform::YouTube
                ? KeyframeKind::Thumbnail
                : KeyframeKind::SourceImage;

            $written = $this->writer->persist($target, array_map(
                fn (LocalMediaAsset $asset): array => [
                    'tempPath' => $asset->tempPath,
                    'timestampMs' => null,
                    'kind' => $kind,
                    'extension' => $this->extensionFor($asset->contentType),
                    'sourceChecksum' => $asset->sha256,
                ],
                $images,
            ));

            return "completed:{$written} frame(s)";
        }

        return 'skipped:'.($workspace->markers()[0] ?? 'no-media');
    }

    /** Mirrors ArchiveStoryMediaJob::extensionFor's image branch. */
    private function extensionFor(?string $contentType): string
    {
        return match (true) {
            $contentType === null => 'jpg',
            str_contains($contentType, 'image/png') => 'png',
            str_contains($contentType, 'image/webp') => 'webp',
            str_contains($contentType, 'image/heic') => 'heic',
            default => 'jpg',
        };
    }
}
