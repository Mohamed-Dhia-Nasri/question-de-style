<?php

namespace App\Platform\Ingestion\Providers\YouTube;

use App\Platform\Ingestion\Contracts\ContentProvider;
use App\Platform\Ingestion\DTO\ContentData;
use App\Platform\Ingestion\DTO\NormalizedBatch;
use App\Platform\Ingestion\DTO\ProviderResponse;
use App\Platform\Ingestion\Exceptions\ProviderCallException;
use App\Platform\Ingestion\Http\YouTubeClient;
use App\Platform\Ingestion\Normalization\Extract;
use App\Platform\Ingestion\Normalization\NormalizesItems;
use App\Platform\Ingestion\SourceRegistry;
use App\Platform\Ingestion\Support\ErrorCategory;
use App\Shared\Enums\ContentType;
use App\Shared\Enums\Platform;
use App\Shared\ValueObjects\Provenance;
use Carbon\CarbonImmutable;
use DateInterval;
use Throwable;

/**
 * SRC-youtube-data-api-v3 — videos/shorts + public view/like/comment counts
 * (docs/40-integrations/00-data-source-matrix.md §3, REQ-M1-003).
 *
 * Three chained calls: channel → uploads playlist → recent video details.
 * ContentType per the raw→domain mapping is VIDEO / SHORT; SHORT is
 * classified by the configurable short-form duration threshold.
 */
class YouTubeContentAdapter implements ContentProvider
{
    use NormalizesItems;

    public function __construct(private readonly YouTubeClient $client) {}

    public function source(): string
    {
        return SourceRegistry::YOUTUBE_DATA_API_V3;
    }

    public function platform(): Platform
    {
        return Platform::YouTube;
    }

    // $fullDepth is accepted for contract parity but not applied: the
    // uploads-playlist walk is already capped at content_results_limit and
    // YouTube Data API quota is free — there is no per-result billing to
    // window (cost plan rec 1 targets the Apify actors only).
    public function fetchContent(string $handle, bool $fullDepth = false): NormalizedBatch
    {
        $totalMs = 0.0;
        $totalBytes = 0;

        // 1. Resolve the channel's uploads playlist.
        $channel = $this->client->get('channels', [
            'part' => 'contentDetails',
            ...(str_starts_with($handle, 'UC') && strlen($handle) === 24
                ? ['id' => $handle]
                : ['forHandle' => $handle]),
            'maxResults' => 1,
        ]);
        $totalMs += $channel->requestMs;
        $totalBytes += $channel->responseBytes;

        $uploadsPlaylist = $channel->data['items'][0]['contentDetails']['relatedPlaylists']['uploads'] ?? null;

        if (! is_string($uploadsPlaylist) || $uploadsPlaylist === '') {
            // Channel not found or schema changed — nothing to normalize.
            return $this->emptyBatch($channel->httpStatus, $totalBytes, $totalMs);
        }

        // 2. Recent upload ids.
        $playlist = $this->client->get('playlistItems', [
            'part' => 'contentDetails',
            'playlistId' => $uploadsPlaylist,
            'maxResults' => min(50, (int) config('qds.ingestion.content_results_limit')),
        ]);
        $totalMs += $playlist->requestMs;
        $totalBytes += $playlist->responseBytes;

        $videoIds = [];

        foreach (is_array($playlist->data['items'] ?? null) ? $playlist->data['items'] : [] as $item) {
            $videoId = $item['contentDetails']['videoId'] ?? null;

            if (is_string($videoId) && $videoId !== '') {
                $videoIds[] = $videoId;
            }
        }

        if ($videoIds === []) {
            return $this->emptyBatch($playlist->httpStatus, $totalBytes, $totalMs);
        }

        // 3. Video details + public statistics.
        $videos = $this->client->get('videos', [
            'part' => 'snippet,statistics,contentDetails',
            'id' => implode(',', $videoIds),
            'maxResults' => 50,
        ]);
        $totalMs += $videos->requestMs;
        $totalBytes += $videos->responseBytes;

        $items = $videos->data['items'] ?? null;

        if (! is_array($items)) {
            throw new ProviderCallException(
                $this->source(),
                ErrorCategory::SchemaDrift,
                'YouTube videos response has no items list where one was expected.',
                $videos->httpStatus,
            );
        }

        $response = new ProviderResponse(
            items: array_values($items),
            httpStatus: $videos->httpStatus,
            responseBytes: $totalBytes,
            requestMs: $totalMs,
            sourceVersion: YouTubeProfileAdapter::SOURCE_VERSION,
        );

        return $this->normalizeBatch($response, function (array $item) use ($response): ContentData {
            $externalId = Extract::requireString($item, 'YouTube video', 'id');

            $snippet = is_array($item['snippet'] ?? null) ? $item['snippet'] : [];
            $statistics = is_array($item['statistics'] ?? null) ? $item['statistics'] : [];
            $contentDetails = is_array($item['contentDetails'] ?? null) ? $item['contentDetails'] : [];

            $seconds = $this->durationSeconds(Extract::string($contentDetails, 'duration'));
            $threshold = (int) config('qds.ingestion.short_video_max_seconds');

            return new ContentData(
                platform: Platform::YouTube,
                externalId: $externalId,
                contentType: ($seconds !== null && $seconds <= $threshold)
                    ? ContentType::Short
                    : ContentType::Video,
                caption: Extract::string($snippet, 'title'),
                mediaUrls: ["https://www.youtube.com/watch?v={$externalId}"],
                publishedAt: Extract::timestamp($snippet, 'publishedAt'),
                publicMetrics: array_values(array_filter([
                    Extract::publicMetric('views', Extract::int($statistics, 'viewCount')),
                    Extract::publicMetric('likes', Extract::int($statistics, 'likeCount')),
                    Extract::publicMetric('comments', Extract::int($statistics, 'commentCount')),
                ])),
                provenance: new Provenance($this->source(), CarbonImmutable::now(), $response->sourceVersion),
                mentions: \App\Platform\Ingestion\Normalization\SignalExtract::mentions($item),
                productTags: \App\Platform\Ingestion\Normalization\SignalExtract::productTags($item),
                collaborators: \App\Platform\Ingestion\Normalization\SignalExtract::collaborators($item),
                brandedContentLabel: \App\Platform\Ingestion\Normalization\SignalExtract::brandedContentLabel($item),
            );
        });
    }

    /** Parse an ISO-8601 duration (PT#M#S) into whole seconds. */
    private function durationSeconds(?string $iso): ?int
    {
        if ($iso === null) {
            return null;
        }

        try {
            $interval = new DateInterval($iso);
        } catch (Throwable) {
            return null;
        }

        return (int) round(
            ((($interval->d * 24) + $interval->h) * 60 + $interval->i) * 60 + $interval->s
        );
    }

    private function emptyBatch(int $httpStatus, int $bytes, float $requestMs): NormalizedBatch
    {
        $response = new ProviderResponse(
            items: [],
            httpStatus: $httpStatus,
            responseBytes: $bytes,
            requestMs: $requestMs,
            sourceVersion: YouTubeProfileAdapter::SOURCE_VERSION,
        );

        return $this->normalizeBatch($response, fn (array $item) => null);
    }
}
