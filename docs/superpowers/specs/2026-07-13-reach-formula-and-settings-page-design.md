# Reach formula + Settings section — design spec

- **Date:** 2026-07-13
- **Status:** Approved (design), pending implementation
- **Related canon:** DP-001 (metric tiering), DEF-003 (confirmed unique reach deferred), MET-EstimatedReach, REQ-M1-006 (ESTIMATED reach), REQ-M1-011 (EMV config), REQ-M3-009 (campaign/seeding results), ADR-0006 (tiering/deferral), ADR-0010 (read-only-from-rollups), ADR-0019/0020 (multi-tenancy). **New: ADR-0022 (reach estimation method).**

## 1. Problem & motivation

Two user-facing gaps:

1. **No Settings home.** The EMV configuration page exists at `/monitoring/emv` but has **no sidebar link** — it is reachable only by typing the URL ([sidebar.blade.php](../../../resources/views/layouts/sidebar.blade.php) has no EMV entry). There is no "Settings" section at all.
2. **Estimated reach is always "unavailable."** Reach is fully scaffolded but **dormant**: `ReachEstimator` is bound to a stub that returns `null` ([UnavailableReachEstimator.php:19](../../../app/Platform/Enrichment/Reach/UnavailableReachEstimator.php), bound at [PlatformServiceProvider.php:70](../../../app/Platform/PlatformServiceProvider.php)); the analytics fact writer hardcodes `estimated_reach = NULL` ([NeonAnalyticsService.php:392,569](../../../app/Platform/Analytics/NeonAnalyticsService.php)); rollups therefore `sum(NULL) = NULL` and every reach tile renders "unavailable." No production code ever calls `ReachEstimator::estimate()`.

The `True unique reach` / `Confirmed unique reach` tiles are static "unavailable" placeholders (DEF-003) that the user does not want shown.

## 2. Decisions (approved)

- **D1 — Per-tenant reach formula (one agency-wide formula).** Reach is computed once per post during enrichment against the tenant's single ACTIVE reach configuration, stored, and summed into the shared rollups — identical to how EMV works. Every user and every export sees the **same** reproducible number. (Rejected: per-user/display-time reach — it cannot be the shared/exported number and fights ADR-0010.)
- **D2 — New "Settings" sidebar section** housing **EMV** (moved from Monitoring) and **Reach** (new). Gated `settings.view` (all staff); mutations gated `reach.manage` / `emv.manage` (ADMIN only).
- **D3 — Remove all three unique-reach tiles** (2 CRM `True unique reach` + 1 Monitoring `Confirmed unique reach`). Confirmed/true unique reach stays DEF-003-deferred but is no longer surfaced.
- **D4 — Add ADR-0022** documenting the reach estimation method, its inputs, the disclosed method string, the ESTIMATED-tier discipline, and the DEF-003 boundary. This closes the known "reach model undecided" gap; the DI binding flips only after ADR-0022 is written.

## 3. Reach formula & configuration model

### 3.1 `reach_configurations` table (clone of `emv_configurations`)

Tenant-owned, versioned, append-only — mirrors [emv_configurations](../../../database/migrations/2026_07_05_100002_create_emv_tables.php) and [EmvConfiguration.php](../../../app/Modules/Monitoring/Models/EmvConfiguration.php):

- Columns: `id`, `tenant_id` NOT NULL (`BelongsToTenant`, auto-stamped/scoped), `name`, `method` (disclosed model label → `ReachEstimate.method`), `formula_version`, `params` jsonb (the tunable card), `status` string(10) DEFAULT `DRAFT` + CHECK IN (`DRAFT`,`ACTIVE`,`INACTIVE`,`ARCHIVED`), `effective_from`, `notes`, `created_by`/`activated_by`/`activated_at` (FK users, nullOnDelete).
- Partial UNIQUE `(tenant_id) WHERE status='ACTIVE'` (one ACTIVE per tenant) and UNIQUE `(tenant_id, formula_version)` — re-keyed per tenant exactly as EMV was in [add_tenant_ownership_to_business_tables.php:229-237](../../../database/migrations/2026_07_11_100002_add_tenant_ownership_to_business_tables.php).
- Register in `TENANT_TABLES` + `COMPOSITE_PARENTS` (gets `UNIQUE(id, tenant_id)`); `created_by`/`activated_by` become composite `(col, tenant_id) → users(id, tenant_id)`.
- Model: `ReachConfiguration` (Monitoring), status enum `ReachConfigurationStatus` (clone of `EmvConfigurationStatus`), jsonb array casts. Lifecycle service + policy clone `EmvConfigurationService` / `EmvConfigurationPolicy`.

