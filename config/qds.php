<?php

// QDS platform-level configuration. Canonical facts (enums, entities, sources,
// roles) live in docs/ — this file only holds runtime toggles and metadata.

return [

    /*
    |--------------------------------------------------------------------------
    | Build / version metadata
    |--------------------------------------------------------------------------
    | Surfaced by the /health endpoint. Set by CI/CD in production.
    */
    'build' => [
        'sha' => env('QDS_BUILD_SHA'),
        'time' => env('QDS_BUILD_TIME'),
    ],

    /*
    |--------------------------------------------------------------------------
    | SVC-SnapshotScheduler (ADR-0003)
    |--------------------------------------------------------------------------
    | Recurring MetricSnapshot capture. Disabled until the SRC-* connectors
    | exist (remaining P0 work). The scheduler entry is registered but gated
    | on this flag so no job runs against a not-yet-implemented pipeline.
    */
    'snapshots' => [
        'enabled' => env('QDS_SNAPSHOTS_ENABLED', false),
        // Cadence is NOT canonically decided (flagged missing decision; cost
        // governance is P4 roadmap work) — configurable until an ADR fixes it.
        // Minimum spacing between two snapshots of the same target.
        'min_interval_minutes' => (int) env('QDS_SNAPSHOTS_MIN_INTERVAL_MINUTES', 55),
        // Content items published within this window keep receiving
        // content-level snapshots.
        'content_window_days' => (int) env('QDS_SNAPSHOTS_CONTENT_WINDOW_DAYS', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | SVC-Ingestion (L2) — provider polling pipeline
    |--------------------------------------------------------------------------
    | Roster-first monitoring (ADR-0011): profile, content, and story
    | polling for tracked creators via the frozen SRC-* providers only.
    | Poll cadences are NOT canonically decided (flagged missing decision) —
    | configurable here until an ADR fixes them.
    */
    'ingestion' => [
        'enabled' => env('QDS_INGESTION_ENABLED', false),

        // Cron expressions for the recurring cycles. Stories get a tighter
        // default cadence because they expire at the platform (REQ-M1-004).
        'cycle_cron' => env('QDS_INGESTION_CYCLE_CRON', '0 */6 * * *'),
        'story_cycle_cron' => env('QDS_INGESTION_STORY_CYCLE_CRON', '30 */4 * * *'),

        // A RUNNING cycle older than this is considered stale (marked STALE
        // by the status refresh; a new cycle may then start).
        'cycle_stale_after_minutes' => (int) env('QDS_INGESTION_CYCLE_STALE_MINUTES', 180),

        // Max results requested per content poll (cost control).
        'content_results_limit' => (int) env('QDS_INGESTION_CONTENT_LIMIT', 30),

        // Short-form classification threshold (seconds) for platforms whose
        // raw→domain mapping allows SHORT / VIDEO (TikTok, YouTube).
        'short_video_max_seconds' => (int) env('QDS_INGESTION_SHORT_VIDEO_MAX_SECONDS', 60),

        // Private object storage disk for archived media (stories).
        'media_disk' => env('QDS_MEDIA_DISK', 'media'),

        // Lifetime of signed URLs granting access to archived media.
        'signed_url_ttl_minutes' => (int) env('QDS_MEDIA_SIGNED_URL_TTL_MINUTES', 10),

        // Quarantined-record retention (redacted payloads, DP-005).
        'quarantine_retention_days' => (int) env('QDS_INGESTION_QUARANTINE_RETENTION_DAYS', 14),

        // Provider-call telemetry retention.
        'telemetry_retention_days' => (int) env('QDS_INGESTION_TELEMETRY_RETENTION_DAYS', 90),

        // Limited response sampling for debugging: redacted before storage,
        // short retention, ADMIN-only access (ProviderResponseSamplePolicy).
        // Per-provider overrides: 'providers' => ['SRC-…' => ['enabled' => true, 'rate' => 0.1]].
        'sampling' => [
            'defaults' => [
                'enabled' => env('QDS_INGESTION_SAMPLING_ENABLED', false),
                'rate' => (float) env('QDS_INGESTION_SAMPLING_RATE', 0.05),
                'max_items' => 3,
                'retention_days' => (int) env('QDS_INGESTION_SAMPLING_RETENTION_DAYS', 7),
            ],
            'providers' => [],
        ],

        // External API Monitoring thresholds.
        'observability' => [
            // Rolling window for the provider health view.
            'health_window_hours' => (int) env('QDS_INGESTION_HEALTH_WINDOW_HOURS', 24),
            // Provider status flips to FAILING (+ alert) at this streak.
            'failing_after_consecutive_failures' => (int) env('QDS_INGESTION_FAILING_AFTER', 3),
            // Stale-data warning when a used provider has no success for this long.
            'stale_after_hours' => (int) env('QDS_INGESTION_STALE_AFTER_HOURS', 24),
            // Story-polling risk alert window (stories expire, REQ-M1-004).
            'story_polling_risk_hours' => (int) env('QDS_INGESTION_STORY_RISK_HOURS', 12),
            // Schema-drift alert when this share of one call's records fail
            // structural validation.
            'schema_drift_alert_ratio' => (float) env('QDS_INGESTION_SCHEMA_DRIFT_RATIO', 0.5),
            // Abnormal-duration alert threshold for one provider call.
            'abnormal_duration_ms' => (int) env('QDS_INGESTION_ABNORMAL_DURATION_MS', 120000),
            // Excessive-retries alert threshold (retry count of one job).
            'excessive_retry_count' => (int) env('QDS_INGESTION_EXCESSIVE_RETRIES', 2),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | SVC-EnrichmentAI (L3) — classification, sentiment, recognition, EMV
    |--------------------------------------------------------------------------
    | Runtime toggles for the enrichment pipeline. Enrichment cadence is NOT
    | canonically decided (same flagged missing decision as ingestion) —
    | configurable here until an ADR fixes it. Recognition providers are the
    | frozen SRC-google-* contracts; a provider with no credentials is
    | skipped and its outputs stay unavailable (never fabricated).
    */
    'enrichment' => [
        'enabled' => env('QDS_ENRICHMENT_ENABLED', false),

        // Recurring sweep over recently ingested, not-yet-enriched content.
        'sweep_cron' => env('QDS_ENRICHMENT_SWEEP_CRON', '15 */6 * * *'),

        // Max targets picked up per sweep (cost control).
        'sweep_batch' => (int) env('QDS_ENRICHMENT_SWEEP_BATCH', 50),

        // Content published within this window is eligible for enrichment.
        'content_window_days' => (int) env('QDS_ENRICHMENT_CONTENT_WINDOW_DAYS', 30),

        // Numeric provider score → ENUM-ConfidenceLevel bucketing. The
        // cut-points are NOT canonically decided (flagged missing decision;
        // DP-004 only says LOW routes to review) — configurable until an
        // ADR fixes them. score >= high → HIGH; >= medium → MEDIUM; else LOW.
        'confidence' => [
            'high' => (float) env('QDS_ENRICHMENT_CONFIDENCE_HIGH', 0.85),
            'medium' => (float) env('QDS_ENRICHMENT_CONFIDENCE_MEDIUM', 0.60),
        ],

        'attribution' => [
            // A shipment supports SEEDED attribution only when the content
            // was published within this many days after delivery/shipping.
            // NOT canonically decided (flagged) — configurable until an
            // ADR fixes it.
            'shipment_window_days' => (int) env('QDS_ENRICHMENT_SHIPMENT_WINDOW_DAYS', 60),
        ],

        'hashtags' => [
            // Generic hashtags never count as campaign/brand/product/agency
            // evidence, even if someone configures them in a list. The
            // blocklist is operational config, not canon; extend per market.
            'generic' => [
                'ad', 'advertising', 'beauty', 'fashion', 'fitness', 'food',
                'fun', 'fyp', 'gesponsert', 'instagood', 'lifestyle', 'love',
                'makeup', 'mode', 'ootd', 'photooftheday', 'reels', 'skincare',
                'style', 'trending', 'viral', 'werbung',
            ],
        ],

        'metrics' => [
            // MET-EngagementRate divisor: "followers or views per the
            // configured model" (metrics catalog). The choice is part of
            // the transparent, configured model and is disclosed with
            // every DERIVED rate.
            'engagement_base' => env('QDS_ENRICHMENT_ENGAGEMENT_BASE', 'followers'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | SVC-Analytics (ADR-0010, amended by ADR-0013)
    |--------------------------------------------------------------------------
    | Scheduled rollup refresh (materialized views / rollup tables) driven by
    | the app scheduler — Neon Postgres has no pg_cron. Disabled until the
    | FACT- / DIM- / ROLLUP- structures are migrated.
    */
    'analytics' => [
        'rollup_refresh_enabled' => env('QDS_ANALYTICS_ROLLUP_REFRESH_ENABLED', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | SVC-Export (REQ-M1-012)
    |--------------------------------------------------------------------------
    | Exports render rollup-backed reports into PRIVATE storage; access is
    | via short-lived signed links only, and artifacts expire and are
    | deleted automatically (DP-005 retention). TTLs are operational
    | config, not canon.
    */
    'exports' => [
        'disk' => env('QDS_EXPORTS_DISK', 'exports'),
        'ttl_hours' => (int) env('QDS_EXPORTS_TTL_HOURS', 24),
        'download_link_ttl_minutes' => (int) env('QDS_EXPORTS_LINK_TTL_MINUTES', 10),
    ],

    /*
    |--------------------------------------------------------------------------
    | Personal-data protection (DP-005)
    |--------------------------------------------------------------------------
    | Keyed blind-index hashing enables exact lookup on encrypted personal
    | fields (e.g. find a creator by email) without a plaintext shadow column
    | and without a plain unsalted hash. Key is environment-managed only.
    */
    'security' => [
        'blind_index_key' => env('QDS_BLIND_INDEX_KEY'),
    ],
];
