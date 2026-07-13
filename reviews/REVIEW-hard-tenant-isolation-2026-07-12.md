# REVIEW TODO — Hard Tenant Isolation (ADR-0020, SaaS pivot Prompt 2)

Date: 2026-07-12. Scope: the uncommitted diff implementing hard cross-tenant isolation on top of the ADR-0019 foundation — a fail-closed authorization backstop, tenant-scoped analytics reads, tenant-aware validation, media/export/file access hardening, per-tenant vs global alert separation, non-forgeable audit stamps, and an adversarial cross-tenant test suite.

Verification state at handoff: full suite **778 passing** (`XDEBUG_MODE=off vendor/bin/phpunit`), PHPStan at the pre-existing 12-error `DemoDataSeeder` baseline (no new errors), Pint clean. A 13-surface pre-implementation audit and a 5-lens adversarial find→verify workflow were run; the one confirmed regression the verify pass surfaced (ambient-context alert tenant-stamping) was fixed in-session and locked with a test.

## Invariant

> A user of Tenant A must never read, query, infer, modify, delete, export, download, attach, aggregate, or cache Tenant B data — including by forging ids, tenant_id fields, filters, job ids, signed-URL params, or filenames. The modelled attacker is a fully-privileged ADMIN of their own tenant.

## What was built (file map)

**Authorization backstop** (the central mechanism):
- `app/Shared/Authorization/TenantIsolationGate.php` — `Gate::before` hook: denies any ability whose `$arguments` contain a `BelongsToTenant` model with `tenantId != actor.tenantId`. Only denies/defers, never grants. Signature `__invoke($user, $ability, array $arguments)` (Gate passes args as ONE array — this bit an earlier revision).
- `app/Providers/AuthServiceProvider.php` — registers it; added to `bootstrap/providers.php`.

**Analytics scoping**:
- `app/Platform/Analytics/RollupReader.php` — constructor-injected `TenantContext`; every rollup/dim query filtered via `->tenant()` helper (guarded).
- `app/Platform/Export/ReportFilters.php`, `app/Modules/CRM/Livewire/Results/SeedingResultsDashboard.php` — raw `dim_geo` reads scoped.
- `app/Platform/Enrichment/Emv/EmvCalculator.php` — `producingConfigurations()` `emv_results` sub-query scoped.

**Validation**:
- `app/Shared/Tenancy/TenantRule.php` — `exists()` scoped to active tenant; applied across 9 Livewire components (Tasks, Brands, Products, Campaigns×2, Seeding×3, Hashtags — 15 call sites).

**Media/export/files**:
- `app/Modules/Monitoring/routes.php` — story-media **stream** route now `['web','auth','signed']`.
- `app/Modules/Monitoring/Http/Controllers/StoryMediaController.php` — `stream()` re-authorizes via `Gate::authorize('view',$story)`.

**Alerts / operations**:
- `app/Platform/Ingestion/Observability/AlertService.php` — `raise()/resolve()` take an **explicit** `?int $tenantId = null` (never ambient); tenant in fingerprint.
- `app/Platform/Ingestion/Jobs/RefreshDataQualityJob.php` — fans out per tenant under `runAs`, passes its tenant; provider callers unchanged → stay global.
- `app/Platform/Ingestion/Models/IngestionAlert.php` + migration `2026_07_12_100000_add_tenant_to_ingestion_alerts.php` — nullable `tenant_id`.
- `app/Modules/Monitoring/Livewire/Operations/OperationsDashboard.php` — freshness via scoped Eloquent; alerts = own OR global(null).

**Audit / console**:
- `app/Shared/Audit/{AuditLog,AuditLogger}.php`, `app/Platform/Export/Jobs/GenerateExportJob.php` — `tenantId`/`userId` out of `$fillable`, force-filled.
- `app/Modules/CRM/Console/SendTaskRemindersCommand.php` — per-task `runAs` so the audit row carries the task's tenant.
- `app/Modules/Discovery/Services/CreatorGeographyWriter.php` — `assertSameTenant()` self-guard.

**Tests**: `tests/Feature/Tenancy/{CrossTenantAuthorizationTest, CrossTenantHttpTest, CrossTenantAnalyticsTest, CrossTenantForgeryTest, CrossTenantAlertTest, TenantIsolationArchitectureTest}.php`.

**Docs**: ADR-0020 (decision-log) + ledger row + header count.

## Review checklist (for the independent deep-review pass)

### Authorization backstop ⚠
- [ ] `Gate::before` argument-unpacking is correct for THIS Laravel version (args arrive as one array — verify against `Gate::callBeforeCallbacks`).
- [ ] The backstop cannot be bypassed: abilities where the model isn't `$arguments[0]`, policy calls that don't route through before-callbacks, models that are policy-registered but DON'T use `BelongsToTenant` (so `class_uses_recursive` misses them) — cross-check every `Gate::policy` registration vs trait usage.
- [ ] It never DENIES legitimate same-tenant access (unsaved model with tenant set; a User acting on itself; class-argument abilities).
- [ ] Owner-only export download still composes (backstop + `ExportJobPolicy::view` owner check).

### Analytics ⚠
- [ ] EVERY `RollupReader` method carries the tenant predicate (no method missed); `latestCreatorMetricSubquery` embedded via `selectSub` on the (already tenant-scoped) creators query composes correctly and doesn't mis-sort or cross tenants.
- [ ] No other raw `DB::table('rollup_%'|'dim_%'|'fact_%'|'analytics_%')` reachable from an authenticated request is unscoped (plan page / `IngestionCostEstimator`, `HomeOverview`, `MonitoringOverview`, `CampaignResultsPanel`, `SeedingResultsPanel`).
- [ ] Rollup matviews are genuinely tenant-grouped (so the predicate is meaningful).

### Validation & forgery
- [ ] No remaining `Rule::exists`/`Rule::unique`/raw existence check on a tenant-owned table is an unscoped oracle or a cross-tenant write enabler.
- [ ] No `BelongsToTenant` model has `tenantId` in `$fillable` or `$guarded=[]`; no `->fill($clientArray)`/`update($clientArray)` can carry a client `tenant_id`.
- [ ] Every `attach/sync/associate` with client ids is protected by a composite `(fk,tenant_id)` FK OR a scoped lookup — name any link/pivot table without one.

### Alerts (regression-prone) ⚠
- [ ] Confirm NO `AlertService::raise/resolve` caller other than the data-quality job passes a tenant (provider/infra alerts must stay global even when raised under a bound context). Re-audit `ProviderCallRecorder`, `RefreshIngestionStatusJob`, `IngestionJobBehaviour`, `RecognitionService`.
- [ ] Per-tenant fan-out still DETECTS anomalies correctly and isolates one tenant's failure; context restored after the loop.

### Files / media / exports
- [ ] Legitimate same-tenant story media STILL streams with `auth` added (the media element request carries the session cookie — verify the consuming blade/JS).
- [ ] Foreign export/document/story id 404s at binding AND policy denies; no guessable tenant file path is downloadable.
- [ ] `GenerateExportJob` builds under the restored tenant context; a missing payload tenantId fails closed, not open.

### Migration
- [ ] `2026_07_12_100000` up/down round-trips; FK/index names < 63 bytes; nullable `tenant_id` needs no backfill (pre-existing rows are correctly global).

### Architecture test
- [ ] `TenantIsolationArchitectureTest` route check excludes only legitimately-non-model bindings (`storage/{path}` etc.) and would actually catch a new auth-less model route.
