# Monitoring settings & decisions build — design

**Date:** 2026-07-17 · **Status:** APPROVED by user (plain-language walkthrough) · **Branch:** `feat/crm-ux-stage-a` (user chose to continue on this branch)

## Purpose

Turn the user's confirmed product decisions for Module 1 (Monitoring) into working software and canonical records:

1. A new per-tenant **Settings → Monitoring** page with four values (gift-link window, engagement-trend window, story keep-days, message-history keep-days).
2. **Engagement trend** computed and shown on the creator detail page (posting frequency stays hidden).
3. **AI enrichment runs per data pull** (event-driven) instead of relying on the timed sweep; the sweep becomes a recovery backstop.
4. **Decision records (ADR-0023..0026)** + reconciliation of the stale "NOT canonically decided" comments these decisions resolve.

User-confirmed decisions this design implements verbatim:
- AI check on every pull, no separate timer (sweep kept only as safety net).
- Confidence cut-points stay 0.85 / 0.60.
- Sentiment stays off (no model chosen; honest Unavailable).
- PAID mention label kept but never asserted (inert compatibility value).
- Engagement trend: avg likes+comments per post, last N days vs previous N days, % change; N is a tenant setting, default 30.
- Posting frequency: keep hidden.
- Defaults: gift window 60 days; story keep 180 days; message history keep = off (never delete).
- All four values on **one** settings page.

## 1. Settings storage

**New table `monitoring_settings`** (append-only "latest row wins", mirroring `monitoring_plan_settings` — new row per save, no update-in-place):

| column | type | notes |
|---|---|---|
| id | bigint pk | |
| tenant_id | FK tenants, NOT NULL | `BelongsToTenant` stamps + scopes it |
| shipment_window_days | smallint NOT NULL | 1..365; no off-state (0 is invalid — matches `max(1,...)` clamp semantics) |
| engagement_trend_window_days | smallint NOT NULL | 7..90, default 30 |
| story_retention_days | smallint NOT NULL | 0 = keep forever; else 1..3650 |
| communication_retention_days | int NOT NULL | 0 = keep forever (default); else 1..3650 |
| updated_by | FK users nullOnDelete | audit |
| timestamps | | |

Index `(tenant_id, id)` for latest-per-tenant reads.

**Model** `app/Modules/Monitoring/Models/MonitoringSetting.php` — `BelongsToTenant`, casts, no update/delete path needed (policy `delete → false`).

**Resolver** `app/Shared/Settings/MonitoringSettingsResolver.php` with one getter per value. Two access modes, chosen to kill the known `MonitoringPlanSetting::current()` cross-tenant-bleed trap (ADR-0019 known limitation — do NOT copy it):

- `forTenant(int $tenantId)` — explicit: `withoutGlobalScope` + `where tenant_id` + `latest('id')`. Used by tenant-less schedulers.
- Context mode (no argument) — requires an **active** `TenantContext` (HTTP request or `TenantContext::runAs` inside jobs). **When no context is bound, return the config default — never another tenant's row.**

Fallbacks are the existing config values (unchanged env defaults): `qds.enrichment.attribution.shipment_window_days` (60), `qds.ingestion.media_retention_days` (180), `qds.gdpr.communication_log_retention_days` (0), new `qds.enrichment.engagement_trend_window_days` (30). Per-getter in-instance memoization keyed by tenant id (safe under `runAs` switches).

## 2. Settings page (Settings → Monitoring)

Follow the single-setting-editor pattern from `EmvSettings`/`ReachSettings` exactly:

- **Component** `app/Modules/Monitoring/Livewire/Settings/MonitoringSettings.php`, alias `monitoring.monitoring-settings`; view `resources/views/livewire/monitoring/monitoring-settings.blade.php`; wrapper `resources/views/settings/monitoring.blade.php` (6-line shell: `x-layouts.app` + `x-page-header` breadcrumbs Dashboard → Settings → Monitoring + `@livewire`).
- **Route** `settings.monitoring` inside the existing `routes/web.php` settings group (`auth` + `can:settings.view` + subscription middleware). Sidebar: add "Monitoring" entry to the Settings section (same blade the EMV/Reach entries live in).
- **Permissions:** page view = `settings.view` (all staff). Saving = new catalog constant `MONITORING_SETTINGS_MANAGE = 'monitoring-settings.manage'`, ADMIN-only in `roleAssignments()`. New `MonitoringSettingPolicy` (`viewAny/view → settings.view`, `create → monitoring-settings.manage`, `update/delete → false`), registered in `MonitoringServiceProvider`. Enforcement layers as today: route middleware, `mount()` `authorize('viewAny')`, `save()` `authorize('create')`, `$canManage` for read-only UI.
- **UI:** four cards, each with a plain-language "How it works" explainer + worked example, per-field grey help captions, `x-ui.alert` for errors, inputs disabled when `!$canManage`:
  - *Gift link window* — integer input (`type="text" inputmode="numeric"`), caption with example: "Product sent 1 June + 60 days → posts up to 31 July can be linked."
  - *Engagement trend window* — integer input, caption: "Compares the last 30 days with the 30 days before."
  - *Story keep time* — toggle "Delete old story files automatically" + days input shown when on (off stores 0 = keep forever). Caption warns deletion is permanent; text metadata is kept.
  - *Message history keep time* — same toggle pattern, default off.
