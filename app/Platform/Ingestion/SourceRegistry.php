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

    /**
     * YouTube captions/transcript TEXT only (ADR-0028 amendment to the
     * ADR-0001 freeze): the pintostudio transcript actor. Never video or
     * audio bytes — YouTube media files stay out of reach in v1 (ToS).
     */
    public const APIFY_YOUTUBE_TRANSCRIPT = 'SRC-apify-youtube-transcript';

    public const GOOGLE_CLOUD_VISION = 'SRC-google-cloud-vision';

    public const GOOGLE_SPEECH_TO_TEXT = 'SRC-google-speech-to-text';

    /** Optional deep-analysis pass over full video content. */
    public const GOOGLE_VIDEO_INTELLIGENCE = 'SRC-google-video-intelligence';

    /**
     * Gemini multimodal embeddings for visual product matching (ADR-0029
     * amendment to the ADR-0001 freeze — sub-project C). Image bytes travel
     * INLINE (base64), never as URLs (DP-005); Bearer-token auth ONLY —
     * API keys cannot call :embedContent (verified 2026-07-19).
     */
    public const GOOGLE_GEMINI_EMBEDDINGS = 'SRC-google-gemini-embeddings';

    /**
     * Gemini VLM verification/grounding of sub-project C's candidates
     * (ADR-0030 amendment to the ADR-0001 freeze — sub-project D).
     * Keyframe bytes travel INLINE (base64) to the EU jurisdictional rep
     * endpoint (aiplatform.eu.rep.googleapis.com), never as URLs
     * (DP-005); Bearer-token (service-account JWT) auth only.
     */
    public const GOOGLE_GEMINI_VLM = 'SRC-google-gemini-vlm';

    /**
     * Internal marker, NOT an external provider (ADR-0015): the record's
     * values were entered by hand by agency staff in the CRM (operator-
     * curated platform accounts under ADR-0014). Performs no collection;
     * the frozen external stack (ADR-0001) is unchanged. Never stamp it on
     * a record that did come from an external provider.
     */
    public const AGENCY_MANUAL_ENTRY = 'SRC-agency-manual-entry';

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
            self::APIFY_YOUTUBE_TRANSCRIPT,
            self::GOOGLE_CLOUD_VISION,
            self::GOOGLE_SPEECH_TO_TEXT,
            self::GOOGLE_VIDEO_INTELLIGENCE,
            self::GOOGLE_GEMINI_EMBEDDINGS,
            self::GOOGLE_GEMINI_VLM,
            self::AGENCY_MANUAL_ENTRY,
        ];
    }

    public static function isRegistered(string $sourceId): bool
    {
        return in_array($sourceId, self::all(), true);
    }

    private function __construct() {}
}
