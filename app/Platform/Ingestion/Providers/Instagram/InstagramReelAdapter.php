<?php

namespace App\Platform\Ingestion\Providers\Instagram;

use App\Platform\Ingestion\Contracts\ContentProvider;
use App\Platform\Ingestion\DTO\ContentData;
use App\Platform\Ingestion\DTO\NormalizedBatch;
use App\Platform\Ingestion\Http\ApifyClient;
use App\Platform\Ingestion\Normalization\Extract;
use App\Platform\Ingestion\Normalization\NormalizesItems;
use App\Platform\Ingestion\SourceRegistry;
use App\Platform\Ingestion\Support\RefreshWindow;
use App\Shared\Enums\ContentType;
use App\Shared\Enums\Platform;
use App\Shared\ValueObjects\Provenance;
use Carbon\CarbonImmutable;

/**
 * SRC-apify-instagram-reel-scraper — reels including play/view counts
 * (docs/40-integrations/00-data-source-matrix.md §3, REQ-M1-003/005).
 * The optional in-reel transcript add-on feeds SVC-EnrichmentAI (P1
 * enrichment scope), not this collection adapter.
 */
class InstagramReelAdapter implements ContentProvider
{
    use NormalizesItems;

    public function __construct(private readonly ApifyClient $client) {}

    public function source(): string
    {
        return SourceRegistry::APIFY_INSTAGRAM_REEL_SCRAPER;
    }

    public function platform(): Platform
    {
        return Platform::Instagram;
    }

    public function fetchContent(string $handle, bool $fullDepth = false): NormalizedBatch
    {
        $input = [
            'username' => [$handle],
            'resultsLimit' => (int) config('qds.ingestion.content_results_limit'),
            // Pinned posts leak items older than the date window (documented
            // actor behavior) — they are re-seen posts anyway, skip them.
            'skipPinnedPosts' => true,
        ];

        if (($window = RefreshWindow::relative($fullDepth)) !== null) {
            $input['onlyPostsNewerThan'] = $window;
        }

        $response = $this->client->runActor(
            $this->source(),
            (string) config('services.apify.actors.instagram_reel'),
            $input,
        );

        return $this->normalizeBatch($response, function (array $item) use ($response): ContentData {
            $externalId = Extract::requireString($item, 'Instagram reel', 'id', 'shortCode');

            $mediaUrls = array_values(array_unique(array_filter([
                Extract::string($item, 'videoUrl'),
                Extract::string($item, 'displayUrl'),
            ])));

            return new ContentData(
                platform: Platform::Instagram,
                externalId: $externalId,
                contentType: ContentType::Reel,
                caption: Extract::string($item, 'caption'),
                mediaUrls: $mediaUrls,
                publishedAt: Extract::timestamp($item, 'timestamp'),
                publicMetrics: array_values(array_filter([
                    Extract::publicMetric('plays', Extract::int($item, 'videoPlayCount')),
                    Extract::publicMetric('views', Extract::int($item, 'videoViewCount')),
                    Extract::publicMetric('likes', Extract::int($item, 'likesCount')),
                    Extract::publicMetric('comments', Extract::int($item, 'commentsCount')),
                    Extract::publicMetric('shares', Extract::int($item, 'sharesCount')),
                ])),
                provenance: new Provenance($this->source(), CarbonImmutable::now(), $response->sourceVersion),
                permalink: Extract::string($item, 'url'),
            );
        });
    }
}
