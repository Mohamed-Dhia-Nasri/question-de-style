<?php

namespace App\Platform\Ingestion\Providers\TikTok;

use App\Platform\Ingestion\Contracts\ProvidesProfileFromContent;
use App\Platform\Ingestion\DTO\ContentData;
use App\Platform\Ingestion\DTO\NormalizedBatch;
use App\Platform\Ingestion\DTO\ProfileData;
use App\Platform\Ingestion\DTO\ProviderResponse;
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
 * SRC-clockworks-tiktok-scraper — the ONLY TikTok provider (ADR-0002);
 * video branch: views, likes, comments, shares, saves
 * (docs/40-integrations/00-data-source-matrix.md §3, REQ-M1-003).
 *
 * ContentType per the raw→domain mapping is SHORT / VIDEO; classification
 * uses the configurable short-form duration threshold (defaults to SHORT
 * when the duration is absent, since TikTok is a short-form platform).
 *
 * Also ProvidesProfileFromContent (cost plan rec 4): every video item of a
 * profile scrape embeds full authorMeta (fans, bio, links), so the account
 * profile is captured from THIS run and no separate profile call is billed.
 * On a quiet day under the date filter the actor pushes one item with only
 * authorMeta and an empty-section note — that item refreshes the profile
 * and is skipped as content without quarantine noise.
 */
class TikTokContentAdapter implements ProvidesProfileFromContent
{
    use NormalizesItems;

    private ?ProfileData $lastProfile = null;

    public function __construct(private readonly ApifyClient $client) {}

    public function source(): string
    {
        return SourceRegistry::CLOCKWORKS_TIKTOK_SCRAPER;
    }

    public function platform(): Platform
    {
        return Platform::TikTok;
    }

    public function fetchContent(string $handle, bool $fullDepth = false): NormalizedBatch
    {
        $this->lastProfile = null;

        $input = [
            'profiles' => [$handle],
            'resultsPerPage' => (int) config('qds.ingestion.content_results_limit'),
        ];

        // Charged add-on ('filter-applied' event) but an order of magnitude
        // cheaper than re-buying the newest N videos every cycle (cost plan
        // rec 1). Requires the default 'latest' profileSorting — do not add
        // popularity sorting here, the actor rejects the combination.
        if (($window = RefreshWindow::relative($fullDepth)) !== null) {
            $input['oldestPostDateUnified'] = $window;
        }

        $response = $this->client->runActor(
            $this->source(),
            (string) config('services.apify.actors.tiktok'),
            $input,
        );

        return $this->normalizeBatch($response, function (array $item) use ($response): ?ContentData {
            $this->captureProfile($item, $response);

            // Quiet-day marker: with the date filter and no videos in the
            // window, the actor still pushes ONE authorMeta-only item so
            // profile stats keep refreshing. Not content, not an error.
            //
            // A marker carries ONLY author stats — no video-signal fields. An
            // item that looks like a video but has a missing/non-string id
            // (external schema drift: `id` renamed or emitted as a number)
            // must NOT be mistaken for a marker and silently dropped — it
            // falls through to requireString below and quarantines loudly,
            // exactly as the Instagram adapter does.
            $looksLikeVideo = isset($item['videoMeta'])
                || isset($item['webVideoUrl'])
                || isset($item['playCount']);

            if (! is_string($item['id'] ?? null) && is_array($item['authorMeta'] ?? null) && ! $looksLikeVideo) {
                return null;
            }

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
                mediaUrls: array_values(array_filter([
                    $this->downloadUrl($item),
                ])),
                publishedAt: Extract::timestamp($item, 'createTimeISO', 'createTime'),
                publicMetrics: array_values(array_filter([
                    Extract::publicMetric('views', Extract::int($item, 'playCount')),
                    Extract::publicMetric('likes', Extract::int($item, 'diggCount')),
                    Extract::publicMetric('comments', Extract::int($item, 'commentCount')),
                    Extract::publicMetric('shares', Extract::int($item, 'shareCount')),
                    Extract::publicMetric('saves', Extract::int($item, 'collectCount')),
                ])),
                provenance: new Provenance($this->source(), CarbonImmutable::now(), $response->sourceVersion),
                permalink: Extract::string($item, 'webVideoUrl'),
                mentions: \App\Platform\Ingestion\Normalization\SignalExtract::mentions($item),
                productTags: \App\Platform\Ingestion\Normalization\SignalExtract::productTags($item),
                collaborators: \App\Platform\Ingestion\Normalization\SignalExtract::collaborators($item),
                brandedContentLabel: \App\Platform\Ingestion\Normalization\SignalExtract::brandedContentLabel($item),
            );
        });
    }

    public function profileFromLastFetch(): ?ProfileData
    {
        return $this->lastProfile;
    }

    /**
     * Capture the account profile from an item's authorMeta (same mapping
     * as TikTokProfileAdapter). First item wins — a profile scrape's items
     * all share one author.
     *
     * @param  array<array-key, mixed>  $item
     */
    private function captureProfile(array $item, ProviderResponse $response): void
    {
        if ($this->lastProfile !== null) {
            return;
        }

        $author = $item['authorMeta'] ?? null;

        if (! is_array($author)) {
            return;
        }

        $username = Extract::string($author, 'name', 'uniqueId');

        if ($username === null || $username === '') {
            return;
        }

        $this->lastProfile = new ProfileData(
            platform: Platform::TikTok,
            handle: $username,
            bio: Extract::string($author, 'signature'),
            externalLinks: array_filter([Extract::string($author, 'bioLink')]),
            followerCount: Extract::publicMetric('followers', Extract::int($author, 'fans', 'followers')),
            provenance: new Provenance($this->source(), CarbonImmutable::now(), $response->sourceVersion),
        );
    }

    /**
     * The actor's direct CDN media URL — the REAL video file (sub-project B).
     * Null when the actor supplies none: media_urls then stays EMPTY — the
     * watch page is NEVER a media candidate (downstream reports the existing
     * media:none marker instead of wasting a fetch on HTML).
     *
     * @param  array<array-key, mixed>  $item
     */
    private function downloadUrl(array $item): ?string
    {
        $urls = $item['mediaUrls'] ?? null;

        if (is_array($urls) && is_string($urls[0] ?? null) && $urls[0] !== '') {
            return $urls[0];
        }

        $videoMeta = is_array($item['videoMeta'] ?? null) ? $item['videoMeta'] : [];

        return Extract::string($videoMeta, 'downloadAddr');
    }
}
