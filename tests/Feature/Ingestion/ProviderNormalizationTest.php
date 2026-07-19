<?php

namespace Tests\Feature\Ingestion;

use App\Platform\Ingestion\DTO\ContentData;
use App\Platform\Ingestion\DTO\ProfileData;
use App\Platform\Ingestion\DTO\StoryData;
use App\Platform\Ingestion\Providers\Instagram\InstagramPostAdapter;
use App\Platform\Ingestion\Providers\Instagram\InstagramProfileAdapter;
use App\Platform\Ingestion\Providers\Instagram\InstagramReelAdapter;
use App\Platform\Ingestion\Providers\Instagram\InstagramStoryAdapter;
use App\Platform\Ingestion\Providers\TikTok\TikTokContentAdapter;
use App\Platform\Ingestion\Providers\TikTok\TikTokProfileAdapter;
use App\Platform\Ingestion\Providers\YouTube\YouTubeContentAdapter;
use App\Platform\Ingestion\Providers\YouTube\YouTubeProfileAdapter;
use App\Platform\Ingestion\SourceRegistry;
use App\Platform\Ingestion\Support\ErrorCategory;
use App\Shared\Enums\ContentType;
use App\Shared\Enums\MetricTier;
use App\Shared\Enums\Platform;
use App\Shared\ValueObjects\MetricValue;
use Tests\Support\FakesProviderResponses;
use Tests\TestCase;

/**
 * Provider normalization + provenance mapping (raw→domain mapping,
 * data-source matrix §4): every accepted record becomes a documented DTO
 * carrying Provenance with the exact SRC-* id (DP-002); malformed items
 * are rejected with the right category, missing OPTIONAL fields are
 * tolerated, and PUBLIC metrics carry tier PUBLIC (DP-001).
 */