### 3.2 The v1 default formula

Per ContentItem, using the two inputs the canon mandates — PUBLIC views/plays **and** a follower signal (author `PlatformAccount.follower_count`):

```
estimated_reach = round( α · views  +  β · follower_count )
```

- Defaults (per-platform overridable in `params`): `α = 0.7` (view→unique dedup), `β = 0.1` (follower baseline so no-/low-view posts still model reach from audience).
- **Validator** (in the config service, pinned by ADR-0022): reject `α ≥ 1 ∧ β = 0` (that degenerates to a raw view count — banned by GL-PublicViews), require `β > 0` (follower signal must contribute), require all coefficients ≥ 0.
- Output is a `ReachEstimate(amount, MetricTier::Estimated, method = "<name> v<formula_version>")` — the envelope enforces ESTIMATED tier + non-empty method ([ReachEstimate.php:25-34](../../../app/Shared/ValueObjects/ReachEstimate.php)).
- Coefficients are **tunable in the Reach settings UI**; α/β defaults are a starting point, not a hard requirement.

## 4. Enrichment: compute & store reach (mirror the EMV path)

- `DefaultReachEstimator implements ReachEstimator` — loads the tenant's ACTIVE `ReachConfiguration`, reads the post's PUBLIC views + author follower count, returns a `ReachEstimate`; returns `null` (honest "unavailable") if no ACTIVE config exists.
- `ReachCalculator` (clone of [EmvCalculator](../../../app/Platform/Enrichment/Emv/EmvCalculator.php)) writes append-only **`reach_results`** rows (clone of `emv_results`): `content_item_id`, `reach_configuration_id`, `formula_version`, `value` jsonb (the ReachEstimate envelope), `inputs` jsonb (views/followers used — auditability), `calculated_at`.
- Add a **`reach` stage** to [EnrichmentPipeline](../../../app/Platform/Enrichment/EnrichmentPipeline.php) next to the `emv` stage.
- Flip the DI binding [PlatformServiceProvider.php:70](../../../app/Platform/PlatformServiceProvider.php) → `DefaultReachEstimator` (after ADR-0022).
- [NeonAnalyticsService.php:392,569](../../../app/Platform/Analytics/NeonAnalyticsService.php): replace `NULL, NULL` for the reach columns with a `LATERAL` join to `reach_results` (latest by `calculated_at`), structurally identical to the EMV LATERAL join already at ~:415-423.

**Everything downstream is already wired** — rollups already `sum(estimated_reach)` with an `ESTIMATED` tier, `RollupReader` already reads it, and 5 of 6 display tiles + all 4 export columns already bind it. They light up with no further edits once facts stop being NULL. **One exception:** the Monitoring Content Detail "Estimated reach" tile is hardcoded "unavailable" ([content-detail.blade.php:97-102](../../../resources/views/livewire/monitoring/content-detail.blade.php)) and needs bespoke wiring to `reach_results`.

## 5. Settings section + pages (clone EMV wiring across 5 layers)

