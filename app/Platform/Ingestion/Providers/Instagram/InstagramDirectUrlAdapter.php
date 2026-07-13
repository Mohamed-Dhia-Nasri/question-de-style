<?php

namespace App\Platform\Ingestion\Providers\Instagram;

use App\Platform\Ingestion\DTO\ContentData;
use App\Platform\Ingestion\DTO\NormalizedBatch;
use App\Platform\Ingestion\Http\ApifyClient;
use App\Platform\Ingestion\Normalization\Extract;
use App\Platform\Ingestion\Normalization\NormalizesItems;
use App\Platform\Ingestion\Normalization\RecordRejected;
use App\Platform\Ingestion\SourceRegistry;
use App\Platform\Ingestion\Support\ErrorCategory;
use App\Shared\Enums\ContentType;
use App\Shared\Enums\Platform;
use App\Shared\ValueObjects\Provenance;
use Carbon\CarbonImmutable;

/**
 * SRC-apify-instagram-scraper — the general Instagram actor, used ONLY for
 * direct post-URL metric refresh (qds.ingestion.campaign_refresh): one run
 * re-fetches current metrics for specific known posts/reels via their
 * public page URLs, so campaign-linked content older than the roster's
 * refresh window keeps its EMV/CPE metrics live without widening the whole
 * roster's window.
 *
 * NOT a roster polling source: verified price-identical-or-worse than the
 * specialized actors for feed scraping, and one run returns a single
 * content type — its unique capability is the directUrls input.
 */
class InstagramDirectUrlAdapter
{
    use NormalizesItems;

    public function __construct(private readonly ApifyClient $client) {}

    public function source(): string
    {
        return SourceRegistry::APIFY_INSTAGRAM_SCRAPER;
    }

    public function platform(): Platform
    {
        return Platform::Instagram;
    }

    /**
     * Fetch the CURRENT state of specific posts/reels by page URL
     * (/p/… and /reel/… URLs). Multi-URL batches use the async endpoint —
     * they can outlive Apify's 300s synchronous wall.
     *
     * @param  list<string>  $urls
     * @return NormalizedBatch whose items are ContentData
     */
    public function fetchByUrls(array $urls): NormalizedBatch
    {
        $actorId = (string) config('services.apify.actors.instagram_direct');

        $input = [
            'directUrls' => $urls,
            'resultsType' => 'posts',
        ];

        $response = count($urls) > 1
            ? $this->client->runActorAsync($this->source(), $actorId, $input)
            : $this->client->runActor($this->source(), $actorId, $input);

        return $this->normalizeBatch($response, function (array $item) use ($response): ContentData {
            if (isset($item['error'])) {
                throw new RecordRejected(
                    ErrorCategory::MissingRequiredFields,
                    'Direct-URL refresh returned an error item (post removed or private).',
                    Extract::hint($item),
                );
            }

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
                mediaUrls: array_values(array_filter([
                    Extract::string($item, 'displayUrl'),
                    Extract::string($item, 'videoUrl'),
                ])),
                publishedAt: Extract::timestamp($item, 'timestamp'),
                publicMetrics: array_values(array_filter([
                    Extract::publicMetric('likes', Extract::int($item, 'likesCount')),
                    Extract::publicMetric('comments', Extract::int($item, 'commentsCount')),
                    Extract::publicMetric('views', Extract::int($item, 'videoViewCount')),
                    Extract::publicMetric('plays', Extract::int($item, 'videoPlayCount')),
                ])),
                provenance: new Provenance($this->source(), CarbonImmutable::now(), $response->sourceVersion),
                permalink: Extract::string($item, 'url'),
            );
        });
    }
}
