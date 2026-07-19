<?php

namespace App\Platform\Enrichment\Media;

use App\Modules\Monitoring\Models\ContentItem;
use App\Modules\Monitoring\Models\Story;
use App\Platform\Enrichment\Recognition\MediaFetcher;
use App\Shared\Enums\ContentType;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Builds the lazy MediaWorkspace for one enrichment target. Routing
 * starts from the row's content_type (image-typed → first 3 image URLs;
 * video-typed → first URL) but the FINAL word belongs to the DOWNLOADED
 * content type: a video-typed row whose payload turns out to be an image
 * (YouTube's thumbnail, a reel's displayUrl fallback) becomes an image
 * asset — never fed to ffmpeg or Video Intelligence as "video".
 */
class MediaWorkspaceFactory
{
    private const IMAGE_LIMIT_PER_TARGET = 3;

    public function __construct(private readonly MediaFetcher $fetcher) {}

    public function forTarget(ContentItem|Story $target): MediaWorkspace
    {
        return new MediaWorkspace(function (MediaWorkspace $workspace) use ($target): void {
            $target instanceof Story
                ? $this->acquireStory($target, $workspace)
                : $this->acquireContent($target, $workspace);
        });
    }

    private function acquireContent(ContentItem $item, MediaWorkspace $workspace): void
    {
        $urls = array_values(array_filter((array) ($item->media_urls ?? []), 'is_string'));

        if ($urls === []) {
            $workspace->addMarker('media:none');

            return;
        }

        if (in_array($item->content_type, [ContentType::ImagePost, ContentType::Carousel], true)) {
            $inlineMax = (int) config('qds.enrichment.recognition.inline_max_bytes');

            foreach (array_slice($urls, 0, self::IMAGE_LIMIT_PER_TARGET) as $url) {
                $asset = $this->stream($workspace, $url, $inlineMax);

                if ($asset !== null) {
                    $workspace->addImage($asset);
                }
            }

            return;
        }

        // Video-typed content: single deep pass over the first media asset —
        // but the downloaded content type has the final word (see class doc).
        $asset = $this->stream($workspace, $urls[0], (int) config('qds.enrichment.keyframes.download_max_bytes'));

        if ($asset === null) {
            return;
        }

        if ($asset->contentType !== null && str_starts_with($asset->contentType, 'image/')) {
            $workspace->addImage($asset);

            return;
        }

        $workspace->setVideo($asset);
    }

    private function stream(MediaWorkspace $workspace, string $url, int $maxBytes): ?LocalMediaAsset
    {
        $sink = $workspace->newTempPath();

        if ($sink === null) {
            $workspace->addMarker('media:fetch-failed');

            return null;
        }

        $result = $this->fetcher->streamToFile($url, $sink, $maxBytes);

        if ($result->status !== StreamStatus::Ok) {
            $workspace->addMarker(match ($result->status) {
                StreamStatus::TooLarge => 'media:too-large',
                StreamStatus::Gone => 'media:too-old',
                default => 'media:fetch-failed',
            });

            return null;
        }

        clearstatcache(true, $sink);
        $size = (int) @filesize($sink);

        if ($size === 0) {
            $workspace->addMarker('media:fetch-failed');

            return null;
        }

        return new LocalMediaAsset($sink, $size, $result->contentType, (string) hash_file('sha256', $sink), $url);
    }

    /** Archived story media lives on the private disk — no HTTP, no SSRF surface. */
    private function acquireStory(Story $story, MediaWorkspace $workspace): void
    {
        $mediaUrl = (string) ($story->media_url ?? '');

        if ($mediaUrl === '') {
            $workspace->addMarker('media:none');

            return;
        }

        $sink = $workspace->newTempPath();

        if ($sink === null) {
            $workspace->addMarker('media:fetch-failed');

            return;
        }

        // Archive extensions are DERIVED from Content-Type at archive time
        // (ArchiveStoryMediaJob::extensionFor), so extension routing is the
        // stored MIME metadata — trustworthy by construction (seam audit #10).
        $isVideo = $this->looksLikeVideo($mediaUrl);
        $maxBytes = $isVideo
            ? (int) config('qds.enrichment.keyframes.download_max_bytes')
            : (int) config('qds.enrichment.recognition.inline_max_bytes');

        try {
            $stream = Storage::disk((string) config('qds.ingestion.media_disk'))->readStream($mediaUrl);
            $out = fopen($sink, 'wb');

            if (! is_resource($stream) || ! is_resource($out)) {
                $workspace->addMarker('media:fetch-failed');

                return;
            }

            // Copy one byte past the cap so an over-cap file is detectable.
            stream_copy_to_stream($stream, $out, $maxBytes + 1);
            fclose($stream);
            fclose($out);
        } catch (Throwable) {
            $workspace->addMarker('media:fetch-failed');

            return;
        }

        clearstatcache(true, $sink);
        $size = (int) @filesize($sink);

        if ($size === 0) {
            $workspace->addMarker('media:fetch-failed');

            return;
        }

        if ($size > $maxBytes) {
            $workspace->addMarker('media:too-large');

            return;
        }

        $asset = new LocalMediaAsset($sink, $size, null, (string) hash_file('sha256', $sink), null);
        $isVideo ? $workspace->setVideo($asset) : $workspace->addImage($asset);
    }

    private function looksLikeVideo(string $path): bool
    {
        return in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), ['mp4', 'mov', 'webm', 'm4v'], true);
    }
}
