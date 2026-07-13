<?php

namespace App\Platform\Ingestion\Providers\Instagram;

use App\Platform\Ingestion\Contracts\ContentProvider;
use App\Platform\Ingestion\DTO\ContentData;
use App\Platform\Ingestion\DTO\NormalizedBatch;
use App\Platform\Ingestion\Http\ApifyClient;
use App\Platform\Ingestion\Normalization\Extract;
use App\Platform\Ingestion\Normalization\NormalizesItems;
use App\Platform\Ingestion\Normalization\RecordRejected;
use App\Platform\Ingestion\SourceRegistry;
use App\Platform\Ingestion\Support\ErrorCategory;
use App\Platform\Ingestion\Support\RefreshWindow;
use App\Shared\Enums\ContentType;
use App\Shared\Enums\Platform;
use App\Shared\ValueObjects\Provenance;
use Carbon\CarbonImmutable;

/**
 * SRC-apify-instagram-post-scraper — image posts / carousels
 * (docs/40-integrations/00-data-source-matrix.md §3, REQ-M1-003).
 *
 * Type mapping: Image → IMAGE_POST, Sidecar → CAROUSEL, Video → REEL
 * (Instagram feed videos are reels; the (platform, external_id) unique key
 * dedupes against the reel scraper). Anything else is quarantined as
 * schema drift — never guessed. STORY never appears here (rule F8).
 */
class InstagramPostAdapter implements ContentProvider
{
    use NormalizesItems;

    public function __construct(private readonly ApifyClient $client) {}

    public function source(): string
    {
        return SourceRegistry::APIFY_INSTAGRAM_POST_SCRAPER;
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
            (string) config('services.apify.actors.instagram_post'),
            $input,
        );

        return $this->normalizeBatch($response, function (array $item) use ($response): ContentData {
            $externalId = Extract::requireString($item, 'Instagram post', 'id', 'shortCode');

            $rawType = Extract::requireString($item, 'Instagram post', 'type');

            $contentType = match ($rawType) {
                'Image' => ContentType::ImagePost,
                'Sidecar' => ContentType::Carousel,
                'Video' => ContentType::Reel,
                default => throw new RecordRejected(
                    ErrorCategory::SchemaDrift,
                    "Unknown Instagram post type [{$rawType}].",
                    $externalId,
                ),
            };

            return new ContentData(
                platform: Platform::Instagram,
                externalId: $externalId,
                contentType: $contentType,
                caption: Extract::string($item, 'caption'),
                mediaUrls: $this->mediaUrls($item),
                publishedAt: Extract::timestamp($item, 'timestamp'),
                publicMetrics: array_values(array_filter([
                    Extract::publicMetric('likes', Extract::int($item, 'likesCount')),
                    Extract::publicMetric('comments', Extract::int($item, 'commentsCount')),
                    Extract::publicMetric('views', Extract::int($item, 'videoViewCount')),
                ])),
                provenance: new Provenance($this->source(), CarbonImmutable::now(), $response->sourceVersion),
                permalink: Extract::string($item, 'url'),
            );
        });
    }

    /**
     * @param  array<array-key, mixed>  $item
     * @return list<string>
     */
    private function mediaUrls(array $item): array
    {
        $urls = [];

        $display = $item['displayUrl'] ?? null;

        if (is_string($display) && $display !== '') {
            $urls[] = $display;
        }

        foreach (is_array($item['images'] ?? null) ? $item['images'] : [] as $image) {
            if (is_string($image) && $image !== '') {
                $urls[] = $image;
            }
        }

        return array_values(array_unique($urls));
    }
}
