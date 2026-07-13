<?php

namespace App\Platform\Ingestion\Providers\Instagram;

use App\Platform\Ingestion\Contracts\BatchStoryProvider;
use App\Platform\Ingestion\DTO\NormalizedBatch;
use App\Platform\Ingestion\DTO\StoryData;
use App\Platform\Ingestion\Http\ApifyClient;
use App\Platform\Ingestion\Normalization\Extract;
use App\Platform\Ingestion\Normalization\NormalizesItems;
use App\Platform\Ingestion\SourceRegistry;
use App\Shared\Enums\Platform;
use App\Shared\ValueObjects\Provenance;
use Carbon\CarbonImmutable;

/**
 * SRC-apify-instagram-story-details — the louisdeconinck actor; returns
 * live stories with no login required
 * (docs/40-integrations/00-data-source-matrix.md §3). Feeds ENT-Story
 * archival before platform expiry (REQ-M1-004, AC-M1-005). Stories are
 * never ContentItems (rule F8).
 *
 * expires_at is stored only when the provider reports it — never fabricated
 * from an assumed 24h window.
 */
class InstagramStoryAdapter implements BatchStoryProvider
{
    use NormalizesItems;

    public function __construct(private readonly ApifyClient $client) {}

    public function source(): string
    {
        return SourceRegistry::APIFY_INSTAGRAM_STORY_DETAILS;
    }

    public function platform(): Platform
    {
        return Platform::Instagram;
    }

    public function fetchStories(string $handle): NormalizedBatch
    {
        return $this->fetchStoriesForHandles([$handle]);
    }

    /**
     * One actor run for MANY handles (cost plan rec 3): the story actor
     * bills a per-RUN start fee that dwarfs its per-username fee, so the
     * story cycle sends the roster in batches instead of one run per
     * account. Multi-handle runs use the ASYNC endpoint — a roster-sized
     * run can outlive Apify's 300s synchronous wall.
     *
     * @param  list<string>  $handles
     */
    public function fetchStoriesForHandles(array $handles): NormalizedBatch
    {
        $actorId = (string) config('services.apify.actors.instagram_story');
        $input = ['usernames' => $handles];

        $response = count($handles) > 1
            ? $this->client->runActorAsync($this->source(), $actorId, $input)
            : $this->client->runActor($this->source(), $actorId, $input);

        return $this->normalizeBatch($response, function (array $item) use ($response): StoryData {
            $externalId = Extract::requireString($item, 'Instagram story', 'id', 'pk');

            return new StoryData(
                platform: Platform::Instagram,
                externalId: $externalId,
                mediaSourceUrl: Extract::string($item, 'videoUrl', 'imageUrl', 'mediaUrl', 'displayUrl'),
                expiresAt: Extract::timestamp($item, 'expiresAt', 'expiringAt'),
                publicMetrics: array_filter([
                    Extract::publicMetric('views', Extract::int($item, 'viewCount', 'viewersCount')),
                ]),
                provenance: new Provenance($this->source(), CarbonImmutable::now(), $response->sourceVersion),
                ownerHandle: Extract::string($item, 'username', 'ownerUsername', 'user'),
            );
        });
    }
}
