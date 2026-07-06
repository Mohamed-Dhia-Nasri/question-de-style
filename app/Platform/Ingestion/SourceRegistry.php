<?php

namespace App\Platform\Ingestion;

/**
 * The closed v1 provider registry (SRC-*).
 *
 * Canonical: docs/40-integrations/00-data-source-matrix.md. The stack is
 * frozen by ADR-0001 / DP-006 — do NOT add, swap, or invent providers here;
 * a new provider requires a superseding ADR first.
 *
 * Every externally-sourced record must carry a Provenance envelope whose
 * `source` is one of these ids (DP-002).
 */
final class SourceRegistry
{
    public const APIFY_INSTAGRAM_SCRAPER = 'SRC-apify-instagram-scraper';

    public const APIFY_INSTAGRAM_REEL_SCRAPER = 'SRC-apify-instagram-reel-scraper';

    public const APIFY_INSTAGRAM_PROFILE_SCRAPER = 'SRC-apify-instagram-profile-scraper';

    public const APIFY_INSTAGRAM_POST_SCRAPER = 'SRC-apify-instagram-post-scraper';

    public const APIFY_INSTAGRAM_COMMENT_SCRAPER = 'SRC-apify-instagram-comment-scraper';

    public const APIFY_INSTAGRAM_STORY_DETAILS = 'SRC-apify-instagram-story-details';

    /** The ONLY TikTok source (ADR-0002). */
    public const CLOCKWORKS_TIKTOK_SCRAPER = 'SRC-clockworks-tiktok-scraper';

    public const YOUTUBE_DATA_API_V3 = 'SRC-youtube-data-api-v3';

    public const GOOGLE_CLOUD_VISION = 'SRC-google-cloud-vision';

    public const GOOGLE_SPEECH_TO_TEXT = 'SRC-google-speech-to-text';

    /** Optional deep-analysis pass over full video content. */
    public const GOOGLE_VIDEO_INTELLIGENCE = 'SRC-google-video-intelligence';

    /** @return list<string> every registered SRC-* id */
    public static function all(): array
    {
        return [
            self::APIFY_INSTAGRAM_SCRAPER,
            self::APIFY_INSTAGRAM_REEL_SCRAPER,
            self::APIFY_INSTAGRAM_PROFILE_SCRAPER,
            self::APIFY_INSTAGRAM_POST_SCRAPER,
            self::APIFY_INSTAGRAM_COMMENT_SCRAPER,
            self::APIFY_INSTAGRAM_STORY_DETAILS,
            self::CLOCKWORKS_TIKTOK_SCRAPER,
            self::YOUTUBE_DATA_API_V3,
            self::GOOGLE_CLOUD_VISION,
            self::GOOGLE_SPEECH_TO_TEXT,
            self::GOOGLE_VIDEO_INTELLIGENCE,
        ];
    }

    public static function isRegistered(string $sourceId): bool
    {
        return in_array($sourceId, self::all(), true);
    }

    private function __construct() {}
}
