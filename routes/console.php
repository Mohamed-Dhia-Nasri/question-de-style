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

// SVC-EnrichmentAI — enrichment sweep over newly ingested content
// (REQ-M1-002/008/009/011). Cadence NOT canonically decided (flagged) —
// configurable via qds.enrichment.sweep_cron.
Schedule::command('qds:run-enrichment')
    ->cron((string) config('qds.enrichment.sweep_cron'));

// External API Monitoring: stale cycles/data, story-polling risk.
Schedule::command('qds:refresh-ingestion-status')->everyFifteenMinutes();

// Retention enforcement (samples, quarantine, telemetry — DP-005).
Schedule::command('qds:prune-ingestion-data')->daily();

// SVC-Export retention: expired artifacts are deleted from private
// storage on schedule (DP-005; REQ-M1-012 export security).
Schedule::command('qds:prune-expired-exports')->hourly();
