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
 * SRC-apify-instagram-story-details — the louisdeconinck
 * `instagram-story-details-scraper` actor; returns live stories with no
 * login required (docs/40-integrations/00-data-source-matrix.md §3). Feeds
 * ENT-Story archival before platform expiry (REQ-M1-004, AC-M1-005).
 * Stories are never ContentItems (rule F8).
 *
 * The actor returns Instagram's RAW private-API media object (snake_case),
 * so normalization reads the NESTED IG shape — not a flattened one:
 *   - media  : video_versions[].url (video) else
 *              image_versions2.candidates[].url (photo)
 *   - expiry : expiring_at (unix seconds) — stored ONLY when the provider
 *              reports it, never fabricated from an assumed 24h window
 *   - owner  : user.username (nested; there is no top-level username)
 *   - id     : id (the "{mediaPk}_{userPk}" string) or pk
 * The actor exposes no story view/viewer count, so publicMetrics is empty
 * (a zero is never fabricated).
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
                mediaSourceUrl: $this->mediaUrl($item),
                expiresAt: Extract::timestamp($item, 'expiring_at'),
                publicMetrics: [], // the actor exposes no view/viewer count
                provenance: new Provenance($this->source(), CarbonImmutable::now(), $response->sourceVersion),
                ownerHandle: $this->ownerHandle($item),
            );
        });
    }

    /**
     * The single media URL to archive once into private storage. A video
     * story exposes video_versions[].url; a photo story
     * image_versions2.candidates[].url (best candidate first). Prefer the
     * video so a video story archives the clip, not its poster frame.
     *
     * @param  array<array-key, mixed>  $item
     */
    private function mediaUrl(array $item): ?string
    {
        $video = $item['video_versions'][0]['url'] ?? null;

        if (is_string($video) && trim($video) !== '') {
            return $video;
        }

        $image = $item['image_versions2']['candidates'][0]['url'] ?? null;

        return is_string($image) && trim($image) !== '' ? $image : null;
    }

    /**
     * The posting account's handle, nested at user.username in the raw IG
     * object (there is no top-level username). Required to attribute items
     * when one BATCHED run covers many roster handles (cost plan rec 3).
     *
     * @param  array<array-key, mixed>  $item
     */
    private function ownerHandle(array $item): ?string
    {
        $username = $item['user']['username'] ?? null;

        return is_string($username) && trim($username) !== '' ? $username : null;
    }
}
