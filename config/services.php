<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Apify (frozen SRC-* providers — ADR-0001/0002, DP-006)
    |--------------------------------------------------------------------------
    | Credentials come ONLY from environment-managed secrets. Actor ids are
    | env-tunable because the marketplace slug can change; the PROVIDER SET
    | may not — adding an actor for a new capability requires an ADR.
    */
    'apify' => [
        'token' => env('APIFY_TOKEN'),
        'base_url' => env('APIFY_BASE_URL', 'https://api.apify.com/v2'),
        'timeout' => (int) env('APIFY_TIMEOUT_SECONDS', 240),
        'actors' => [
            // SRC-apify-instagram-profile-scraper
            'instagram_profile' => env('APIFY_ACTOR_INSTAGRAM_PROFILE', 'apify~instagram-profile-scraper'),
            // SRC-apify-instagram-post-scraper
            'instagram_post' => env('APIFY_ACTOR_INSTAGRAM_POST', 'apify~instagram-post-scraper'),
            // SRC-apify-instagram-reel-scraper
            'instagram_reel' => env('APIFY_ACTOR_INSTAGRAM_REEL', 'apify~instagram-reel-scraper'),
            // SRC-apify-instagram-story-details.
            // DEVIATION (needs a doc/ADR note): the data-source matrix names
            // the `louisdeconinck` actor, but that actor is paid/rental and
            // its `instagram-story-details` slug 404s. Per the operator's
            // instruction the default is the `datavoyantlab` advanced stories
            // actor instead. The SRC-* contract id (provenance) is unchanged;
            // only the underlying Apify actor differs. NOTE: this actor is
            // ALSO paid — a free Apify account gets an access-error item
            // (surfaced as AUTHENTICATION by ApifyClient). Override per env.
            'instagram_story' => env('APIFY_ACTOR_INSTAGRAM_STORY', 'datavoyantlab~advanced-instagram-stories-scraper'),
            // SRC-clockworks-tiktok-scraper — the ONLY TikTok source (ADR-0002)
            'tiktok' => env('APIFY_ACTOR_TIKTOK', 'clockworks~tiktok-scraper'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | YouTube Data API v3 (SRC-youtube-data-api-v3)
    |--------------------------------------------------------------------------
    | Public statistics only; OAuth creator analytics are deferred (DEF-004).
    */
    'youtube' => [
        'api_key' => env('YOUTUBE_API_KEY'),
        'base_url' => env('YOUTUBE_BASE_URL', 'https://www.googleapis.com/youtube/v3'),
        'timeout' => (int) env('YOUTUBE_TIMEOUT_SECONDS', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Google AI recognition providers (frozen SRC-* set — ADR-0001, DP-006)
    |--------------------------------------------------------------------------
    | SRC-google-cloud-vision (IMAGE_TEXT_OCR, LOGO),
    | SRC-google-speech-to-text (SPOKEN_BRAND, German models — DACH focus),
    | SRC-google-video-intelligence (ON_SCREEN_TEXT, OPTIONAL deep pass).
    | Credentials come ONLY from environment-managed secrets. Recognition is
    | skipped (renders unavailable) while a provider has no key configured.
    */
    'google_vision' => [
        'api_key' => env('GOOGLE_VISION_API_KEY'),
        'base_url' => env('GOOGLE_VISION_BASE_URL', 'https://vision.googleapis.com/v1'),
        'timeout' => (int) env('GOOGLE_VISION_TIMEOUT_SECONDS', 60),
    ],

    'google_speech' => [
        'api_key' => env('GOOGLE_SPEECH_API_KEY'),
        'base_url' => env('GOOGLE_SPEECH_BASE_URL', 'https://speech.googleapis.com/v1'),
        'timeout' => (int) env('GOOGLE_SPEECH_TIMEOUT_SECONDS', 120),
        // German models enabled per the data-source matrix (DACH focus).
        'language_code' => env('GOOGLE_SPEECH_LANGUAGE', 'de-DE'),
    ],

    'google_video_intelligence' => [
        'api_key' => env('GOOGLE_VIDEO_INTELLIGENCE_API_KEY'),
        'base_url' => env('GOOGLE_VIDEO_INTELLIGENCE_BASE_URL', 'https://videointelligence.googleapis.com/v1'),
        'timeout' => (int) env('GOOGLE_VIDEO_INTELLIGENCE_TIMEOUT_SECONDS', 300),
    ],

];
