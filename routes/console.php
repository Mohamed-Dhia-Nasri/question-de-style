<?php

use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Scheduled tasks
|--------------------------------------------------------------------------
| Run locally with `php artisan schedule:work`; in production a single cron
| entry (or the scheduler container) runs `php artisan schedule:run` every
| minute. All QDS jobs are self-gating on their config flags, so they are
| registered unconditionally and skip cleanly until enabled.
|
| Cadences: snapshots hourly (recurring per ADR-0003), rollups every 30
| minutes (within the 15–60 min band of ADR-0013). The ingestion cycle
| cadences are NOT canonically decided (flagged missing decision; cost
| governance is P4) — they are configurable via qds.ingestion.* and default
| to every 6h (full cycle) / every 4h (story-only, tighter because stories
| expire — REQ-M1-004).
*/

Schedule::command('qds:capture-snapshots')->hourly();
Schedule::command('qds:refresh-rollups')->everyThirtyMinutes();

// SVC-Ingestion — roster monitoring cycles (REQ-M1-001, AC-M1-001).
Schedule::command('qds:run-monitoring-cycle')
    ->cron((string) config('qds.ingestion.cycle_cron'));
Schedule::command('qds:run-monitoring-cycle --stories-only')
    ->cron((string) config('qds.ingestion.story_cycle_cron'));

// SVC-EnrichmentAI — recovery backstop sweep (ADR-0023: enrichment is
// dispatched per data pull; this recurring sweep only catches targets
// whose run crashed or was reaped).
Schedule::command('qds:run-enrichment')
    ->cron((string) config('qds.enrichment.sweep_cron'));

// Content-to-campaign matching (REQ-M3-008): materialize shipment↔content
// links from the SEEDED mentions attribution produces. Cadence NOT
// canonically decided (flagged) — hourly interleaves the enrichment sweeps.
Schedule::command('qds:link-seeded-content')->hourly();
// Daily full rescan (verification finding on GAP-2): eligibility can change
// WITHOUT bumping a mention's updated_at (e.g. an operator attaches a parent
// campaign to a seeding run later), so mentions older than the hourly pass's
// lookback window would otherwise never be revisited. The daily --all pass
// heals all such aged-out state within 24h.
Schedule::command('qds:link-seeded-content --all')->dailyAt('04:30');

// Campaign-linked metric refresh (cost plan, reviews/PLAN-apify-cost-
// optimization-2026-07-07.md): keeps EMV/CPE live for content matched to
// producing seeding campaigns after it ages out of the roster's refresh
// window. Daily, after the 04:30 link-seeded-content --all pass so freshly
// healed links are refreshed the same morning.
Schedule::command('qds:refresh-campaign-content')->dailyAt('05:30');

// External API Monitoring: stale cycles/data, story-polling risk.
Schedule::command('qds:refresh-ingestion-status')->everyFifteenMinutes();

// Data-quality monitoring (P4 hardening): metric anomalies (zero-drops,
// implausible falls — TikTok scraper fragility), snapshot time-series
// gaps, and stale enrichment-run reaping. Hourly: anomalies only move
// when new snapshots land (hourly cadence).
Schedule::command('qds:check-data-quality')->hourly();

// Retention enforcement (samples, quarantine, telemetry — DP-005).
Schedule::command('qds:prune-ingestion-data')->daily();

// Media storage lifecycle (P4 hardening): archived story media past its
// retention window is deleted from the private media disk (DP-005).
Schedule::command('qds:prune-story-media')->daily();

// Derived-media lifecycle (sub-project B): persisted keyframes past the
// per-tenant retention window are deleted — file first, then row (DP-005).
Schedule::command('qds:prune-keyframes')->daily();

// SVC-Export retention: expired artifacts are deleted from private
// storage on schedule (DP-005; REQ-M1-012 export security).
Schedule::command('qds:prune-expired-exports')->hourly();

// GDPR retention enforcement (P4 hardening, DP-005): old communication
// logs (when a retention period is configured) and leftover GDPR export
// files past the export TTL.
Schedule::command('qds:gdpr-enforce-retention')->daily();

// CRM task deadline reminders (REQ-M3-011, AC-M3-017): fire-exactly-once
// via the reminder_sent_at stamp. Cadence NOT canonically decided
// (flagged, same class as the others) — hourly keeps reminders within an
// hour of entering the qds.tasks.reminder_window_hours look-ahead. No
// config gate: the command is self-limiting (only writes when due).
Schedule::command('qds:send-task-reminders')->hourly();
