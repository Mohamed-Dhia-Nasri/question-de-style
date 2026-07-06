<?php

namespace App\Platform\Ingestion\Providers\TikTok;

use App\Platform\Ingestion\Contracts\ContentProvider;
use App\Platform\Ingestion\DTO\ContentData;
use App\Platform\Ingestion\DTO\NormalizedBatch;
use App\Platform\Ingestion\Http\ApifyClient;
use App\Platform\Ingestion\Normalization\Extract;
use App\Platform\Ingestion\Normalization\NormalizesItems;
use App\Platform\Ingestion\SourceRegistry;
use App\Shared\Enums\ContentType;
use App\Shared\Enums\Platform;
use App\Shared\ValueObjects\Provenance;
use Carbon\CarbonImmutable;

/**
 * SRC-clockworks-tiktok-scraper — the ONLY TikTok provider (ADR-0002);
 * video branch: views, likes, comments, shares, saves
 * (docs/40-integrations/00-data-source-matrix.md §3, REQ-M1-003).
 *
 * ContentType per the raw→domain mapping is SHORT / VIDEO; classification
 * uses the configurable short-form duration threshold (defaults to SHORT
 * when the duration is absent, since TikTok is a short-form platform).
 */
class TikTokContentAdapter implements ContentProvider
{
    use NormalizesItems;

    public function __construct(private readonly ApifyClient $client) {}

    public function source(): string
    {
        return SourceRegistry::CLOCKWORKS_TIKTOK_SCRAPER;
    }

    public function platform(): Platform
    {
        return Platform::TikTok;
    }

    public function fetchContent(string $handle): NormalizedBatch
    {
        $response = $this->client->runActor(
            $this->source(),
            (string) config('services.apify.actors.tiktok'),
            [
                'profiles' => [$handle],
                'resultsPerPage' => (int) config('qds.ingestion.content_results_limit'),
            ],
        );

        return $this->normalizeBatch($response, function (array $item) use ($response): ContentData {
            $externalId = Extract::requireString($item, 'TikTok video', 'id');

            $videoMeta = is_array($item['videoMeta'] ?? null) ? $item['videoMeta'] : [];
            $duration = Extract::int($videoMeta, 'duration');
            $threshold = (int) config('qds.ingestion.short_video_max_seconds');

            return new ContentData(
                platform: Platform::TikTok,
                externalId: $externalId,
                contentType: ($duration === null || $duration <= $threshold)
                    ? ContentType::Short
                    : ContentType::Video,
                caption: Extract::string($item, 'text'),
                mediaUrls: array_filter([
                    Extract::string($item, 'webVideoUrl'),
                ]),
                publishedAt: Extract::timestamp($item, 'createTimeISO', 'createTime'),
                publicMetrics: array_values(array_filter([
                    Extract::publicMetric('views', Extract::int($item, 'playCount')),
                    Extract::publicMetric('likes', Extract::int($item, 'diggCount')),
                    Extract::publicMetric('comments', Extract::int($item, 'commentCount')),
                    Extract::publicMetric('shares', Extract::int($item, 'shareCount')),
                    Extract::publicMetric('saves', Extract::int($item, 'collectCount')),
                ])),
                provenance: new Provenance($this->source(), CarbonImmutable::now(), $response->sourceVersion),
            );
        });
    }
}