- **Sidebar:** add a `$settingsItems` array + a `@can('settings.view')` section block (mirroring the Account block) between Account and Admin, with EMV (`settings.emv`) and Reach (`settings.reach`) items.
- **Routes:** new `/settings` group in [routes/web.php](../../../routes/web.php) with `['auth','can:settings.view','subscribed']` → `Route::view('/emv','settings.emv')->name('settings.emv')` and `Route::view('/reach','settings.reach')->name('settings.reach')`. **Remove** the old `/emv` route from [Monitoring/routes.php:28](../../../app/Modules/Monitoring/routes.php).
- **Views:** create `resources/views/settings/emv.blade.php` (copy of `monitoring/emv.blade.php`, breadcrumbs Dashboard › Settings › EMV, keep `@livewire('monitoring.emv-configurations-index')` unchanged) and `resources/views/settings/reach.blade.php` (hosts new `@livewire('monitoring.reach-formula-index')`). Delete/redirect the old `monitoring/emv.blade.php`.
- **Livewire:** new `ReachFormulaIndex` component + blade (clone of [EmvConfigurationsIndex](../../../app/Modules/Monitoring/Livewire/Emv/EmvConfigurationsIndex.php)) — create DRAFT / activate / deactivate / archive reach configs and edit params; register in `MonitoringServiceProvider::boot()`.
- **Policy:** `ReachConfigurationPolicy` (`viewAny`/`view` → `settings.view`; `create`/`update` → `reach.manage`), `Gate::policy(...)` in `MonitoringServiceProvider`. Switch `EmvConfigurationPolicy::viewAny/view` from `monitoring.view` → `settings.view` for consistency.
- **Permissions:** [PermissionsCatalog.php](../../../app/Shared/Authorization/PermissionsCatalog.php) — add `SETTINGS_VIEW='settings.view'` (to `$staff`) and `REACH_MANAGE='reach.manage'` (ADMIN-only, like `EMV_MANAGE`), add both to `all()`. Re-run `RolePermissionSeeder` (idempotent). `RoleName` unchanged.
- **Empty state:** the Reach page renders an explicit "unavailable until configured" empty state when no ACTIVE config exists (same posture as EMV — never a fabricated default).

## 6. Remove unique-reach tiles + export disclosure

- Delete the tile `<div>` blocks: [campaign-results.blade.php:68-73](../../../resources/views/livewire/crm/campaign-results.blade.php), [seeding-results.blade.php:61-66](../../../resources/views/livewire/crm/seeding-results.blade.php), [content-detail.blade.php:103-108](../../../resources/views/livewire/monitoring/content-detail.blade.php).
- Reword the two export disclosure lines [ReportBuilder.php:125,292](../../../app/Platform/Export/ReportBuilder.php): drop "estimated reach unavailable"; keep a DEF-003 clause only for the still-deferred CONFIRMED unique reach.

## 7. Testing

- **Update DEF-003 assertions** that vanish once reach is real / tiles removed: `CampaignResultsPanelTest:207-214`, `SeedingResultsPanelTest:196-203`, `SeedingResultsDashboardTest:153`, `DashboardScreensTest:70,214`. Update the `/monitoring/emv` → `/settings/emv` path in `DashboardScreensTest:305-317` and add `/settings/reach`.
- **New tests:** reach config lifecycle (DRAFT→ACTIVE, one-ACTIVE-per-tenant, validator rejects degenerate coefficients); `DefaultReachEstimator` emits ESTIMATED tier + non-empty method; reach flows fact→rollup→display as a real number; tenant isolation of reach configs; Settings pages gated by `settings.view`, reach mutations gated by `reach.manage`; EMV still reachable at its new route.
- Run with `XDEBUG_MODE=off`.

## 8. Rollout / ops

1. Migrate (`reach_configurations`, `reach_results`, tenant-ownership registration).
2. Seed a default ACTIVE `ReachConfiguration` per tenant (add to `DemoDataSeeder` + a tenant-provision hook).
3. Re-run enrichment to backfill `reach_results` for existing content, then `qds:refresh-rollups`.
4. Estimated reach then shows on dashboards/exports (per campaign, once content is attributed).

## 9. Out of scope (YAGNI)

- No per-user formula pointer / no `users` column / no display-time projection / no rollup schema change.
- No CONFIRMED unique reach (stays DEF-003).
- No new roles.

## 10. Open item

- Confirm default coefficients α=0.7 / β=0.1 (and any per-platform overrides) — pinned in ADR-0022.
