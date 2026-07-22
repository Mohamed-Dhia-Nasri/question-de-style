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
        // Sync run-sync-get-dataset-items calls 408 at Apify's 300s wall; a
        // client-side abort of a run that later completes server-side is
        // still billed and would then be retried as a SECOND billed run —
        // so the client timeout must sit ABOVE the server wall (rec 9).
        'timeout' => (int) env('APIFY_TIMEOUT_SECONDS', 330),
        // Overall deadline for the async run+poll path (batched runs that
        // would blow the 300s sync wall — stories, direct-URL refresh).
        'async_timeout' => (int) env('APIFY_ASYNC_TIMEOUT_SECONDS', 900),
        'actors' => [
            // SRC-apify-instagram-profile-scraper
            'instagram_profile' => env('APIFY_ACTOR_INSTAGRAM_PROFILE', 'apify~instagram-profile-scraper'),
            // SRC-apify-instagram-post-scraper
            'instagram_post' => env('APIFY_ACTOR_INSTAGRAM_POST', 'apify~instagram-post-scraper'),
            // SRC-apify-instagram-reel-scraper
            'instagram_reel' => env('APIFY_ACTOR_INSTAGRAM_REEL', 'apify~instagram-reel-scraper'),
            // SRC-apify-instagram-story-details — the `louisdeconinck` stories
            // actor named in the data-source matrix. The correct current slug
            // is `instagram-story-details-scraper` (the bare
            // `instagram-story-details` slug 404s). PAID actor (pay-per-event,
            // ~$7/1000 profiles + activation fee, "only available for paying
            // Apify users") — a non-paying account gets an access-error item
            // surfaced as AUTHENTICATION by ApifyClient. Input is
            // {"usernames": [bare handles]}; output is Instagram's RAW
            // private-API story object (snake_case: expiring_at,
            // video_versions[].url, image_versions2.candidates[].url,
            // media_type, user.username) — see InstagramStoryAdapter for the
            // normalization. Override per env.
            'instagram_story' => env('APIFY_ACTOR_INSTAGRAM_STORY', 'louisdeconinck~instagram-story-details-scraper'),
            // SRC-clockworks-tiktok-scraper — the ONLY TikTok source (ADR-0002)
            'tiktok' => env('APIFY_ACTOR_TIKTOK', 'clockworks~tiktok-scraper'),
            // SRC-apify-instagram-scraper — the general actor, used ONLY for
            // direct post-URL metric refresh of campaign-linked content
            // (qds.ingestion.campaign_refresh). Roster polling stays on the
            // specialized actors: verified price-identical or cheaper, and
            // the general actor returns one content type per run.
            'instagram_direct' => env('APIFY_ACTOR_INSTAGRAM_DIRECT', 'apify~instagram-scraper'),
            // SRC-apify-youtube-transcript (ADR-0028): YouTube captions text
            // for SPOKEN_BRAND — the only in-freeze YouTube spoken signal.
            'youtube_transcript' => env('APIFY_ACTOR_YOUTUBE_TRANSCRIPT', 'pintostudio~youtube-transcript-scraper'),
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

    /*
    |--------------------------------------------------------------------------
    | Google multimodal embeddings (SRC-google-gemini-embeddings — ADR-0029)
    |--------------------------------------------------------------------------
    | Sub-project C visual product matching. Model-based naming (survives
    | Google platform-brand churn). Bearer-token auth ONLY — API keys cannot
    | call :embedContent (verified 2026-07-19); credentials come ONLY from
    | environment-managed secrets. The eu multi-region endpoint is the only
    | EU location serving gemini-embedding-2 (residency: ML processing stays
    | within EU member states); the global endpoint has NO guarantee.
    */
    'google_embeddings' => [
        'credentials_path' => env('GOOGLE_EMBEDDINGS_CREDENTIALS'),   // service-account JSON key file
        'project_id' => env('GOOGLE_EMBEDDINGS_PROJECT'),
        'location' => env('GOOGLE_EMBEDDINGS_LOCATION', 'eu'), // EU multi-region — the only EU location serving this model (§5)
        'base_url' => env('GOOGLE_EMBEDDINGS_BASE_URL'),              // default derived from location
        'timeout' => (int) env('GOOGLE_EMBEDDINGS_TIMEOUT_SECONDS', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Google Gemini VLM verification (SRC-google-gemini-vlm — ADR-0030)
    |--------------------------------------------------------------------------
    | Sub-project D catalog-grounded verification (gemini-3.5-flash on the
    | EU jurisdictional multi-region — ML processing stays within EU member
    | states; the global endpoint carries NO residency guarantee and is
    | rejected). Bearer-token auth ONLY, via the generalized
    | service-account token provider; credentials come ONLY from
    | environment-managed secrets and MAY reuse the embeddings key file.
    */
    'google_vlm' => [
        'credentials_path' => env('GOOGLE_VLM_CREDENTIALS'),      // service-account JSON key file (may equal GOOGLE_EMBEDDINGS_CREDENTIALS)
        'project_id' => env('GOOGLE_VLM_PROJECT'),
        'location' => env('GOOGLE_VLM_LOCATION', 'eu'),           // EU multi-region (spec §5); 'global' is rejected — no residency guarantee
        'base_url' => env('GOOGLE_VLM_BASE_URL'),                 // default derived: https://aiplatform.eu.rep.googleapis.com/v1
        'timeout' => (int) env('GOOGLE_VLM_TIMEOUT_SECONDS', 60), // VLM calls are slower than embeddings
    ],

    /*
    |--------------------------------------------------------------------------
    | Google Speech-to-Text v2 (SRC-google-speech-to-text — ADR-0030)
    |--------------------------------------------------------------------------
    | Sub-project D multilingual speech (chirp_3, language auto-detect, EU
    | multi-region endpoint). v2 documents service-account auth ONLY (no
    | API keys) — Bearer tokens via the generalized token provider. The v1
    | 'google_speech' block above stays UNTOUCHED: it is the rollback path
    | while qds.enrichment.speech.v2_enabled is off.
    */
    'google_speech_v2' => [
        'credentials_path' => env('GOOGLE_SPEECH_V2_CREDENTIALS'), // service-account JSON key file
        'project_id' => env('GOOGLE_SPEECH_V2_PROJECT'),
        'location' => env('GOOGLE_SPEECH_V2_LOCATION', 'eu'),      // EU multi-region (spec §9)
        'base_url' => env('GOOGLE_SPEECH_V2_BASE_URL'),            // default derived: https://eu-speech.googleapis.com/v2
        'timeout' => (int) env('GOOGLE_SPEECH_V2_TIMEOUT_SECONDS', 60),
    ],

    /*
    |--------------------------------------------------------------------------
    | Stripe (ADR-0021 — SaaS billing processor, NOT a data provider)
    |--------------------------------------------------------------------------
    | Outside the frozen SRC-* data-provider set (ADR-0001): Stripe processes
    | payments and never supplies platform data. Secrets come ONLY from the
    | environment, travel ONLY in headers, and never appear in logs or
    | exceptions (the ApifyClient invariants). Card data never touches QDS —
    | checkout and payment-method entry happen on Stripe-hosted pages.
    */
    'stripe' => [
        'secret' => env('STRIPE_SECRET'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
        'base_url' => env('STRIPE_BASE_URL', 'https://api.stripe.com/v1'),
        'timeout' => (int) env('STRIPE_TIMEOUT_SECONDS', 30),
        // Max accepted Stripe-Signature timestamp skew (replay window).
        'webhook_tolerance_seconds' => (int) env('STRIPE_WEBHOOK_TOLERANCE_SECONDS', 300),
    ],

];
