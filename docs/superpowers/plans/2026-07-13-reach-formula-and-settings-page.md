# Reach formula + Settings section — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a Settings sidebar section housing EMV (moved) + a new per-tenant Reach formula manager, make "Estimated reach" a real ESTIMATED number computed in enrichment, and remove the deferred "unique reach" tiles.

**Architecture:** Reach mirrors EMV 1:1 — a tenant-owned versioned `reach_configurations` table (DRAFT→ACTIVE, one ACTIVE/tenant), computed once per ContentItem in the enrichment pipeline, stored append-only in `reach_results`, and summed into the existing shared rollups. All existing reach display/export sites already read `estimated_reach` and light up automatically.

**Tech Stack:** Laravel 12, Livewire 4, PostgreSQL (jsonb + materialized-view rollups), spatie/permission, Pest/PHPUnit.

## Global Constraints

- Every reach figure is tier **ESTIMATED**, carries a non-empty **method** string, and is transported as a `ReachEstimate` envelope — never a bare number (DP-001, [ReachEstimate.php:25-34](../../../app/Shared/ValueObjects/ReachEstimate.php)).
- Reach is **never** a raw view count; the follower signal must contribute (GL-PublicViews / DEF-003). The formula validator enforces this.
- CONFIRMED / true-unique reach stays deferred (DEF-003) — never build it, never show it.
- All new tenant-owned tables use `BelongsToTenant` and register in the composite-FK ownership machinery (ADR-0019/0020). Never guess `tenant_id`.
- Reads come only from rollups (`RollupReader`), never live aggregation (ADR-0010).
- New DI binding to a real `ReachEstimator` happens **only after ADR-0022 is written** (Task B1).
- Run tests with `XDEBUG_MODE=off`.
- Nothing is committed unless the user asks; if committing, branch off `main` first.

## File Structure

**Create:**
- `database/migrations/2026_07_13_100001_create_reach_tables.php` — `reach_configurations` + `reach_results`
- `database/migrations/2026_07_13_100002_add_reach_tenant_ownership.php` — composite FK / `UNIQUE(id,tenant_id)` + per-tenant partial-unique ACTIVE
- `app/Modules/Monitoring/Models/ReachConfiguration.php`, `.../ReachResult.php`
- `app/Platform/Enrichment/Support/ReachConfigurationStatus.php`
- `app/Platform/Enrichment/Reach/ReachConfigurationValidator.php`, `.../ReachConfigurationService.php`, `.../DefaultReachEstimator.php`, `.../ReachCalculator.php`
- `app/Modules/Monitoring/Livewire/Reach/ReachFormulaIndex.php`
- `app/Modules/Monitoring/Policies/ReachConfigurationPolicy.php`
- `resources/views/settings/emv.blade.php`, `resources/views/settings/reach.blade.php`
- `resources/views/livewire/monitoring/reach-formula-index.blade.php`
- `database/factories/ReachConfigurationFactory.php`
- `docs/05-decisions/adr-0022-reach-estimation-method.md` (+ decision-log entry)
- Tests under `tests/Feature/Settings/`, `tests/Feature/Enrichment/`, `tests/Feature/Reach/`

**Modify:**
- `app/Shared/Authorization/PermissionsCatalog.php` (2 consts + `all()` + `$staff`)
- `routes/web.php` (settings group), `app/Modules/Monitoring/routes.php` (remove `/emv`)
- `resources/views/layouts/sidebar.blade.php` (Settings section)
- `app/Modules/Monitoring/MonitoringServiceProvider.php` (register Livewire + Gate policy)
- `app/Modules/Monitoring/Policies/EmvConfigurationPolicy.php` (viewAny/view → settings.view)
- `app/Platform/Enrichment/EnrichmentPipeline.php` (reach stage), `app/Platform/PlatformServiceProvider.php` (rebind)
- `app/Platform/Analytics/NeonAnalyticsService.php` (reach LATERAL join at :392, :569)
- `resources/views/livewire/crm/campaign-results.blade.php`, `.../seeding-results.blade.php`, `resources/views/livewire/monitoring/content-detail.blade.php` (remove unique-reach tiles; wire content-detail estimated-reach)
- `app/Platform/Export/ReportBuilder.php` (:125, :292 disclosure reword)
- `database/seeders/DemoDataSeeder.php` (seed default ACTIVE reach config)
- Tests asserting `DEF-003` (see Task C3)