class ProviderNormalizationTest extends TestCase
{
    use FakesProviderResponses;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeProviderCredentials();
    }

    public function test_instagram_profile_normalizes_with_provenance(): void
    {
        $this->fakeApifyActor('apify~instagram-profile-scraper', $this->fixture('instagram-profile'));

        $batch = app(InstagramProfileAdapter::class)->fetchProfile('styleicon.de');

        $this->assertCount(1, $batch->items);
        $this->assertCount(0, $batch->rejected);

        /** @var ProfileData $profile */
        $profile = $batch->items[0];
        $this->assertSame('styleicon.de', $profile->handle);
        $this->assertSame(Platform::Instagram, $profile->platform);
        $this->assertSame('Fashion & Beauty aus München', $profile->bio);
        $this->assertContains('https://styleicon.example/shop', $profile->externalLinks);
        $this->assertContains('https://styleicon.example', $profile->externalLinks);

        $this->assertInstanceOf(MetricValue::class, $profile->followerCount);
        $this->assertSame(125000.0, $profile->followerCount->amount);
        $this->assertSame(MetricTier::Public, $profile->followerCount->tier);
        $this->assertSame('followers', $profile->followerCount->metric);

        // Provenance mapping (DP-002): exact SRC-* id + actor version.
        $this->assertSame(SourceRegistry::APIFY_INSTAGRAM_PROFILE_SCRAPER, $profile->provenance->source);
        $this->assertSame('apify~instagram-profile-scraper', $profile->provenance->sourceVersion);
        $this->assertNotNull($profile->provenance->fetchedAt);
    }

    public function test_instagram_posts_normalize_types_and_reject_malformed_items(): void
    {
        $this->fakeApifyActor('apify~instagram-post-scraper', $this->fixture('instagram-posts'));

        $batch = app(InstagramPostAdapter::class)->fetchContent('styleicon.de');

        // 2 valid, 3 rejected (no id / unknown type / non-numeric count).
        $this->assertCount(2, $batch->items);
        $this->assertCount(3, $batch->rejected);

        /** @var ContentData $image */
        $image = $batch->items[0];
        $this->assertSame(ContentType::ImagePost, $image->contentType);
        $this->assertSame('3412345678901234567', $image->externalId);
        $this->assertSame(SourceRegistry::APIFY_INSTAGRAM_POST_SCRAPER, $image->provenance->source);

        /** @var ContentData $carousel */
        $carousel = $batch->items[1];
        $this->assertSame(ContentType::Carousel, $carousel->contentType);
        $this->assertCount(3, $carousel->mediaUrls);

        $categories = array_map(fn ($r) => $r->category, $batch->rejected);
        $this->assertContains(ErrorCategory::MissingRequiredFields, $categories);
        $this->assertContains(ErrorCategory::SchemaDrift, $categories);
        $this->assertContains(ErrorCategory::InvalidFieldTypes, $categories);

        // STORY never appears as a ContentType (rule F8) — nothing to map to it.
        foreach ($batch->items as $item) {
            $this->assertNotSame('STORY', $item->contentType->value);
        }
    }

    public function test_instagram_reels_tolerate_missing_optional_metrics(): void
    {
        $this->fakeApifyActor('apify~instagram-reel-scraper', $this->fixture('instagram-reels'));

        $batch = app(InstagramReelAdapter::class)->fetchContent('styleicon.de');

        $this->assertCount(2, $batch->items);
        $this->assertCount(0, $batch->rejected);

        /** @var ContentData $full */
        $full = $batch->items[0];
        $this->assertSame(ContentType::Reel, $full->contentType);
        $metricNames = array_map(fn (MetricValue $m) => $m->metric, $full->publicMetrics);
        $this->assertSame(['plays', 'views', 'likes', 'comments', 'shares'], $metricNames);

        foreach ($full->publicMetrics as $metric) {
            $this->assertSame(MetricTier::Public, $metric->tier);
        }

        /** @var ContentData $bare */
        $bare = $batch->items[1];
        $this->assertSame([], $bare->publicMetrics); // absent counts stay absent — never fabricated zeros
        $this->assertNull($bare->publishedAt);
    }

    public function test_instagram_stories_normalize_and_quarantine_unidentifiable_items(): void
    {
        $this->fakeApifyActor((string) config('services.apify.actors.instagram_story'), $this->fixture('instagram-stories'));

        $batch = app(InstagramStoryAdapter::class)->fetchStories('styleicon.de');

        $this->assertCount(2, $batch->items);
        $this->assertCount(1, $batch->rejected);

        /** @var StoryData $video */
        $video = $batch->items[0];
        $this->assertSame('story-77001', $video->externalId);
        $this->assertSame('https://cdn.example/story1.mp4', $video->mediaSourceUrl);
        $this->assertNotNull($video->expiresAt);
        $this->assertSame(SourceRegistry::APIFY_INSTAGRAM_STORY_DETAILS, $video->provenance->source);

        /** @var StoryData $image */
        $image = $batch->items[1];
        $this->assertNull($image->expiresAt); // never fabricated from an assumed window
    }

    public function test_tiktok_profile_dedupes_author_and_maps_public_counts(): void
    {
        $this->fakeApifyActor('clockworks~tiktok-scraper', $this->fixture('tiktok-items'));

        $batch = app(TikTokProfileAdapter::class)->fetchProfile('styleicon');

        $this->assertCount(1, $batch->items);

        /** @var ProfileData $profile */
        $profile = $batch->items[0];
        $this->assertSame(Platform::TikTok, $profile->platform);
        $this->assertSame('styleicon', $profile->handle);
        $this->assertSame(480000.0, $profile->followerCount?->amount);
        $this->assertSame(SourceRegistry::CLOCKWORKS_TIKTOK_SCRAPER, $profile->provenance->source);
    }

    public function test_tiktok_content_classifies_short_vs_video_by_duration(): void
    {
        config(['qds.ingestion.short_video_max_seconds' => 60]);
        $this->fakeApifyActor('clockworks~tiktok-scraper', $this->fixture('tiktok-items'));

        $adapter = app(TikTokContentAdapter::class);
        $batch = $adapter->fetchContent('styleicon');

        $this->assertCount(3, $batch->items);
        // The id-less item carries authorMeta — under rec 4 it is the
        // quiet-day profile marker, silently skipped rather than
        // quarantined, and the profile is captured from the payload.
        $this->assertCount(0, $batch->rejected);
        $this->assertSame('styleicon', $adapter->profileFromLastFetch()?->handle);

        /** @var ContentData $short */
        $short = $batch->items[0];
        $this->assertSame(ContentType::Short, $short->contentType); // 34s
        $this->assertSame(
            ['views', 'likes', 'comments', 'shares', 'saves'],
            array_map(fn (MetricValue $m) => $m->metric, $short->publicMetrics),
        );

        /** @var ContentData $long */
        $long = $batch->items[1];
        $this->assertSame(ContentType::Video, $long->contentType); // 184s
        $this->assertNotNull($long->publishedAt); // unix createTime parsed
    }

    public function test_tiktok_video_with_a_drifted_non_string_id_is_quarantined_not_silently_dropped(): void
    {
        // A genuine quiet-day marker carries ONLY author stats. A real video
        // whose `id` drifts to a non-string (actor emits it as a JSON number,
        // or renames the key) must NOT be mistaken for a marker and dropped —
        // it must quarantine loudly, or TikTok content vanishes silently on a
        // plausible external schema change (review cost#1).
        $this->fakeApifyActor('clockworks~tiktok-scraper', [
            [
                'id' => '7301234567890123456',
                'text' => 'real video',
                'webVideoUrl' => 'https://www.tiktok.com/@styleicon/video/7301234567890123456',
                'createTimeISO' => '2026-07-01T10:00:00.000Z',
                'playCount' => 1000, 'diggCount' => 50, 'commentCount' => 5, 'shareCount' => 2, 'collectCount' => 1,
                'videoMeta' => ['duration' => 30],
                'authorMeta' => ['name' => 'styleicon', 'fans' => 90000],
            ],
            [
                // Same shape, but the id drifted to a NUMBER — a video by every
                // other signal (videoMeta/playCount/webVideoUrl), not a marker.
                'id' => 7301234567890123499,
                'text' => 'drifted id',
                'webVideoUrl' => 'https://www.tiktok.com/@styleicon/video/7301234567890123499',
                'playCount' => 500, 'diggCount' => 10, 'commentCount' => 1, 'shareCount' => 0, 'collectCount' => 0,
                'videoMeta' => ['duration' => 20],
                'authorMeta' => ['name' => 'styleicon', 'fans' => 90000],
            ],
        ]);

        $batch = app(TikTokContentAdapter::class)->fetchContent('styleicon');

        // The good video is accepted; the drifted one is quarantined, NOT
        // silently returned-as-null and dropped.
        $this->assertCount(1, $batch->items);
        $this->assertCount(1, $batch->rejected);
    }

    public function test_tiktok_media_urls_carry_the_real_download_url_never_the_watch_page(): void
    {
        $this->fakeProviderCredentials();
        $this->fakeApifyActor((string) config('services.apify.actors.tiktok'), $this->fixture('tiktok-items'));

        $batch = app(\App\Platform\Ingestion\Providers\TikTok\TikTokContentAdapter::class)->fetchContent('styleicon');
        // Key by externalId — never by array position (fixture ordering and
        // rejected-item behaviour must not break unrelated assertions).
        $items = collect($batch->items)
            ->filter(fn ($i) => $i instanceof \App\Platform\Ingestion\DTO\ContentData)
            ->keyBy(fn (\App\Platform\Ingestion\DTO\ContentData $i) => $i->externalId);

        // The actor's mediaUrls list wins.
        $this->assertSame(['https://cdn.tiktok.example/video/7301234567890123456.mp4'], $items['7301234567890123456']->mediaUrls);
        $this->assertSame('https://www.tiktok.com/@styleicon/video/7301234567890123456', $items['7301234567890123456']->permalink);

        // videoMeta.downloadAddr is the secondary source.
        $this->assertSame(['https://cdn.tiktok.example/download/7301234567890123457.mp4'], $items['7301234567890123457']->mediaUrls);

        // No download URL from the actor → NO media candidate (the watch
        // page is never media; downstream reports media:none). The page URL
        // survives as the permalink only.
        $this->assertSame([], $items['7301234567890123458']->mediaUrls);
        $this->assertSame('https://www.tiktok.com/@styleicon/video/7301234567890123458', $items['7301234567890123458']->permalink);
    }

    public function test_youtube_profile_maps_channel_and_respects_hidden_subscribers(): void
    {
        $this->fakeYouTubeApi();

        $batch = app(YouTubeProfileAdapter::class)->fetchProfile('styleicon');

        /** @var ProfileData $profile */
        $profile = $batch->items[0];
        $this->assertSame(Platform::YouTube, $profile->platform);
        $this->assertSame('@styleicon', $profile->handle);
        $this->assertSame(310000.0, $profile->followerCount?->amount);
        $this->assertSame('subscribers', $profile->followerCount?->metric);
        $this->assertSame(SourceRegistry::YOUTUBE_DATA_API_V3, $profile->provenance->source);
    }

    public function test_youtube_content_chains_calls_and_classifies_shorts(): void
    {
        config(['qds.ingestion.short_video_max_seconds' => 60]);
        $this->fakeYouTubeApi();

        $batch = app(YouTubeContentAdapter::class)->fetchContent('styleicon');

        $this->assertCount(2, $batch->items);

        /** @var ContentData $video */
        $video = $batch->items[0];
        $this->assertSame(ContentType::Video, $video->contentType); // 12m31s
        $this->assertSame('vid00000001', $video->externalId);
        $this->assertSame(['views', 'likes', 'comments'], array_map(fn (MetricValue $m) => $m->metric, $video->publicMetrics));

        /** @var ContentData $short */
        $short = $batch->items[1];
        $this->assertSame(ContentType::Short, $short->contentType); // 45s
        $this->assertSame(SourceRegistry::YOUTUBE_DATA_API_V3, $short->provenance->source);
    }

    public function test_youtube_media_urls_carry_the_best_thumbnail_and_permalink_the_watch_url(): void
    {
        $this->fakeProviderCredentials();
        $this->fakeYouTubeApi();

        $batch = app(\App\Platform\Ingestion\Providers\YouTube\YouTubeContentAdapter::class)->fetchContent('stylechannel');
        // Key by externalId — never by array position.
        $items = collect($batch->items)
            ->filter(fn ($i) => $i instanceof \App\Platform\Ingestion\DTO\ContentData)
            ->keyBy(fn (\App\Platform\Ingestion\DTO\ContentData $i) => $i->externalId);

        $this->assertSame(['https://i.ytimg.example/vi/vid00000001/maxresdefault.jpg'], $items['vid00000001']->mediaUrls);
        $this->assertSame('https://www.youtube.com/watch?v=vid00000001', $items['vid00000001']->permalink);
        // vid00000002 has no maxres → the ladder falls back to high.
        $this->assertSame(['https://i.ytimg.example/vi/vid00000002/hqdefault.jpg'], $items['vid00000002']->mediaUrls);
    }
}
