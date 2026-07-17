<?php

namespace App\Platform\Ingestion\Jobs;

use App\Modules\Monitoring\Models\Story;
use App\Platform\Enrichment\PerPullEnrichmentDispatcher;
use App\Platform\Ingestion\Jobs\Concerns\IngestionJobBehaviour;
use App\Platform\Ingestion\Models\ProviderCall;
use App\Platform\Ingestion\SourceRegistry;
use App\Platform\Ingestion\Support\CallOutcome;
use App\Platform\Ingestion\Support\ErrorCategory;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * Downloads one story's media from the provider's public CDN URL into
 * PRIVATE object storage before the story expires (REQ-M1-004; ADR-0013
 * object storage). The archived storage PATH becomes the story's
 * media_url — access happens only through short-lived signed URLs
 * (StoryMediaController). The job payload carries the URL, never a blob.
 */
class ArchiveStoryMediaJob implements ShouldQueue
{
    use IngestionJobBehaviour;
    use Queueable;

    public int $tries = 3;

    public int $timeout = 180;

    public ?int $cycleId = null;

    public function __construct(
        public readonly int $storyId,
        public readonly string $mediaSourceUrl,
        public readonly string $correlationId,
    ) {
        $this->onQueue('media');
    }

    /** @return list<int> */
    public function backoff(): array
    {
        // Tighter than content backoff: story media has an expiry deadline.
        return [30, 120];
    }

    public function handle(): void
    {
        $this->attachLogContext();

        $story = Story::query()->find($this->storyId);

        if ($story === null || $story->media_url !== null) {
            return; // gone, or already archived (safe replay)
        }

        $startedAt = CarbonImmutable::now();
        $startedMono = microtime(true);

        try {
            $response = Http::timeout(120)->connectTimeout(10)->get($this->mediaSourceUrl);
        } catch (ConnectionException) {
            $this->recordArchival($story, $startedAt, $startedMono, CallOutcome::Failure, ErrorCategory::Network, 0);

            throw new \RuntimeException('Story media download failed (network) — will retry before expiry.');
        }

        if (! $response->successful()) {
            $expired = in_array($response->status(), [403, 404, 410], true);

            $this->recordArchival(
                $story,
                $startedAt,
                $startedMono,
                CallOutcome::Failure,
                $expired ? ErrorCategory::EmptyUnexpected : ErrorCategory::UpstreamError,
                strlen($response->body()),
            );

            if ($expired) {
                // Media already gone at the platform — nothing to retry.
                return;
            }

            throw new \RuntimeException("Story media download failed (HTTP {$response->status()}).");
        }

        $disk = (string) config('qds.ingestion.media_disk');
        $extension = $this->extensionFor($response->header('Content-Type'));

        // ADR-0019: per-tenant prefix, tenant taken from the Story ROW (the
        // job payload may carry no context when dispatched from platform
        // cycles). Existing archives keep their stored paths — playback
        // always reads media_url from the row, never rebuilds it.
        $path = sprintf(
            'tenants/%d/stories/%s/%d/%s.%s',
            $story->tenant_id,
            strtolower($story->platform->value),
            $story->platform_account_id,
            $story->external_id,
            $extension,
        );

        Storage::disk($disk)->put($path, $response->body());

        $story->update(['media_url' => $path]);

        $this->recordArchival($story, $startedAt, $startedMono, CallOutcome::Success, null, strlen($response->body()));

        // ADR-0023: story enrichment follows the successful archive —
        // recognition needs the stored media file.
        app(PerPullEnrichmentDispatcher::class)
            ->dispatchForStory((int) $story->id, $this->correlationId);
    }

    private function recordArchival(
        Story $story,
        CarbonImmutable $startedAt,
        float $startedMono,
        CallOutcome $outcome,
        ?ErrorCategory $category,
        int $bytes,
    ): void {
        $elapsedMs = round((microtime(true) - $startedMono) * 1000, 2);

        ProviderCall::query()->create([
            'source' => SourceRegistry::APIFY_INSTAGRAM_STORY_DETAILS,
            'operation' => 'story.media_archive',
            'correlation_id' => $this->correlationId,
            'job_id' => $this->job?->uuid(),
            'platform_account_id' => $story->platform_account_id,
            'started_at' => $startedAt,
            'finished_at' => CarbonImmutable::now(),
            'duration_ms' => $elapsedMs,
            'outcome' => $outcome,
            'error_category' => $category,
            'error_message' => $category !== null ? "Story media archival failed ({$category->value})." : null,
            'retry_count' => max(0, $this->attempts() - 1),
            'response_bytes' => $bytes,
            'result_count' => 1,
            'accepted_count' => $outcome === CallOutcome::Success ? 1 : 0,
            'timings' => ['media_ms' => $elapsedMs],
        ]);
    }

    private function extensionFor(?string $contentType): string
    {
        return match (true) {
            $contentType === null => 'bin',
            str_contains($contentType, 'video/mp4') => 'mp4',
            str_contains($contentType, 'video/webm') => 'webm',
            str_contains($contentType, 'image/jpeg') => 'jpg',
            str_contains($contentType, 'image/png') => 'png',
            str_contains($contentType, 'image/webp') => 'webp',
            str_contains($contentType, 'image/heic') => 'heic',
            // Any other video subtype (e.g. video/quicktime) keeps a
            // video extension so downstream recognition routes it to the
            // video + SPOKEN_BRAND path instead of misclassifying it as an
            // image and silently skipping speech detection.
            str_contains($contentType, 'video/') => 'mp4',
            default => 'bin',
        };
    }
}