---

# PHASE A — Settings area with EMV + Reach management (ships the visible feature)

### Task A1: Reach permissions

**Files:** Modify `app/Shared/Authorization/PermissionsCatalog.php`; Test `tests/Feature/Settings/ReachPermissionsTest.php`

**Interfaces:** Produces `PermissionsCatalog::SETTINGS_VIEW = 'settings.view'`, `PermissionsCatalog::REACH_MANAGE = 'reach.manage'`.

- [ ] **Step 1 — failing test**
```php
<?php // tests/Feature/Settings/ReachPermissionsTest.php
use App\Shared\Authorization\PermissionsCatalog;
use App\Shared\Enums\RoleName;

it('grants settings.view to all staff and reach.manage to admin only', function () {
    $assignments = PermissionsCatalog::roleAssignments();
    expect($assignments[RoleName::Analyst->value])->toContain(PermissionsCatalog::SETTINGS_VIEW);
    expect($assignments[RoleName::Analyst->value])->not->toContain(PermissionsCatalog::REACH_MANAGE);
    expect($assignments[RoleName::Admin->value])->toContain(PermissionsCatalog::REACH_MANAGE);
    expect($assignments[RoleName::ClientViewer->value])->not->toContain(PermissionsCatalog::SETTINGS_VIEW);
    expect(PermissionsCatalog::all())->toContain(PermissionsCatalog::SETTINGS_VIEW, PermissionsCatalog::REACH_MANAGE);
});
```
- [ ] **Step 2 — run, expect FAIL** `XDEBUG_MODE=off ./vendor/bin/pest tests/Feature/Settings/ReachPermissionsTest.php`
- [ ] **Step 3 — implement:** in `PermissionsCatalog`, add after `EMV_MANAGE`:
```php
    public const SETTINGS_VIEW = 'settings.view';

    public const REACH_MANAGE = 'reach.manage';
```
Add both to the `all()` array (after `EMV_MANAGE`) and add `self::SETTINGS_VIEW` to the `$staff` array in `roleAssignments()`. Do **not** add `REACH_MANAGE` to `$staff` (ADMIN gets it via `self::all()`).
- [ ] **Step 4 — run, expect PASS**
- [ ] **Step 5 — reseed roles** `php artisan db:seed --class="Database\Seeders\RolePermissionSeeder"`

### Task A2: `reach_configurations` table + tenant ownership

**Files:** Create both migrations; Test `tests/Feature/Reach/ReachConfigurationSchemaTest.php`

**Interfaces:** Produces table `reach_configurations` with columns `id, tenant_id, name, method, formula_version, params(jsonb), status, effective_from, notes, created_by, activated_at, activated_by, timestamps`; one-ACTIVE-per-tenant partial unique.

- [ ] **Step 1 — failing test**
```php
<?php // tests/Feature/Reach/ReachConfigurationSchemaTest.php
use Illuminate\Support\Facades\Schema;
it('provisions reach_configurations with the expected columns', function () {
    expect(Schema::hasTable('reach_configurations'))->toBeTrue();
    expect(Schema::hasColumns('reach_configurations',
        ['tenant_id','name','method','formula_version','params','status','effective_from','created_by','activated_by']))->toBeTrue();
});
```
- [ ] **Step 2 — run, expect FAIL**
- [ ] **Step 3 — implement `create_reach_tables.php`** — clone [create_emv_tables.php](../../../database/migrations/2026_07_05_100002_create_emv_tables.php) with these substitutions: table `reach_configurations`; drop `rate_card_version`/`currency`/`formula`/`rates`; add `$table->string('method');`, `$table->string('formula_version',64);`, `$table->jsonb('params');`; keep `status`/`effective_from`/`notes`/`assumptions`/`created_by`/`activated_at`/`activated_by`/timestamps; `$table->unique(['formula_version'])`; CHECK constraint `reach_configurations_status_check IN ('DRAFT','ACTIVE','INACTIVE','ARCHIVED')`; the `(1) WHERE status='ACTIVE'` global unique index (retenanted in A2b). Also create `reach_results` (Task B2 will use it) — **defer to B2**; this migration creates only `reach_configurations`.
- [ ] **Step 3b — implement `add_reach_tenant_ownership.php`** following [add_tenant_ownership_to_business_tables.php:132-237](../../../database/migrations/2026_07_11_100002_add_tenant_ownership_to_business_tables.php): add `tenant_id` FK, `UNIQUE(id, tenant_id)`, composite FKs for `created_by`/`activated_by` → `users(id, tenant_id)`, drop the global one-active index and recreate it `ON reach_configurations (tenant_id) WHERE status='ACTIVE'`, and re-key `unique(formula_version)` → `unique(tenant_id, formula_version)`.
- [ ] **Step 4 — run migrations + test** `php artisan migrate` then rerun the test, expect PASS.