- **Validation:** hand-rolled `friendlyError()` plain-language strings into `$formError` (ranges above; integers only). No Livewire `$rules`.
- **Save:** single `save()` writes ONE new `monitoring_settings` row holding all four values (hydrate-from-latest-else-defaults on mount, exact round-trip), `updated_by = auth()->id()`, success toast via `dispatch('notify', ...)`. Caption under the save button: "Applies from now on — figures already calculated keep their original settings." (retention values apply at the next nightly cleanup).

## 3. Per-tenant retention enforcement

Both prune commands switch from one global `config()` value to a per-tenant loop (`Tenant::pluck('id')`), using `MonitoringSettingsResolver::forTenant()` (falls back to config defaults for tenants with no settings row — behavior unchanged for them):

- `PruneStoryMediaCommand`: per tenant — resolve `story_retention_days`; `0` → skip tenant; else delete media files + null `media_url` + stamp `media_pruned_at` for `Story` rows `WHERE tenant_id = ? AND captured_at < now − days` (explicit predicate — `TenantScope` is a no-op in artisan; keep `chunkById(100)`).
- `GdprEnforceRetentionCommand::pruneCommunicationLogs`: per tenant — resolve `communication_retention_days`; `0` → skip; else delete `CommunicationLog WHERE tenant_id = ? AND occurred_at < cutoff`. (The command's export-file half is untouched.)

Schedules unchanged (daily). Command output reports per-tenant counts.

## 4. Gift-link window consumption

`MentionClassifier` (lines ~211 and ~278) replaces `max(1, (int) config('qds.enrichment.attribution.shipment_window_days'))` with `max(1, $settings->shipmentWindowDays())` (constructor-injected resolver, context mode). Enrichment always runs inside `TenantContext::runAs`, so the active tenant's value applies; with no context the resolver returns the config default (60), keeping `MentionClassifierTest`'s pinned default green.

## 5. Engagement trend

**Formula (canonical, ADR-0024):** for a creator and window N (tenant setting, default 30):
- Pool = the creator's `ContentItem`s (via their platform accounts) with `published_at` in `[now−2N, now)`, split into *current* `[now−N, now)` and *previous* `[now−2N, now−N)` windows.
- Per-item engagement = sum of **observed** PUBLIC `likes` + `comments` from `content_items.public_metrics` (an item with only one observed contributes that one; an item with **neither** observed is **excluded** — missing is never zero).
- `avg(window)` = mean per-item engagement over included items. Result = `(avgCurrent − avgPrevious) / avgPrevious × 100`, rounded to a whole signed percent.
- **Unavailable (null)** when: previous window has no included items, or `avgPrevious = 0`, or current window has no included items. No fabricated zero.

**Implementation:**
- `DerivedMetricsService::engagementTrend()` — new signature `(Creator $creator, int $windowDays): ?EngagementTrend` (small readonly DTO: `currentAvg`, `previousAvg`, `percentChange`, `currentCount`, `previousCount`). Remove the "no canonical formula — do not invent" wording for engagement trend from the class/method docblocks (posting-frequency wording stays, citing ADR-0024's explicit non-decision). Live Eloquent under the HTTP tenant scope — same sanctioned precedent as CreatorDetail's existing avg/median computation. (Calendar-grain rollups cannot serve rolling windows; ADR-0024 records this read-path exception to ADR-0010.)
- `CreatorDetail` computes it in `mount`/`render` (window from the resolver) and the blade adds an **"Engagement trend"** tile in the existing DERIVED tile grid (next to Average/median views): value like `+12%` / `−8%` with the DERIVED (`Calculated`) `x-metric.tier-badge` and caption "Average likes + comments per post, last {N} days vs the {N} days before."; null → `<x-states.unavailable reason="Not enough posts in the two comparison windows yet." />`.
- Creators index, exports, analytics schema: **untouched** (creator page only, v1). `posting_frequency` surfaces stay Unavailable.
- Replace the null-asserting test in `MetricsAndReachTest` with formula tests.

## 6. Per-pull enrichment (ADR-0023)

**Dispatch on creation only — never on metric refresh** (avoids re-billing Google recognition and duplicate append-only `EmvResult`/`ReachResult` rows):

- `ContentItemPersister` collects the ids of rows it **creates** (created branch only) and exposes them on `PersistenceResult` as an additive `createdIds` field (default `[]`; existing callers unaffected).
- `IngestContentJob`, after each `persist()`: if `config('qds.enrichment.enabled')` and `createdIds` non-empty → keep ids whose `published_at ≥ now − qds.enrichment.content_window_days` (one `whereIn` query; mirrors the sweep's eligibility window so deep backfills don't trigger surprise cost) → `EnrichContentItemJob::dispatch(id, correlationId)` (reuse the ingestion correlation id when present, else fresh UUID). Jobs carry scalar ids only; `EnrichContentItemJob` re-establishes tenant via `runAs` from the row as today.
- `RefreshCampaignContentJob` shares the persister; refreshes update existing rows so `createdIds` is empty in practice — apply the same gated dispatch for correctness, no special-casing.
- **Stories:** enrichment needs archived media (media arrives asynchronously). `ArchiveStoryMediaJob` dispatches `EnrichStoryJob($storyId)` after a **successful** archive, gated on `qds.enrichment.enabled`. Stories without media are left to the sweep backstop.
- **Sweep** (`qds:run-enrichment`) is mechanically unchanged and stays scheduled: its `whereDoesntHave(RUNNING|COMPLETED)` predicate makes it a no-op for per-pull-enriched items and the recovery path for crashed/reaped runs (reaper flips stale RUNNING → FAILED → sweep-eligible). Update its config/comment framing from "cadence NOT canonically decided" to "backstop for per-pull dispatch (ADR-0023)".
- Kill switch: `QDS_ENRICHMENT_ENABLED` now gates the new dispatch sites **and** the sweep (jobs themselves stay ungated, as today, so explicit manual dispatch still works).
- Burst dedup: `ShouldBeUnique` (660 s) already covers double dispatch within a pull; per-pull dispatch fires once per created row by construction.

## 7. Decision records & doc reconciliation

Append to `docs/05-decisions/decision-log.md` (next free numbers):
- **ADR-0023 — Enrichment triggers per data pull; sweep demoted to backstop.** Closes the flagged "enrichment sweep cadence" gap (left open by ADR-0017).
- **ADR-0024 — Engagement-trend formula** (rolling N vs previous N, avg observed likes+comments, % change; per-tenant window default 30; DERIVED tier; live-read exception to ADR-0010 for rolling windows). **Posting frequency explicitly remains undecided and hidden.**
- **ADR-0025 — Per-tenant monitoring settings & retention policy** (table + resolver + page; canonical defaults: gift window 60, story keep 180, message history keep-forever; per-tenant prune loops). Closes the flagged shipment-window gap and the two retention ADR-candidates from the P4 review.
- **ADR-0026 — Operational confirmations:** confidence cut-points 0.85/0.60 canonical; PAID kept as inert compatibility value (never asserted without a platform paid-partnership label — resolves roadmap post-P1 TODO #1); sentiment deliberately remains Unavailable (no model chosen; revisit as its own future ADR).

Reconcile the comments/docs these ADRs resolve (only lines these decisions actually decide): `config/qds.php` (attribution block, enrichment sweep block, gdpr block, media-retention comment), `routes/console.php` enrichment comment, `ConfidenceScore.php` "until an ADR fixes them" → cite ADR-0026, `DerivedMetricsService` docblocks, roadmap post-P1 TODO #1 (PAID — resolved) and TODO #3 (stale-run reaper — already built; mark delivered), glossary `ENUM-MentionType` PAID note ("kept for compatibility; QDS never asserts it without a platform label").

## 8. Testing & delivery

- **Tests:** settings page (nav entry, route + permissions, admin-only save, validation messages, round-trip hydration, append-only history row, cross-tenant isolation of reads/writes); resolver (context mode, `forTenant`, no-context → config default — never another tenant's row); per-tenant pruning (two tenants, different values, 0 = skip); `MentionClassifier` per-tenant window (two tenants, different windows, same shipment timing); trend formula (happy path %, one-sided observed metrics, exclusion of no-metric items, empty/zero previous → null, window boundary); CreatorDetail tile (renders % + DERIVED badge; unavailable case); per-pull dispatch (created → dispatched; updated/refresh → NOT dispatched; flag off → nothing; old backfilled content outside window → nothing; story enriched only after archive).
- Full suite green (`XDEBUG_MODE=off ./vendor/bin/phpunit`), PHPStan level 6, Pint.
- After implementation: write the deep-review handoff `reviews/REVIEW-monitoring-settings-<date>.md` (PENDING_REVIEW) per the repo convention — implementation must not self-review.
- Out of scope: sentiment model choice, posting-frequency formula, trend in exports/lists/analytics schema, changing sweep cron, Google live verification, per-tenant `monitoring_plan_settings` scheduler fix (separate ADR-0019 debt).
