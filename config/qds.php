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

        // Cron expressions for the recurring SLOTS. The content cron fixes
        // the finest pollable granularity (every 6h); how often an account
        // is actually polled within those slots is the operator-chosen
        // plan (monitoring_plan_settings via CadenceSettings, defaults
        // below). The story cron likewise only defines the available
        // slots; stories_per_day decides how many run.
        'cycle_cron' => env('QDS_INGESTION_CYCLE_CRON', '0 */6 * * *'),
        'story_cycle_cron' => env('QDS_INGESTION_STORY_CYCLE_CRON', '30 */4 * * *'),

        // Tiered content cadence DEFAULTS (product-owner decision,
        // 2026-07-08; overridable in-app on /monitoring/plan): creators on
        // a running campaign/seeding run poll every N hours; everyone else
        // on the slower baseline. <=6 = every cycle. CAUTION: engagement-
        // velocity KPIs need <=12h on the tier you care about; the slow
        // baseline sees new posts up to interval-hours late.
        'baseline_content_interval_hours' => (int) env('QDS_INGESTION_BASELINE_INTERVAL_HOURS', 84),
        'campaign_content_interval_hours' => (int) env('QDS_INGESTION_CAMPAIGN_INTERVAL_HOURS', 12),

        // Story polls per day (0 = off; max = the story cron's slot count,
        // 6 at the default every-4h cron). Product-owner default: 1/day.
        // CAUTION at 1/day: stories expire after 24h — one failed/late run
        // loses that day's stories unrecoverably; the researched safety
        // floor is 2-3/day (reviews/PLAN-apify-cost-optimization doc).
        'stories_per_day' => (int) env('QDS_INGESTION_STORIES_PER_DAY', 1),

        // A RUNNING cycle older than this is considered stale (marked STALE
        // by the status refresh; a new cycle may then start).
        'cycle_stale_after_minutes' => (int) env('QDS_INGESTION_CYCLE_STALE_MINUTES', 180),

        // Max results requested per content poll (cost control).
        'content_results_limit' => (int) env('QDS_INGESTION_CONTENT_LIMIT', 30),

        // Incremental metric-refresh window (cost plan rec 1, reviews/
        // PLAN-apify-cost-optimization-2026-07-07.md): content polls send a
        // provider-side date filter (Instagram `onlyPostsNewerThan`, TikTok
        // `oldestPostDateUnified`) so only items published within this many
        // days are fetched — engagement metrics keep refreshing while a post
        // is inside its growth window instead of re-buying the newest N
        // items forever. 0 disables the filter (legacy full-depth behavior).
        // NOTE: never confuse with "only new posts" — re-fetching in-window
        // posts IS the metric-refresh mechanism (snapshots are DB-only).
        'refresh_window_days' => (int) env('QDS_INGESTION_REFRESH_WINDOW_DAYS', 14),

        // Periodic full-depth sweep: at most every N days, one full cycle
        // runs WITHOUT the date filter to catch late-blooming engagement on
        // posts older than the refresh window. 0 disables the sweep.
        'full_sweep_interval_days' => (int) env('QDS_INGESTION_FULL_SWEEP_INTERVAL_DAYS', 7),

        // Kill switch for story polling (cost plan rec 2): the default
        // story actor is paid/rental and bills a per-RUN start fee — this
        // gate stops story cycles entirely without touching the crons.
        'stories_enabled' => (bool) env('QDS_INGESTION_STORIES_ENABLED', true),

        // Minimum spacing between PROFILE polls of one account (product-
        // owner cadence decision, 2026-07-08): profile data (followers,
        // bio) changes slowly, so the profile call no longer rides every
        // content cycle — it runs only when the last successful profile
        // fetch is older than this. Never-polled accounts always fetch;
        // on-demand creator runs always fetch. Weekly default ⇒ the
        // follower-growth series has weekly resolution (AC-M1-021 trade-
        // off accepted). 0 = legacy behavior (profile on every cycle).
        // Content polling is unaffected.
        'profile_poll_interval_hours' => (int) env('QDS_INGESTION_PROFILE_INTERVAL_HOURS', 168),

        // Story polls are batched: one actor run covers up to this many
        // handles (cost plan rec 3 — the per-run start fee dominates story
        // cost; batching amortizes it across the roster).
        'story_batch_size' => (int) env('QDS_INGESTION_STORY_BATCH_SIZE', 25),

        // Queue retry posture for provider-calling ingestion jobs
        // (cost plan rec 9): every retry can re-bill provider calls, so
        // both knobs are env-tunable. Backoff is comma-separated seconds.
        'job_tries' => (int) env('QDS_INGESTION_JOB_TRIES', 4),
        'job_backoff' => env('QDS_INGESTION_JOB_BACKOFF', '60,300,900,1800'),

        // Circuit breaker (cost plan recs 2+9): once a provider's health
        // state is FAILING with a PERMANENT error category (auth/access/
        // not-found), further calls are skipped for the cooldown — retrying
        // a paywalled or misconfigured actor only burns budget. After the
        // cooldown one canary probe goes through; success closes the
        // breaker, failure re-arms it.
        'circuit_breaker' => [
            'enabled' => (bool) env('QDS_INGESTION_CIRCUIT_ENABLED', true),
            'cooldown_minutes' => (int) env('QDS_INGESTION_CIRCUIT_COOLDOWN_MINUTES', 360),
        ],

        // Adaptive cadence (cost plan rec 7): accounts whose newest known
        // content is older than dormant_after_days are polled at most once
        // per demoted_interval_hours instead of every cycle. Never-polled
        // accounts and creators attached to an ACTIVE campaign or seeding
        // run are always polled. Story polls additionally require a story
        // seen within story_activity_window_days (plus one daily probe).
        'adaptive' => [
            'enabled' => (bool) env('QDS_INGESTION_ADAPTIVE_ENABLED', true),
            'dormant_after_days' => (int) env('QDS_INGESTION_DORMANT_AFTER_DAYS', 14),
            'demoted_interval_hours' => (int) env('QDS_INGESTION_DEMOTED_INTERVAL_HOURS', 24),
            'story_activity_window_days' => (int) env('QDS_INGESTION_STORY_ACTIVITY_WINDOW_DAYS', 7),
        ],

        // Campaign-linked metric refresh (cost plan follow-up): a daily
        // pass re-fetches metrics for content items that are LINKED to a
        // producing seeding campaign but have aged out of the refresh
        // window, via SRC-apify-instagram-scraper direct post URLs — so
        // campaign EMV/CPE stays live without widening the whole roster's
        // window. Instagram only in v1 (direct-URL refresh is verified for
        // the general Instagram actor; TikTok has no per-URL input).
        'campaign_refresh' => [
            'enabled' => (bool) env('QDS_CAMPAIGN_REFRESH_ENABLED', true),
            'max_urls_per_run' => (int) env('QDS_CAMPAIGN_REFRESH_MAX_URLS', 100),
            'batch_size' => (int) env('QDS_CAMPAIGN_REFRESH_BATCH_SIZE', 25),
            // Stop refreshing this many days after the campaign leaves its
            // producing statuses — metrics have settled by then.
            'settle_days' => (int) env('QDS_CAMPAIGN_REFRESH_SETTLE_DAYS', 30),
        ],

        // Short-form classification threshold (seconds) for platforms whose
        // raw→domain mapping allows SHORT / VIDEO (TikTok, YouTube).
        'short_video_max_seconds' => (int) env('QDS_INGESTION_SHORT_VIDEO_MAX_SECONDS', 60),

        // Private object storage disk for archived media (stories).
        'media_disk' => env('QDS_MEDIA_DISK', 'media'),

        // Lifetime of signed URLs granting access to archived media.
        'signed_url_ttl_minutes' => (int) env('QDS_MEDIA_SIGNED_URL_TTL_MINUTES', 10),

        // Archived story media retention (media storage lifecycle, DP-005).
        // Files older than this are deleted from the media disk and the
        // story keeps only its metadata. 0 disables pruning entirely.
        'media_retention_days' => (int) env('QDS_MEDIA_RETENTION_DAYS', 180),

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

        // Data-quality monitoring (P4 hardening): detects when the DATA
        // looks wrong even though provider calls succeed — the signature
        // of silent scraper breakage (TikTok anti-bot fragility).
        'data_quality' => [
            'enabled' => (bool) env('QDS_DATA_QUALITY_ENABLED', true),
            // Ignore accounts below this follower count — tiny accounts
            // legitimately hit zero and produce noisy ratios.
            'zero_drop_min_followers' => (int) env('QDS_DATA_QUALITY_MIN_FOLLOWERS', 100),
            // A drop of this share of followers between two consecutive
            // snapshots is treated as an anomaly (0.5 = lost half).
            'drop_alert_ratio' => (float) env('QDS_DATA_QUALITY_DROP_RATIO', 0.5),
            // A monitored account with snapshot history but no new point
            // for this long has a gap in its time series (snapshots are
            // hourly, so >24h means at least a full day of missed points).
            'snapshot_gap_hours' => (int) env('QDS_DATA_QUALITY_SNAPSHOT_GAP_HOURS', 26),
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

        // Rolling window N for the engagement trend (ADR-0024): compares
        // the last N days with the N days before. Config default; each
        // tenant can override it on Settings → Monitoring (ADR-0025).
        'engagement_trend_window_days' => (int) env('QDS_ENRICHMENT_TREND_WINDOW_DAYS', 30),

        // A run still RUNNING after this long was hard-killed (worker died
        // mid-run): the data-quality monitor reaps it as FAILED so its
        // target becomes sweep-eligible again (P4 hardening; mirrors the
        // ingestion stale-cycle refresh).
        'run_stale_after_minutes' => (int) env('QDS_ENRICHMENT_RUN_STALE_AFTER_MINUTES', 180),

        // SPOKEN_BRAND audio derivation: local ffmpeg pulls a mono 16 kHz
        // FLAC track out of video media before it goes to speech:recognize.
        // max_seconds is clamped to 60 in code — the sync endpoint's limit.
        'audio' => [
            'ffmpeg_path' => env('QDS_ENRICHMENT_FFMPEG_PATH', 'ffmpeg'),
            'max_seconds' => (int) env('QDS_ENRICHMENT_AUDIO_MAX_SECONDS', 60),
        ],

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
    | Content-to-campaign matching (REQ-M3-008)
    |--------------------------------------------------------------------------
    | The SeededContentLinker materializes shipment↔content links from the
    | SEEDED mentions attribution produces (internal only — no provider
    | calls). Cadence is NOT canonically decided (same flagged class as the
    | other cadences in this file).
    */
    'matching' => [
        'enabled' => env('QDS_MATCHING_ENABLED', false),

        // Incremental scan window (deep-review GAP-2): each pass only
        // re-walks mentions updated within this many hours — reclassified
        // and human-blessed mentions bump updated_at and re-enter the
        // window; `qds:link-seeded-content --all` forces a full rescan.
        // Keep the window comfortably wider than the schedule cadence.
        // Operational choice, NOT canonically decided (flagged class).
        'lookback_hours' => (int) env('QDS_MATCHING_LOOKBACK_HOURS', 48),
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
    | CRM document attachments (REQ-M3-010)
    |--------------------------------------------------------------------------
    | Uploaded contracts/briefs live on PRIVATE storage and are served only
    | through short-lived signed download links (the SVC-Export precedent) —
    | never a public URL. Disk + link TTL are operational config, not canon
    | (spec D7: size cap and extension allowlist are operational choices).
    */
    'documents' => [
        'disk' => env('QDS_DOCUMENTS_DISK', 'local'),
        'download_link_ttl_minutes' => (int) env('QDS_DOCUMENTS_LINK_TTL_MINUTES', 10),
    ],

    /*
    |--------------------------------------------------------------------------
    | CRM tasks & deadline reminders (REQ-M3-011)
    |--------------------------------------------------------------------------
    | A task "nearing its deadline" fires a reminder exactly once
    | (AC-M3-017; the reminder_sent_at stamp — spec D8/D9). The look-ahead
    | window is NOT canonically decided (same flagged class as the other
    | cadences in this file) — configurable until an ADR fixes it.
    */
    'tasks' => [
        'reminder_window_hours' => (int) env('QDS_TASKS_REMINDER_WINDOW_HOURS', 48),
    ],

    /*
    |--------------------------------------------------------------------------
    | GDPR tooling (P4 hardening, DP-005)
    |--------------------------------------------------------------------------
    | Data-subject export/erasure run on demand (qds:gdpr-export-creator /
    | qds:gdpr-erase-creator); retention enforcement runs daily. The
    | communication-log retention period is NOT canonically decided (same
    | flagged class as the cadences) — 0 disables it until an ADR fixes the
    | policy. GDPR export files always expire with qds.exports.ttl_hours.
    */
    'gdpr' => [
        'communication_log_retention_days' => (int) env('QDS_GDPR_COMMS_RETENTION_DAYS', 0),
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