### Task A3: `ReachConfiguration` model + status enum + factory

**Files:** Create `ReachConfigurationStatus.php`, `ReachConfiguration.php`, `ReachConfigurationFactory.php`; Test `tests/Feature/Reach/ReachConfigurationModelTest.php`

**Interfaces:** Produces `ReachConfiguration` (BelongsToTenant, casts `params`→array, `status`→`ReachConfigurationStatus`, `effective_from`→immutable_date), `->isActive()`, `author()`, `activator()`.

- [ ] **Step 1 — failing test**
```php
<?php // tests/Feature/Reach/ReachConfigurationModelTest.php
use App\Modules\Monitoring\Models\ReachConfiguration;
use App\Platform\Enrichment\Support\ReachConfigurationStatus;
it('casts params and status', function () {
    $c = ReachConfiguration::factory()->create(['params' => ['view_weight' => 0.7, 'follower_weight' => 0.1]]);
    expect($c->params['view_weight'])->toBe(0.7);
    expect($c->status)->toBeInstanceOf(ReachConfigurationStatus::class);
});
```
- [ ] **Step 2 — run, expect FAIL**
- [ ] **Step 3 — implement:**
  - `ReachConfigurationStatus` — clone [EmvConfigurationStatus.php](../../../app/Platform/Enrichment/Support/EmvConfigurationStatus.php) verbatim (same 4 cases), new class name.
  - `ReachConfiguration` — clone [EmvConfiguration.php](../../../app/Modules/Monitoring/Models/EmvConfiguration.php): `use BelongsToTenant; use HasFactory;` fillable `['name','method','formula_version','params','effective_from','status','notes','assumptions','created_by','activated_at','activated_by']`; casts `params`→`array`, `assumptions`→`array`, `effective_from`→`immutable_date`, `status`→`ReachConfigurationStatus::class`, `activated_at`→`immutable_datetime`; `author()`/`activator()` BelongsTo User on `created_by`/`activated_by`; `isActive()`. Drop the `results()` relation for now (ReachResult has no config FK requirement in reads); add it in B3.
  - `ReachConfigurationFactory` — states: default `status=DRAFT`, `method='qds-estimated-reach'`, `formula_version='reach-2026.1'`, `params=['view_weight'=>0.7,'follower_weight'=>0.1]`, `effective_from=now()`; `->active()` state.
- [ ] **Step 4 — run, expect PASS**

### Task A4: Reach formula validator

**Files:** Create `app/Platform/Enrichment/Reach/ReachConfigurationValidator.php`; Test `tests/Feature/Reach/ReachConfigurationValidatorTest.php`

**Interfaces:** Produces `ReachConfigurationValidator::validate(array $attributes): array` (list of error strings, empty = valid). Params shape: `{view_weight: float>=0, follower_weight: float>0, platforms?: {<platform>: {view_weight?, follower_weight?}}}`.

- [ ] **Step 1 — failing test**
```php
<?php // tests/Feature/Reach/ReachConfigurationValidatorTest.php
use App\Platform\Enrichment\Reach\ReachConfigurationValidator;
beforeEach(fn () => $this->v = new ReachConfigurationValidator());
$valid = ['name'=>'R','method'=>'m','formula_version'=>'v1','effective_from'=>now(),
          'params'=>['view_weight'=>0.7,'follower_weight'=>0.1]];
it('accepts a valid config', fn () => expect($this->v->validate($valid))->toBe([]));
it('rejects a raw-view-count passthrough', function () use ($valid) {
    expect($this->v->validate([...$valid,'params'=>['view_weight'=>1.0,'follower_weight'=>0.0]]))->not->toBe([]);
});
it('requires follower_weight > 0', function () use ($valid) {
    expect($this->v->validate([...$valid,'params'=>['view_weight'=>0.7,'follower_weight'=>0.0]]))->not->toBe([]);
});
it('rejects negative weights', function () use ($valid) {
    expect($this->v->validate([...$valid,'params'=>['view_weight'=>-0.1,'follower_weight'=>0.1]]))->not->toBe([]);
});
```
- [ ] **Step 2 — run, expect FAIL**
- [ ] **Step 3 — implement** (model on the EMV validator's structure). Rules: `name`/`method`/`formula_version` required non-empty; `effective_from` required; `params.view_weight` numeric ≥ 0; `params.follower_weight` numeric **> 0** (follower signal must contribute — GL-PublicViews); reject `view_weight >= 1 && follower_weight == 0` with message "reach must not be a raw view count"; optional `params.platforms` keyed by `Platform::cases()` values, each a `{view_weight?, follower_weight?}` object of non-negative numbers; unknown keys rejected.
- [ ] **Step 4 — run, expect PASS**

### Task A5: `ReachConfigurationService` lifecycle

**Files:** Create `app/Platform/Enrichment/Reach/ReachConfigurationService.php`; Test `tests/Feature/Reach/ReachConfigurationServiceTest.php`

**Interfaces:** Produces `create(array,$user)`, `update(config,array,$user)`, `activate(config,$user)`, `deactivate(config,$user)`, `archive(config,$user)` — same semantics as [EmvConfigurationService](../../../app/Platform/Enrichment/Emv/EmvConfigurationService.php) (only-DRAFT-editable, atomic one-active swap, audit-logged, `Gate::authorize` on `reach.manage`).

- [ ] **Step 1 — failing test**
```php
<?php // tests/Feature/Reach/ReachConfigurationServiceTest.php
use App\Modules\Monitoring\Models\ReachConfiguration;
use App\Platform\Enrichment\Reach\ReachConfigurationService;
use App\Platform\Enrichment\Support\ReachConfigurationStatus;
// helper: seed a tenant + admin user in TenantContext (see tests/Feature/Reach helpers or Crm tests for the pattern)
it('activates only one config per tenant', function () {
    $admin = /* admin user in tenant context */ ;
    $svc = app(ReachConfigurationService::class);
    $a = $svc->create(['name'=>'A','method'=>'m','formula_version'=>'v1','effective_from'=>now(),'params'=>['view_weight'=>0.7,'follower_weight'=>0.1]], $admin);
    $b = $svc->create(['name'=>'B','method'=>'m','formula_version'=>'v2','effective_from'=>now(),'params'=>['view_weight'=>0.6,'follower_weight'=>0.2]], $admin);
    $svc->activate($a, $admin); $svc->activate($b, $admin);
    expect($a->refresh()->status)->toBe(ReachConfigurationStatus::Inactive);
    expect($b->refresh()->status)->toBe(ReachConfigurationStatus::Active);
});
```
> Reuse the tenant/admin setup helper used by existing EMV tests (`tests/Feature/.../EmvTest.php`); follow that exact pattern.
- [ ] **Step 2 — run, expect FAIL**
- [ ] **Step 3 — implement** by cloning `EmvConfigurationService`: inject `ReachConfigurationValidator` + `AuditLogger`; audit keys `reach.configuration.created|updated|activated|deactivated|archived`; validate on create/update/activate; `Gate::authorize('create'|'update', ...)`; identical DRAFT-only-editable + atomic-swap logic on `ReachConfiguration`/`ReachConfigurationStatus`.
- [ ] **Step 4 — run, expect PASS**

### Task A6: `ReachFormulaIndex` Livewire + blade + policy + registration

**Files:** Create `app/Modules/Monitoring/Livewire/Reach/ReachFormulaIndex.php`, `resources/views/livewire/monitoring/reach-formula-index.blade.php`, `app/Modules/Monitoring/Policies/ReachConfigurationPolicy.php`; Modify `MonitoringServiceProvider.php`; Test `tests/Feature/Reach/ReachFormulaIndexTest.php`

**Interfaces:** Produces Livewire alias `monitoring.reach-formula-index`; `ReachConfigurationPolicy` (`viewAny/view`→`settings.view`; `create/update`→`reach.manage`; `delete`→false).

- [ ] **Step 1 — failing test**
```php
<?php // tests/Feature/Reach/ReachFormulaIndexTest.php
use App\Modules\Monitoring\Livewire\Reach\ReachFormulaIndex;
use Livewire\Livewire;
it('lets an admin create a draft reach configuration', function () {
    $admin = /* admin in tenant context */ ;
    Livewire::actingAs($admin)->test(ReachFormulaIndex::class)
        ->call('create')
        ->set('name','My reach')->set('method','qds-estimated-reach')->set('formulaVersion','reach-2026.1')
        ->set('viewWeight','0.7')->set('followerWeight','0.1')
        ->call('save')
        ->assertHasNoErrors();
    $this->assertDatabaseHas('reach_configurations', ['name' => 'My reach', 'status' => 'DRAFT']);
});
```
- [ ] **Step 2 — run, expect FAIL**
- [ ] **Step 3 — implement:**
  - `ReachConfigurationPolicy` — clone [EmvConfigurationPolicy](../../../app/Modules/Monitoring/Policies/EmvConfigurationPolicy.php): `viewAny/view` → `PermissionsCatalog::SETTINGS_VIEW`; `create/update` → `PermissionsCatalog::REACH_MANAGE`; `delete` → false.
  - `ReachFormulaIndex` — clone [EmvConfigurationsIndex](../../../app/Modules/Monitoring/Livewire/Emv/EmvConfigurationsIndex.php): public props `name, method, formulaVersion, effectiveFrom, viewWeight='0.7', followerWeight='0.1', platformsJson='', notes, formError`; `mount()` authorizes `viewAny` on `ReachConfiguration`; `create()` resets form; `save(ReachConfigurationService)` builds `params = ['view_weight'=>(float)$viewWeight,'follower_weight'=>(float)$followerWeight] + (platformsJson ? ['platforms'=>json_decode(...)] : [])` and calls `$service->create([...], $this->user())`; `activate/deactivate/archive($id, $service)` mirror EMV; `render()` returns `livewire.monitoring.reach-formula-index` with `configurations => ReachConfiguration::query()->orderByDesc('id')->get()`.
  - blade — clone [emv-configurations-index.blade.php](../../../resources/views/livewire/monitoring/emv-configurations-index.blade.php): swap the rate-card JSON field for two number inputs (View weight α, Follower weight β) + optional platform-overrides JSON; empty state "No reach configuration yet — estimated reach stays unavailable until one is activated (REQ-M1-006)"; `@can('create'|'update', ...ReachConfiguration...)`.
  - `MonitoringServiceProvider::boot()` — add `Gate::policy(ReachConfiguration::class, ReachConfigurationPolicy::class);` and `Livewire::component('monitoring.reach-formula-index', ReachFormulaIndex::class);` (+ imports).
- [ ] **Step 4 — run, expect PASS**

### Task A7: Settings routes + views + move EMV + policy switch

**Files:** Modify `routes/web.php`, `app/Modules/Monitoring/routes.php`, `app/Modules/Monitoring/Policies/EmvConfigurationPolicy.php`; Create `resources/views/settings/emv.blade.php`, `resources/views/settings/reach.blade.php`; delete `resources/views/monitoring/emv.blade.php`; Test `tests/Feature/Settings/SettingsRoutesTest.php`

**Interfaces:** Produces routes `settings.emv` (`/settings/emv`), `settings.reach` (`/settings/reach`).

- [ ] **Step 1 — failing test**
```php
<?php // tests/Feature/Settings/SettingsRoutesTest.php
it('serves settings pages to staff and 403s client viewers', function () {
    $this->actingAs(/* analyst */)->get('/settings/emv')->assertOk();
    $this->actingAs(/* analyst */)->get('/settings/reach')->assertOk();
    $this->actingAs(/* client viewer */)->get('/settings/reach')->assertForbidden();
});
it('no longer serves the old /monitoring/emv route', function () {
    $this->actingAs(/* analyst */)->get('/monitoring/emv')->assertNotFound();
});
```
- [ ] **Step 2 — run, expect FAIL**
- [ ] **Step 3 — implement:**
  - `routes/web.php` — add after the reports group:
```php
Route::middleware(['auth', 'can:'.PermissionsCatalog::SETTINGS_VIEW, 'subscribed'])
    ->prefix('settings')->as('settings.')->group(function () {
        Route::view('/emv', 'settings.emv')->name('emv');
        Route::view('/reach', 'settings.reach')->name('reach');
    });
```
  - `app/Modules/Monitoring/routes.php` — delete the `Route::view('/emv', 'monitoring.emv')->name('emv');` line (and its comment).
  - `resources/views/settings/emv.blade.php` — copy of `monitoring/emv.blade.php`, breadcrumbs `['Dashboard'=>route('dashboard'),'Settings'=>route('settings.emv'),'EMV'=>null]`, keep `@livewire('monitoring.emv-configurations-index')`.
  - `resources/views/settings/reach.blade.php` — same shell, title "Reach formula", breadcrumbs Dashboard › Settings › Reach, `@livewire('monitoring.reach-formula-index')`.
  - Delete `resources/views/monitoring/emv.blade.php`.
  - `EmvConfigurationPolicy` — change `viewAny`/`view` to return `$user->can(PermissionsCatalog::SETTINGS_VIEW)` (leave create/update on `EMV_MANAGE`).
- [ ] **Step 4 — run, expect PASS**

### Task A8: Settings sidebar section

**Files:** Modify `resources/views/layouts/sidebar.blade.php`; Test `tests/Feature/Settings/SettingsNavTest.php`

- [ ] **Step 1 — failing test**
```php
<?php // tests/Feature/Settings/SettingsNavTest.php
it('shows the Settings section to staff with EMV + Reach links', function () {
    $res = $this->actingAs(/* analyst */)->get('/dashboard');
    $res->assertSee('Settings')->assertSee(route('settings.emv'))->assertSee(route('settings.reach'));
});
it('hides Settings from client viewers', function () {
    $this->actingAs(/* client viewer */)->get('/reports')->assertDontSee(route('settings.reach'));
});
```
- [ ] **Step 2 — run, expect FAIL**
- [ ] **Step 3 — implement:** add a `$settingsItems` array in the `@php` block (mirroring `$accountItems`) with EMV (`route=>'settings.emv'`, `can=>'settings.view'`) and Reach (`route=>'settings.reach'`, `can=>'settings.view'`) entries (reuse an appropriate SVG icon). Add a `@can('settings.view')` section `<div>` with heading "Settings" and the `@foreach ($settingsItems ...)` loop (copy the Account block markup), slotted between the Account block (`:178`) and the Admin block (`:180`).
- [ ] **Step 4 — run, expect PASS.** Manually verify: log in as admin → sidebar shows **Settings → EMV / Reach**; both pages load.

---

# PHASE B — Make estimated reach real

### Task B1: ADR-0022 (reach estimation method)

**Files:** Create `docs/05-decisions/adr-0022-reach-estimation-method.md`; Modify `docs/05-decisions/decision-log.md`

- [ ] **Step 1** — write ADR-0022 documenting: status APPROVED; the model `estimated_reach = round(α·views + β·follower_count)` with per-platform overrides; inputs limited to PUBLIC views/plays + follower signals; output tier **ESTIMATED**, method string disclosed; forbids `α≥1 ∧ β=0`; reaffirms DEF-003 (CONFIRMED unique reach still deferred); default coefficients α=0.7, β=0.1 (subject to operator tuning). Add the row to `decision-log.md`.
- [ ] **Step 2** — no test (doc). Commit.

### Task B2: `reach_results` table

**Files:** Create `database/migrations/2026_07_13_100003_create_reach_results_table.php`; Test `tests/Feature/Reach/ReachResultSchemaTest.php`

- [ ] **Step 1 — failing test** — assert `reach_results` has `content_item_id, reach_configuration_id, formula_version, value(jsonb), inputs(jsonb), calculated_at`.
- [ ] **Step 2 — run, expect FAIL**
- [ ] **Step 3 — implement** — clone the `emv_results` block from [create_emv_tables.php:66-83](../../../database/migrations/2026_07_05_100002_create_emv_tables.php): `content_item_id` + `reach_configuration_id` constrained/indexed, `formula_version` string(64), `value` jsonb (the ReachEstimate envelope), `inputs` jsonb, `calculated_at`+`created_at`, index `['content_item_id','calculated_at']`.
- [ ] **Step 4 — migrate + test, expect PASS**

### Task B3: `ReachResult` model (+ config relation)

**Files:** Create `app/Modules/Monitoring/Models/ReachResult.php`; add `results()` HasMany to `ReachConfiguration`; Test extends A3 model test.
- [ ] Clone `EmvResult` model: casts `value`/`inputs`/`assumptions`→array, `calculated_at`→immutable_datetime; `configuration()` BelongsTo `ReachConfiguration`; `contentItem()` BelongsTo. Add `results()` HasMany on `ReachConfiguration`. Test round-trips a `ReachEstimate` through `value`.

### Task B4: `DefaultReachEstimator`

**Files:** Create `app/Platform/Enrichment/Reach/DefaultReachEstimator.php`; Test `tests/Feature/Enrichment/DefaultReachEstimatorTest.php`

**Interfaces:** `implements ReachEstimator`; `estimate(ContentItem): ?ReachEstimate`. Consumes active `ReachConfiguration`; reads `content->metrics` PUBLIC views + author `platformAccount->follower_count`.

- [ ] **Step 1 — failing test**
```php
<?php // tests/Feature/Enrichment/DefaultReachEstimatorTest.php
use App\Platform\Enrichment\Reach\DefaultReachEstimator;
use App\Shared\Enums\MetricTier;
it('returns null when no active config exists', function () {
    $content = /* content item, no active reach config */ ;
    expect(app(DefaultReachEstimator::class)->estimate($content))->toBeNull();
});
it('computes alpha*views + beta*followers as ESTIMATED with a method', function () {
    // active config view_weight=0.7 follower_weight=0.1; content views=1000; author followers=5000
    $r = app(DefaultReachEstimator::class)->estimate($content);
    expect($r->amount)->toBe(1200.0);            // 0.7*1000 + 0.1*5000
    expect($r->tier)->toBe(MetricTier::Estimated);
    expect($r->method)->not->toBe('');
});
```
- [ ] **Step 2 — run, expect FAIL**
- [ ] **Step 3 — implement:** resolve `ReachConfiguration::query()->where('status', ReachConfigurationStatus::Active)->first()`; return `null` if none (honest unavailable). Read PUBLIC `views` (fall back to `plays`) from the content's metrics and the author follower count; apply per-platform override if present. Return `new ReachEstimate(round($alpha*$views + $beta*$followers), MetricTier::Estimated, $config->method.' v'.$config->formula_version)`.
- [ ] **Step 4 — run, expect PASS**

### Task B5: `ReachCalculator` + pipeline stage + rebind

**Files:** Create `app/Platform/Enrichment/Reach/ReachCalculator.php`; Modify `EnrichmentPipeline.php`, `PlatformServiceProvider.php`; Test `tests/Feature/Enrichment/ReachStageTest.php`

**Interfaces:** `ReachCalculator::calculate(ContentItem): ?ReachResult` (writes append-only row; null when estimator returns null). Pipeline `$stages['reach']`.

- [ ] **Step 1 — failing test** — enrich a ContentItem with an active reach config; assert a `reach_results` row exists with an ESTIMATED value and the run's `stages['reach']` starts with `calculated:`.
- [ ] **Step 2 — run, expect FAIL**
- [ ] **Step 3 — implement:** `ReachCalculator` injects `DefaultReachEstimator`; `calculate()` gets the estimate, writes a `ReachResult` (`value`=estimate->toArray(), `inputs`=views/followers used, `formula_version`, `calculated_at`). Add `private readonly ReachCalculator $reach` to `EnrichmentPipeline` ctor and, after the emv stage (`:77-86`), for ContentItems: `$result = $this->reach->calculate($target); $stages['reach'] = $result !== null ? 'calculated:'.$result->formula_version : 'unavailable:no-active-configuration';` (stories: `skipped:content-items-only`). In `PlatformServiceProvider` change the bind `ReachEstimator::class → DefaultReachEstimator::class`.
- [ ] **Step 4 — run, expect PASS**

### Task B6: Analytics reach LATERAL join

**Files:** Modify `app/Platform/Analytics/NeonAnalyticsService.php` (:392 fact_mention, :569 fact_seeding_content); Test `tests/Feature/Analytics/ReachRollupTest.php`

- [ ] **Step 1 — failing test** — with an active config + computed `reach_results`, rebuild facts + refresh rollups, then assert `RollupReader::campaignMentionTotals(...)->total_estimated_reach` is non-null and equals the summed reach.
- [ ] **Step 2 — run, expect FAIL**
- [ ] **Step 3 — implement:** replace the two `NULL, NULL` reach column expressions with a `LATERAL` subquery on `reach_results` (latest by `calculated_at`) mirroring the existing EMV LATERAL join (~:415-423): `estimated_reach = (rr.value->>'amount')::numeric`, `estimated_reach_tier = rr.value->>'tier'`.
- [ ] **Step 4 — run, expect PASS**

### Task B7: Content Detail tile + demo seed

**Files:** Modify `resources/views/livewire/monitoring/content-detail.blade.php:97-102` + its component; `database/seeders/DemoDataSeeder.php`; Test `tests/Feature/Monitoring/ContentDetailReachTest.php`

- [ ] **Step 1 — failing test** — content detail for a post with a reach_result shows the ESTIMATED reach number + method, not "unavailable".
- [ ] **Step 2 — run, expect FAIL**
- [ ] **Step 3 — implement:** in the ContentDetail component, load the latest `ReachResult` for the content item and expose its `ReachEstimate`; in the blade replace the hardcoded "unavailable" block with the estimate (number + `ESTIMATED` badge + method), falling back to `<x-states.unavailable>` when null. In `DemoDataSeeder`, create + activate a default `ReachConfiguration` per seeded tenant (method `qds-estimated-reach`, `reach-2026.1`, α=0.7/β=0.1) before enrichment runs.
- [ ] **Step 4 — run, expect PASS.** Then run: `php artisan db:seed`, re-run enrichment, `php artisan qds:refresh-rollups`; manually verify a campaign with attributed content shows Estimated reach.

---

# PHASE C — Remove unique-reach tiles + fix export/tests

### Task C1: Remove the three unique-reach tiles

**Files:** Modify `resources/views/livewire/crm/campaign-results.blade.php:68-73`, `resources/views/livewire/crm/seeding-results.blade.php:61-66`, `resources/views/livewire/monitoring/content-detail.blade.php:103-108`
- [ ] Delete each `<div>...True unique reach.../Confirmed unique reach...</div>` block. No test writes needed here beyond C3.

### Task C2: Reword export disclosure

**Files:** Modify `app/Platform/Export/ReportBuilder.php:125, :292`; Test `tests/Feature/Export/ExportFlowTest.php`
- [ ] Change the disclosure sentence so it no longer says estimated reach is unavailable; keep a DEF-003 clause **only** for CONFIRMED/true unique reach. Update the export test's asserted disclosure string to the new wording (keep the `Estimated reach [ESTIMATED]` header assertion).

### Task C3: Fix DEF-003 assertions

**Files:** Modify tests: `tests/Feature/Crm/CampaignResultsPanelTest.php:207-214`, `tests/Feature/Crm/SeedingResultsPanelTest.php:196-203`, `tests/Feature/Crm/SeedingResultsDashboardTest.php:153`, `tests/Feature/Monitoring/DashboardScreensTest.php:70,214,305-317`
- [ ] Where a test seeded no reach and asserted `DEF-003` via the estimated-reach-unavailable branch, either seed an active config + reach_result and assert the real number, or assert the new empty-state copy. Remove `DEF-003` assertions tied to deleted unique-reach tiles. Update the `/monitoring/emv`→`/settings/emv` path and add `/settings/reach` to the CLIENT_VIEWER-denied smoke list.
- [ ] **Final step — full suite** `XDEBUG_MODE=off ./vendor/bin/pest` — expect all green.

---

## Self-Review

- **Spec coverage:** D1 → Phase B (per-tenant enrichment-computed) + A2-A6 (config). D2 → A1,A7,A8. D3 → C1,C2. D4 → B1. Formula/validator → A4/A5/B4. Display+export lighting up → B6/B7. Tests → each task + C3. ✅
- **Placeholder scan:** clone instructions name the exact template file + exact substitutions (concrete, not "similar to Task N"); new logic (validator, estimator, formula, LATERAL join) has explicit rules/values. Test bodies name the specific assertions. Tenant/admin setup defers to the existing EMV test helper pattern (concrete, repo-existing). ✅
- **Type consistency:** `ReachConfiguration`, `ReachConfigurationStatus`, `ReachConfigurationService`, `ReachConfigurationValidator`, `DefaultReachEstimator`, `ReachCalculator`, `ReachResult`, `ReachFormulaIndex`, params keys `view_weight`/`follower_weight`, Livewire alias `monitoring.reach-formula-index`, routes `settings.emv`/`settings.reach`, permissions `settings.view`/`reach.manage` — used consistently across tasks. ✅
