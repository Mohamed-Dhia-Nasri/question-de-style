# Monitoring Settings & Decisions Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Per-tenant Settings → Monitoring page (gift window, trend window, story/message retention), engagement-trend tile on creator detail, per-pull AI enrichment dispatch, and ADR-0023..0026 with doc reconciliation.

**Architecture:** A new append-only `monitoring_settings` table (latest row per tenant wins) read through a context-safe resolver that falls back to config defaults; consumers are the enrichment attribution window, two per-tenant retention prune commands, and the creator-detail trend computation. Per-pull enrichment dispatch attaches at the content persister's created-ids and the story media archive job, with the existing sweep kept as recovery backstop.

**Tech Stack:** Laravel 12, Livewire 3, PostgreSQL (real DB in tests), Pest-less PHPUnit, PHPStan level 6, Pint.

**Spec:** `docs/superpowers/specs/2026-07-17-monitoring-settings-and-decisions-design.md`

## Global Constraints

- Branch: `feat/crm-ux-stage-a` (user chose; already checked out). Commit after every task.
- Tests: `XDEBUG_MODE=off ./vendor/bin/phpunit` (real Postgres `qds_test` on 127.0.0.1:5433 must be running).
- Quality gates per task: `XDEBUG_MODE=off ./vendor/bin/phpstan analyse` and `./vendor/bin/pint --dirty` clean.
- Commit messages: repo conventional style (`feat(settings): …`); **never add a Co-Authored-By or any AI-attribution trailer** (user preference).
- User-visible copy is plain language (non-technical operators; the EMV/Reach settings pages are the tone reference).
- Doctrine: missing is never zero; deferred/absent values render `<x-states.unavailable>`; every derived figure is tier DERIVED; append-only tables are never updated in place.
- Defaults (user-confirmed): gift window 60 days; engagement-trend window 30 days; story keep 180 days; message-history keep 0 = keep forever.
- The `qds.enrichment.enabled` flag (default false) must gate every NEW enrichment dispatch site.
- Numeric settings inputs: `type="text"` with `inputmode="numeric"`, values held as strings in Livewire props, hand-rolled `friendlyError()` validation (no Livewire `$rules`).

---

### Task 1: `monitoring_settings` table, model, policy, permission

**Files:**
- Create: `database/migrations/2026_07_17_100000_create_monitoring_settings_table.php`
- Create: `app/Modules/Monitoring/Models/MonitoringSetting.php`
- Create: `app/Modules/Monitoring/Policies/MonitoringSettingPolicy.php`
- Modify: `app/Shared/Authorization/PermissionsCatalog.php` (const ~after line 83, `all()` list, docblock)
- Modify: `app/Modules/Monitoring/MonitoringServiceProvider.php` (Gate::policy after line 98 + imports)
- Test: `tests/Feature/Settings/MonitoringSettingsSchemaTest.php`
- Test: `tests/Feature/Settings/MonitoringSettingsPermissionsTest.php`

**Interfaces:**
- Produces: model `App\Modules\Monitoring\Models\MonitoringSetting` (fillable: `shipment_window_days`, `engagement_trend_window_days`, `story_retention_days`, `communication_retention_days`, `updated_by`; int casts; `BelongsToTenant`), permission `PermissionsCatalog::MONITORING_SETTINGS_MANAGE = 'monitoring-settings.manage'` (ADMIN-only), policy mapping `viewAny/view → settings.view`, `create → monitoring-settings.manage`, `update/delete → false`.

- [ ] **Step 1: Write the failing tests**

`tests/Feature/Settings/MonitoringSettingsSchemaTest.php`:

```php
<?php

namespace Tests\Feature\Settings;

use App\Models\Tenant;
use App\Modules\Monitoring\Models\MonitoringSetting;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Per-tenant monitoring settings storage (ADR-0025): append-only latest-
 * row-wins rows, NOT NULL tenant ownership, and DB CHECK ranges mirroring
 * the page validation.
 */
class MonitoringSettingsSchemaTest extends TestCase
{
    use RefreshDatabase;

    private function makeRow(Tenant $tenant, array $overrides = []): MonitoringSetting
    {
        $row = new MonitoringSetting(array_merge([
            'shipment_window_days' => 60,
            'engagement_trend_window_days' => 30,
            'story_retention_days' => 180,
            'communication_retention_days' => 0,
        ], $overrides));
        $row->tenant_id = $tenant->id;
        $row->save();

        return $row;
    }

    public function test_rows_persist_with_tenant_ownership_and_history_accumulates(): void
    {
        $tenant = Tenant::factory()->create();

        $first = $this->makeRow($tenant);
        $second = $this->makeRow($tenant, ['shipment_window_days' => 45]);

        $this->assertDatabaseCount('monitoring_settings', 2);
        $this->assertSame($tenant->id, $first->refresh()->tenant_id);
        $this->assertSame(45, MonitoringSetting::query()
            ->where('tenant_id', $tenant->id)->latest('id')->first()->shipment_window_days);
        $this->assertNotSame($first->id, $second->id);
    }

    public function test_tenant_id_is_required(): void
    {
        $this->expectException(QueryException::class);

        MonitoringSetting::query()->create([
            'shipment_window_days' => 60,
            'engagement_trend_window_days' => 30,
            'story_retention_days' => 180,
            'communication_retention_days' => 0,
        ]);
    }

    public function test_check_constraint_rejects_out_of_range_values(): void
    {
        $tenant = Tenant::factory()->create();

        $this->expectException(QueryException::class);
        $this->makeRow($tenant, ['shipment_window_days' => 0]); // gift window has no off-state
    }
}
```

`tests/Feature/Settings/MonitoringSettingsPermissionsTest.php` (mirror of `ReachPermissionsTest`):

```php
<?php

namespace Tests\Feature\Settings;

use App\Shared\Authorization\PermissionsCatalog;
use App\Shared\Enums\RoleName;
use Tests\TestCase;

class MonitoringSettingsPermissionsTest extends TestCase
{
    public function test_manage_is_admin_only_and_view_stays_staff_wide(): void
    {
        $assignments = PermissionsCatalog::roleAssignments();
        $this->assertContains(PermissionsCatalog::SETTINGS_VIEW, $assignments[RoleName::Analyst->value]);
        $this->assertNotContains(PermissionsCatalog::MONITORING_SETTINGS_MANAGE, $assignments[RoleName::Analyst->value]);
        $this->assertContains(PermissionsCatalog::MONITORING_SETTINGS_MANAGE, $assignments[RoleName::Admin->value]);
        $this->assertNotContains(PermissionsCatalog::MONITORING_SETTINGS_MANAGE, $assignments[RoleName::ClientViewer->value]);
        $this->assertContains(PermissionsCatalog::MONITORING_SETTINGS_MANAGE, PermissionsCatalog::all());
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `XDEBUG_MODE=off ./vendor/bin/phpunit tests/Feature/Settings/MonitoringSettingsSchemaTest.php tests/Feature/Settings/MonitoringSettingsPermissionsTest.php`
Expected: FAIL — `Class "App\Modules\Monitoring\Models\MonitoringSetting" not found` / undefined constant `MONITORING_SETTINGS_MANAGE`.

- [ ] **Step 3: Create the migration**

`database/migrations/2026_07_17_100000_create_monitoring_settings_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Per-tenant monitoring settings (ADR-0025): the operator-chosen gift-link
 * (shipment attribution) window, engagement-trend window, and the two
 * retention periods (story media, communication logs). Append-only —
 * every save inserts a NEW row and the latest row per tenant wins
 * (mirrors monitoring_plan_settings), so setting history is auditable.
 * 0 means "keep forever" for the retention columns only; the attribution
 * and trend windows have no off-state.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monitoring_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            // Posts within this many days after delivery/shipping can be
            // attributed to the gift (ADR-0025; consumed by MentionClassifier).
            $table->unsignedSmallInteger('shipment_window_days');
            // Rolling window N for the engagement trend (ADR-0024):
            // last N days vs the N days before.
            $table->unsignedSmallInteger('engagement_trend_window_days');
            // Archived story media older than this is deleted (0 = keep forever).
            $table->unsignedSmallInteger('story_retention_days');
            // Communication logs older than this are deleted (0 = keep forever).
            $table->unsignedInteger('communication_retention_days');
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'id']);
        });

        // Mirror the page validation at the DB layer (project convention:
        // closed rules get CHECK constraints).
        DB::statement(<<<'SQL'
            ALTER TABLE monitoring_settings ADD CONSTRAINT monitoring_settings_ranges_check CHECK (
                shipment_window_days BETWEEN 1 AND 365
                AND engagement_trend_window_days BETWEEN 7 AND 90
                AND story_retention_days BETWEEN 0 AND 3650
                AND communication_retention_days BETWEEN 0 AND 3650
            )
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('monitoring_settings');
    }
};
```

- [ ] **Step 4: Create the model**

`app/Modules/Monitoring/Models/MonitoringSetting.php`:

```php
<?php

namespace App\Modules\Monitoring\Models;

use App\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * One per-tenant monitoring settings snapshot (ADR-0025). Append-only:
 * saves insert a NEW row; the latest row per tenant wins. Read ONLY
 * through MonitoringSettingsResolver (config fallback + context safety) —
 * never via a latest()-style accessor from platform code, which would
 * repeat the ADR-0019 cross-tenant-bleed limitation of
 * MonitoringPlanSetting::current().
 *
 * @property int $id
 * @property int|null $tenant_id
 * @property int $shipment_window_days
 * @property int $engagement_trend_window_days
 * @property int $story_retention_days
 * @property int $communication_retention_days
 * @property int|null $updated_by
 */
class MonitoringSetting extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'shipment_window_days',
        'engagement_trend_window_days',
        'story_retention_days',
        'communication_retention_days',
        'updated_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'shipment_window_days' => 'integer',
            'engagement_trend_window_days' => 'integer',
            'story_retention_days' => 'integer',
            'communication_retention_days' => 'integer',
        ];
    }
}
```

- [ ] **Step 5: Create the policy**

`app/Modules/Monitoring/Policies/MonitoringSettingPolicy.php`:

```php
<?php

namespace App\Modules\Monitoring\Policies;

use App\Models\User;
use App\Modules\Monitoring\Models\MonitoringSetting;
use App\Shared\Authorization\PermissionsCatalog;

/**
 * Monitoring settings (ADR-0025): staff may read them on the Settings page
 * (settings.view); only holders of monitoring-settings.manage (ADMIN) may
 * save. Rows are append-only history — never edited or deleted.
 */
class MonitoringSettingPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(PermissionsCatalog::SETTINGS_VIEW);
    }

    public function view(User $user, MonitoringSetting $setting): bool
    {
        return $user->can(PermissionsCatalog::SETTINGS_VIEW);
    }

    public function create(User $user): bool
    {
        return $user->can(PermissionsCatalog::MONITORING_SETTINGS_MANAGE);
    }

    public function update(User $user, MonitoringSetting $setting): bool
    {
        return false;
    }

    public function delete(User $user, MonitoringSetting $setting): bool
    {
        return false;
    }
}
```

- [ ] **Step 6: Register permission + policy**

In `app/Shared/Authorization/PermissionsCatalog.php` add after the `REACH_MANAGE` const (line ~83):

```php
    /**
     * Save the per-tenant monitoring settings (gift-link window, trend
     * window, retention periods — ADR-0025). ADMIN only.
     */
    public const MONITORING_SETTINGS_MANAGE = 'monitoring-settings.manage';
```

Add `self::MONITORING_SETTINGS_MANAGE,` to the `all()` array (after `self::REACH_MANAGE,`). Do NOT add it to the `$staff` list in `roleAssignments()` — ADMIN receives it via `all()`.

In `app/Modules/Monitoring/MonitoringServiceProvider.php`: add imports `use App\Modules\Monitoring\Models\MonitoringSetting;` and `use App\Modules\Monitoring\Policies\MonitoringSettingPolicy;`, then after the `ReachConfiguration` policy line (~98):

```php
        Gate::policy(MonitoringSetting::class, MonitoringSettingPolicy::class);
```

- [ ] **Step 7: Run the tests**

Run: `XDEBUG_MODE=off ./vendor/bin/phpunit tests/Feature/Settings/MonitoringSettingsSchemaTest.php tests/Feature/Settings/MonitoringSettingsPermissionsTest.php`
Expected: PASS (all).

- [ ] **Step 8: Gates + commit**

Run: `./vendor/bin/pint --dirty && XDEBUG_MODE=off ./vendor/bin/phpstan analyse`
Expected: clean.

```bash
git add database/migrations/2026_07_17_100000_create_monitoring_settings_table.php \
  app/Modules/Monitoring/Models/MonitoringSetting.php \
  app/Modules/Monitoring/Policies/MonitoringSettingPolicy.php \
  app/Shared/Authorization/PermissionsCatalog.php \
  app/Modules/Monitoring/MonitoringServiceProvider.php \
  tests/Feature/Settings/MonitoringSettingsSchemaTest.php \
  tests/Feature/Settings/MonitoringSettingsPermissionsTest.php
git commit -m "feat(settings): monitoring_settings per-tenant table, model, policy, permission (ADR-0025)"
```

---

### Task 2: config default + `MonitoringSettingsResolver`

**Files:**
- Modify: `config/qds.php` (enrichment block, after `content_window_days` at line ~256)
- Create: `app/Shared/Settings/MonitoringSettingsResolver.php`
- Test: `tests/Feature/Settings/MonitoringSettingsResolverTest.php`

**Interfaces:**
- Consumes: `MonitoringSetting` model (Task 1), `App\Shared\Tenancy\TenantContext` (`id(): ?int`).
- Produces: `MonitoringSettingsResolver` with `shipmentWindowDays(): int` (context mode, `max(1, …)` clamped), `engagementTrendWindowDays(): int` (context mode), `storyRetentionDaysFor(int $tenantId): int`, `communicationRetentionDaysFor(int $tenantId): int` (explicit mode). New config key `qds.enrichment.engagement_trend_window_days` (default 30, env `QDS_ENRICHMENT_TREND_WINDOW_DAYS`).

- [ ] **Step 1: Write the failing test**

`tests/Feature/Settings/MonitoringSettingsResolverTest.php`:

```php
<?php

namespace Tests\Feature\Settings;

use App\Models\Tenant;
use App\Modules\Monitoring\Models\MonitoringSetting;
use App\Shared\Settings\MonitoringSettingsResolver;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Context-safe settings reads (ADR-0025). The trap this kills: a tenant-
 * less read must fall back to config defaults — NEVER to another tenant's
 * latest row (the documented MonitoringPlanSetting::current() limitation).
 */
class MonitoringSettingsResolverTest extends TestCase
{
    use RefreshDatabase;

    private function saveRow(Tenant $tenant, array $overrides = []): void
    {
        $row = new MonitoringSetting(array_merge([
            'shipment_window_days' => 60,
            'engagement_trend_window_days' => 30,
            'story_retention_days' => 180,
            'communication_retention_days' => 0,
        ], $overrides));
        $row->tenant_id = $tenant->id;
        $row->save();
    }

    public function test_no_tenant_context_returns_config_defaults_never_another_tenants_row(): void
    {
        $other = Tenant::factory()->create();
        $this->saveRow($other, ['shipment_window_days' => 7, 'engagement_trend_window_days' => 14]);

        $resolver = app(MonitoringSettingsResolver::class);

        $this->assertSame(60, $resolver->shipmentWindowDays());
        $this->assertSame(30, $resolver->engagementTrendWindowDays());
    }

    public function test_active_context_reads_that_tenants_latest_row(): void
    {
        $tenant = Tenant::factory()->create();
        $this->saveRow($tenant, ['shipment_window_days' => 45]);
        $this->saveRow($tenant, ['shipment_window_days' => 10, 'engagement_trend_window_days' => 14]);

        $days = app(TenantContext::class)->runAs(
            $tenant->id,
            fn (): array => [
                app(MonitoringSettingsResolver::class)->shipmentWindowDays(),
                app(MonitoringSettingsResolver::class)->engagementTrendWindowDays(),
            ],
        );

        $this->assertSame([10, 14], $days);
    }

    public function test_explicit_tenant_reads_are_isolated_per_tenant_with_config_fallback(): void
    {
        $configured = Tenant::factory()->create();
        $bare = Tenant::factory()->create();
        $this->saveRow($configured, ['story_retention_days' => 30, 'communication_retention_days' => 365]);

        $resolver = app(MonitoringSettingsResolver::class);

        $this->assertSame(30, $resolver->storyRetentionDaysFor($configured->id));
        $this->assertSame(365, $resolver->communicationRetentionDaysFor($configured->id));
        // No row → the existing config defaults (story 180, comms 0 = keep forever).
        $this->assertSame(180, $resolver->storyRetentionDaysFor($bare->id));
        $this->assertSame(0, $resolver->communicationRetentionDaysFor($bare->id));
    }

    public function test_trend_window_config_default_is_30(): void
    {
        $this->assertSame(30, config('qds.enrichment.engagement_trend_window_days'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `XDEBUG_MODE=off ./vendor/bin/phpunit tests/Feature/Settings/MonitoringSettingsResolverTest.php`
Expected: FAIL — class not found.

- [ ] **Step 3: Add the config key**

In `config/qds.php`, inside the `'enrichment'` array directly after the `content_window_days` entry (line ~256), add:

```php
        // Rolling window N for the engagement trend (ADR-0024): compares
        // the last N days with the N days before. Config default; each
        // tenant can override it on Settings → Monitoring (ADR-0025).
        'engagement_trend_window_days' => (int) env('QDS_ENRICHMENT_TREND_WINDOW_DAYS', 30),
```

- [ ] **Step 4: Create the resolver**

`app/Shared/Settings/MonitoringSettingsResolver.php`:

```php
<?php

namespace App\Shared\Settings;

use App\Modules\Monitoring\Models\MonitoringSetting;
use App\Shared\Tenancy\TenantContext;

/**
 * The ONLY read path for per-tenant monitoring settings (ADR-0025).
 *
 * Two access modes, so no caller can ever see another tenant's row:
 *  - context mode (no argument): resolves the ACTIVE TenantContext's
 *    latest row; with NO context bound it returns the config default —
 *    never `latest()` across tenants (the documented ADR-0019 limitation
 *    of MonitoringPlanSetting::current() that this class must not repeat);
 *  - explicit mode (…For(int $tenantId)): for tenant-less schedulers that
 *    iterate tenants (retention prune commands).
 *
 * Rows are memoized per tenant id for the life of this instance (the
 * class is NOT a singleton — each resolution starts fresh).
 */
class MonitoringSettingsResolver
{
    /** @var array<int, MonitoringSetting|null> */
    private array $rows = [];

    public function __construct(private readonly TenantContext $context) {}

    /** Gift-link (shipment attribution) window; clamped ≥ 1 — no off-state. */
    public function shipmentWindowDays(): int
    {
        return max(1, $this->contextRow()?->shipment_window_days
            ?? (int) config('qds.enrichment.attribution.shipment_window_days'));
    }

    /** Engagement-trend rolling window N (ADR-0024). */
    public function engagementTrendWindowDays(): int
    {
        return max(1, $this->contextRow()?->engagement_trend_window_days
            ?? (int) config('qds.enrichment.engagement_trend_window_days'));
    }

    /** Story media retention for ONE tenant; 0 = keep forever. */
    public function storyRetentionDaysFor(int $tenantId): int
    {
        return max(0, $this->rowFor($tenantId)?->story_retention_days
            ?? (int) config('qds.ingestion.media_retention_days'));
    }

    /** Communication-log retention for ONE tenant; 0 = keep forever. */
    public function communicationRetentionDaysFor(int $tenantId): int
    {
        return max(0, $this->rowFor($tenantId)?->communication_retention_days
            ?? (int) config('qds.gdpr.communication_log_retention_days'));
    }

    private function contextRow(): ?MonitoringSetting
    {
        $tenantId = $this->context->id();

        return $tenantId === null ? null : $this->rowFor($tenantId);
    }

    private function rowFor(int $tenantId): ?MonitoringSetting
    {
        if (! array_key_exists($tenantId, $this->rows)) {
            $this->rows[$tenantId] = MonitoringSetting::query()
                ->withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->latest('id')
                ->first();
        }

        return $this->rows[$tenantId];
    }
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `XDEBUG_MODE=off ./vendor/bin/phpunit tests/Feature/Settings/MonitoringSettingsResolverTest.php`
Expected: PASS.

- [ ] **Step 6: Gates + commit**

```bash
./vendor/bin/pint --dirty && XDEBUG_MODE=off ./vendor/bin/phpstan analyse
git add config/qds.php app/Shared/Settings/MonitoringSettingsResolver.php tests/Feature/Settings/MonitoringSettingsResolverTest.php
git commit -m "feat(settings): context-safe MonitoringSettingsResolver with config fallbacks (ADR-0025)"
```

---

### Task 3: Settings → Monitoring page

**Files:**
- Create: `app/Modules/Monitoring/Livewire/Settings/MonitoringSettings.php`
- Create: `resources/views/livewire/monitoring/monitoring-settings.blade.php`
- Create: `resources/views/settings/monitoring.blade.php`
- Modify: `routes/web.php` (settings group, lines 29–33)
- Modify: `resources/views/layouts/sidebar.blade.php` (`$settingsItems`, lines 61–76)
- Modify: `app/Modules/Monitoring/MonitoringServiceProvider.php` (Livewire registration ~line 102 + import)
- Modify: `tests/Feature/Settings/SettingsRoutesTest.php`, `tests/Feature/Settings/SettingsNavTest.php`
- Test: `tests/Feature/Settings/MonitoringSettingsPageTest.php`

**Interfaces:**
- Consumes: `MonitoringSetting` model + policy (Task 1); config defaults (Task 2).
- Produces: route `settings.monitoring` → `/settings/monitoring`; Livewire alias `monitoring.monitoring-settings`; component public props `shipmentDays`, `trendDays`, `storyCleanupEnabled`, `storyDays`, `commsCleanupEnabled`, `commsDays`, `formError`; method `save()`.

- [ ] **Step 1: Write the failing tests**

`tests/Feature/Settings/MonitoringSettingsPageTest.php`:

```php
<?php

namespace Tests\Feature\Settings;

use App\Modules\Monitoring\Livewire\Settings\MonitoringSettings;
use App\Modules\Monitoring\Models\MonitoringSetting;
use App\Shared\Enums\RoleName;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Settings → Monitoring (ADR-0024/0025): one page, four plain-language
 * values. Saves append a new history row; admins only; friendly errors.
 */
class MonitoringSettingsPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
    }

    public function test_defaults_hydrate_from_config_when_no_row_exists(): void
    {
        Livewire::actingAs($this->makeUser(RoleName::Admin))->test(MonitoringSettings::class)
            ->assertSet('shipmentDays', '60')
            ->assertSet('trendDays', '30')
            ->assertSet('storyCleanupEnabled', true)
            ->assertSet('storyDays', '180')
            ->assertSet('commsCleanupEnabled', false);
    }

    public function test_admin_save_appends_a_new_row_with_all_four_values(): void
    {
        $admin = $this->makeUser(RoleName::Admin);

        Livewire::actingAs($admin)->test(MonitoringSettings::class)
            ->set('shipmentDays', '45')
            ->set('trendDays', '14')
            ->set('storyCleanupEnabled', true)
            ->set('storyDays', '90')
            ->set('commsCleanupEnabled', true)
            ->set('commsDays', '365')
            ->call('save')
            ->assertSet('formError', null);

        $row = MonitoringSetting::query()->withoutGlobalScopes()->sole();
        $this->assertSame(45, $row->shipment_window_days);
        $this->assertSame(14, $row->engagement_trend_window_days);
        $this->assertSame(90, $row->story_retention_days);
        $this->assertSame(365, $row->communication_retention_days);
        $this->assertSame($admin->id, $row->updated_by);
        $this->assertSame($admin->tenant_id, $row->tenant_id);
    }

    public function test_disabled_cleanup_toggles_store_zero_meaning_keep_forever(): void
    {
        Livewire::actingAs($this->makeUser(RoleName::Admin))->test(MonitoringSettings::class)
            ->set('storyCleanupEnabled', false)
            ->set('commsCleanupEnabled', false)
            ->call('save')
            ->assertSet('formError', null);

        $row = MonitoringSetting::query()->withoutGlobalScopes()->sole();
        $this->assertSame(0, $row->story_retention_days);
        $this->assertSame(0, $row->communication_retention_days);
    }

    public function test_saving_twice_appends_history_rows(): void
    {
        $admin = $this->makeUser(RoleName::Admin);

        Livewire::actingAs($admin)->test(MonitoringSettings::class)->call('save');
        Livewire::actingAs($admin)->test(MonitoringSettings::class)
            ->set('shipmentDays', '30')->call('save');

        $this->assertSame(2, MonitoringSetting::query()->withoutGlobalScopes()->count());
        $this->assertSame(
            30,
            MonitoringSetting::query()->withoutGlobalScopes()->latest('id')->first()->shipment_window_days,
        );
    }

    public function test_non_admin_staff_cannot_save(): void
    {
        Livewire::actingAs($this->makeUser(RoleName::Analyst))->test(MonitoringSettings::class)
            ->call('save')
            ->assertForbidden();

        $this->assertSame(0, MonitoringSetting::query()->withoutGlobalScopes()->count());
    }

    public function test_friendly_errors_reject_out_of_range_values(): void
    {
        $page = Livewire::actingAs($this->makeUser(RoleName::Admin))->test(MonitoringSettings::class);

        $page->set('shipmentDays', '0')->call('save');
        $this->assertNotNull($page->get('formError'));

        $page->set('shipmentDays', '60')->set('trendDays', '5')->call('save');
        $this->assertNotNull($page->get('formError'));

        $page->set('trendDays', '30')->set('storyCleanupEnabled', true)->set('storyDays', 'abc')->call('save');
        $this->assertNotNull($page->get('formError'));

        $this->assertSame(0, MonitoringSetting::query()->withoutGlobalScopes()->count());
    }

    public function test_existing_latest_row_hydrates_the_form(): void
    {
        $admin = $this->makeUser(RoleName::Admin);
        $row = new MonitoringSetting([
            'shipment_window_days' => 21,
            'engagement_trend_window_days' => 60,
            'story_retention_days' => 0,
            'communication_retention_days' => 730,
        ]);
        $row->tenant_id = $admin->tenant_id;
        $row->save();

        Livewire::actingAs($admin)->test(MonitoringSettings::class)
            ->assertSet('shipmentDays', '21')
            ->assertSet('trendDays', '60')
            ->assertSet('storyCleanupEnabled', false)
            ->assertSet('commsCleanupEnabled', true)
            ->assertSet('commsDays', '730');
    }
}
```

In `tests/Feature/Settings/SettingsRoutesTest.php` extend the two existing tests:
- in `test_staff_can_open_settings_emv_and_reach()` add `$this->actingAs($analyst)->get('/settings/monitoring')->assertOk();`
- in `test_client_viewer_is_forbidden_from_settings()` add `$this->actingAs($client)->get('/settings/monitoring')->assertForbidden();`

In `tests/Feature/Settings/SettingsNavTest.php` add to `test_staff_see_the_settings_section_with_emv_and_reach_links()`: `$res->assertSee(route('settings.monitoring'));`

- [ ] **Step 2: Run tests to verify they fail**

Run: `XDEBUG_MODE=off ./vendor/bin/phpunit tests/Feature/Settings/`
Expected: FAIL — component class not found; route not defined.

- [ ] **Step 3: Create the Livewire component**

`app/Modules/Monitoring/Livewire/Settings/MonitoringSettings.php`:

```php
<?php

namespace App\Modules\Monitoring\Livewire\Settings;

use App\Models\User;
use App\Modules\Monitoring\Models\MonitoringSetting;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

/**
 * Settings → Monitoring (ADR-0024/0025): one page for the four per-tenant
 * monitoring values — gift-link window, engagement-trend window, story
 * media keep-time, and message-history keep-time — in the same plain-
 * language single-setting-editor style as EMV/Reach. Saving appends a NEW
 * monitoring_settings row (history is never edited in place).
 *
 * Page needs settings.view; saving re-authorizes on
 * monitoring-settings.manage (ADMIN) via MonitoringSettingPolicy.
 */
class MonitoringSettings extends Component
{
    /** Bounds mirrored by the DB CHECK constraint. */
    private const SHIPMENT_RANGE = [1, 365];

    private const TREND_RANGE = [7, 90];

    private const RETENTION_RANGE = [1, 3650];

    public string $shipmentDays = '';

    public string $trendDays = '';

    public bool $storyCleanupEnabled = false;

    public string $storyDays = '';

    public bool $commsCleanupEnabled = false;

    public string $commsDays = '';

    public ?string $formError = null;

    public function mount(): void
    {
        $this->authorize('viewAny', MonitoringSetting::class);

        // Latest row for the requester's tenant (TenantScope; HTTP always
        // has a tenant context), else the config defaults.
        $row = MonitoringSetting::query()->latest('id')->first();

        $shipment = $row->shipment_window_days
            ?? (int) config('qds.enrichment.attribution.shipment_window_days');
        $trend = $row->engagement_trend_window_days
            ?? (int) config('qds.enrichment.engagement_trend_window_days');
        $story = $row->story_retention_days
            ?? (int) config('qds.ingestion.media_retention_days');
        $comms = $row->communication_retention_days
            ?? (int) config('qds.gdpr.communication_log_retention_days');

        $this->shipmentDays = (string) $shipment;
        $this->trendDays = (string) $trend;
        $this->storyCleanupEnabled = $story > 0;
        $this->storyDays = (string) ($story > 0 ? $story : 180);
        $this->commsCleanupEnabled = $comms > 0;
        $this->commsDays = (string) ($comms > 0 ? $comms : 365);
    }

    public function save(): void
    {
        $this->authorize('create', MonitoringSetting::class);

        $this->formError = $this->friendlyError();

        if ($this->formError !== null) {
            return;
        }

        MonitoringSetting::query()->create([
            'shipment_window_days' => $this->int($this->shipmentDays),
            'engagement_trend_window_days' => $this->int($this->trendDays),
            'story_retention_days' => $this->storyCleanupEnabled ? $this->int($this->storyDays) : 0,
            'communication_retention_days' => $this->commsCleanupEnabled ? $this->int($this->commsDays) : 0,
            'updated_by' => Auth::id(),
        ]);

        $this->dispatch('notify', type: 'success', message: 'Monitoring settings saved.');
    }

    private function friendlyError(): ?string
    {
        [$min, $max] = self::SHIPMENT_RANGE;
        if (! $this->isIntBetween($this->shipmentDays, $min, $max)) {
            return "\"Gift link window\" must be a whole number of days between {$min} and {$max}.";
        }

        [$min, $max] = self::TREND_RANGE;
        if (! $this->isIntBetween($this->trendDays, $min, $max)) {
            return "\"Engagement trend window\" must be a whole number of days between {$min} and {$max}.";
        }

        [$min, $max] = self::RETENTION_RANGE;
        if ($this->storyCleanupEnabled && ! $this->isIntBetween($this->storyDays, $min, $max)) {
            return "\"Keep story files for\" must be a whole number of days between {$min} and {$max} — or switch the cleanup off to keep them forever.";
        }

        if ($this->commsCleanupEnabled && ! $this->isIntBetween($this->commsDays, $min, $max)) {
            return "\"Keep message history for\" must be a whole number of days between {$min} and {$max} — or switch the cleanup off to keep it forever.";
        }

        return null;
    }

    private function isIntBetween(string $value, int $min, int $max): bool
    {
        $trimmed = trim($value);

        if (preg_match('/^\d+$/', $trimmed) !== 1) {
            return false;
        }

        $number = (int) $trimmed;

        return $number >= $min && $number <= $max;
    }

    private function int(string $value): int
    {
        return (int) trim($value);
    }

    private function user(): User
    {
        /** @var User */
        return Auth::user();
    }

    public function render(): View
    {
        return view('livewire.monitoring.monitoring-settings', [
            'canManage' => $this->user()->can('create', MonitoringSetting::class),
        ]);
    }
}
```

- [ ] **Step 4: Create the blade views**

`resources/views/settings/monitoring.blade.php`:

```blade
<x-layouts.app title="Monitoring settings">
    <x-page-header title="Monitoring settings"
        :breadcrumbs="['Dashboard' => route('dashboard'), 'Settings' => route('settings.monitoring'), 'Monitoring' => null]" />

    @livewire('monitoring.monitoring-settings')
</x-layouts.app>
```

`resources/views/livewire/monitoring/monitoring-settings.blade.php`:

```blade
<div class="space-y-5">
    {{-- How it works --}}
    <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
        <h3 class="text-base font-semibold text-gray-800 dark:text-white/90">How these settings work</h3>
        <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">
            These four values control how monitoring links posts to gifts, how trends are
            calculated, and how long collected files and notes are kept. They apply to your
            whole workspace. Every save is recorded, and changes apply from now on — nothing
            already calculated is changed.
        </p>
    </div>

    <div class="space-y-5 rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
        @if ($formError)
            <x-ui.alert variant="error">{{ $formError }}</x-ui.alert>
        @endif

        @unless ($canManage)
            <x-ui.alert variant="info">Only administrators can change these settings.</x-ui.alert>
        @endunless

        {{-- Gift link window --}}
        <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-800">
            <span class="text-sm font-semibold text-gray-800 dark:text-white/90">Gift link window</span>
            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                After you send a product, posts published within this many days can be linked to
                that gift. Example: product delivered on 1 June with a 60-day window — posts up
                to 31 July can count for it.
            </p>
            <div class="mt-3 max-w-45">
                <x-form.label for="shipment-days">Days after delivery</x-form.label>
                <x-form.input id="shipment-days" type="text" inputmode="numeric"
                    wire:model.live.debounce.400ms="shipmentDays" :disabled="! $canManage" />
            </div>
        </div>

        {{-- Engagement trend window --}}
        <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-800">
            <span class="text-sm font-semibold text-gray-800 dark:text-white/90">Engagement trend window</span>
            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                The trend on a creator's page compares the average likes + comments per post of
                the last period with the period before. Example: with 30 days, it compares the
                last 30 days to the 30 days before that.
            </p>
            <div class="mt-3 max-w-45">
                <x-form.label for="trend-days">Days per period</x-form.label>
                <x-form.input id="trend-days" type="text" inputmode="numeric"
                    wire:model.live.debounce.400ms="trendDays" :disabled="! $canManage" />
            </div>
        </div>

        {{-- Story keep time --}}
        <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-800">
            <span class="text-sm font-semibold text-gray-800 dark:text-white/90">Story keep time</span>
            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                Stories disappear from the platform after 24 hours, so QDS saves a copy of the
                photo or video. Old files can be cleaned up automatically — the story's numbers
                and text are always kept, only the file is deleted. Deletion is permanent.
            </p>
            <div class="mt-3">
                <x-form.toggle label="Delete old story files automatically" wire:model.live="storyCleanupEnabled" :disabled="! $canManage" />
            </div>
            @if ($storyCleanupEnabled)
                <div class="mt-3 max-w-45">
                    <x-form.label for="story-days">Keep story files for (days)</x-form.label>
                    <x-form.input id="story-days" type="text" inputmode="numeric"
                        wire:model.live.debounce.400ms="storyDays" :disabled="! $canManage" />
                </div>
            @else
                <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">Story files are kept forever.</p>
            @endif
        </div>

        {{-- Message history keep time --}}
        <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-800">
            <span class="text-sm font-semibold text-gray-800 dark:text-white/90">Message history keep time</span>
            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                Notes about calls, emails and messages with creators are the longest-lived
                personal data in the CRM. For privacy rules you can delete old entries
                automatically. Example: 365 keeps one year of history.
            </p>
            <div class="mt-3">
                <x-form.toggle label="Delete old message history automatically" wire:model.live="commsCleanupEnabled" :disabled="! $canManage" />
            </div>
            @if ($commsCleanupEnabled)
                <div class="mt-3 max-w-45">
                    <x-form.label for="comms-days">Keep message history for (days)</x-form.label>
                    <x-form.input id="comms-days" type="text" inputmode="numeric"
                        wire:model.live.debounce.400ms="commsDays" :disabled="! $canManage" />
                </div>
            @else
                <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">Message history is kept forever.</p>
            @endif
        </div>

        @if ($canManage)
            <div class="flex flex-wrap items-center gap-3 border-t border-gray-100 pt-4 dark:border-gray-800">
                <x-ui.button wire:click="save" wire:loading.attr="disabled">Save changes</x-ui.button>
                <span class="text-xs text-gray-500 dark:text-gray-400">
                    Applies from now on — the gift window and trend use the new values on the next
                    calculation, and file cleanup runs nightly.
                </span>
            </div>
        @endif
    </div>
</div>
```

- [ ] **Step 5: Register route, nav, component**

`routes/web.php` — add inside the settings group after the reach line (line 32):

```php
        Route::view('/monitoring', 'settings.monitoring')->name('monitoring');
```

`resources/views/layouts/sidebar.blade.php` — append to `$settingsItems` (after the Reach entry, line ~75), reusing the same SVG icon string as the Reach entry:

```php
        [
            'name' => 'Monitoring',
            'route' => 'settings.monitoring',
            'active' => request()->routeIs('settings.monitoring'),
            'can' => 'settings.view',
            'icon' => '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M10.5 6H3.75M10.5 6a1.5 1.5 0 1 0 3 0m-3 0a1.5 1.5 0 1 1 3 0M13.5 6h6.75m-6.75 6H3.75m9.75 0a1.5 1.5 0 1 0 3 0m-3 0a1.5 1.5 0 1 1 3 0m3 0h.75m-9 6H3.75m9.75 0a1.5 1.5 0 1 0 3 0m-3 0a1.5 1.5 0 1 1 3 0m3 0h.75" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>',
        ],
```

`app/Modules/Monitoring/MonitoringServiceProvider.php` — add import `use App\Modules\Monitoring\Livewire\Settings\MonitoringSettings;` and after the `monitoring.reach-settings` registration (line ~102):

```php
        Livewire::component('monitoring.monitoring-settings', MonitoringSettings::class);
```

- [ ] **Step 6: Run the tests**

Run: `XDEBUG_MODE=off ./vendor/bin/phpunit tests/Feature/Settings/`
Expected: PASS (all, including the extended routes/nav tests).

- [ ] **Step 7: Gates + commit**

```bash
./vendor/bin/pint --dirty && XDEBUG_MODE=off ./vendor/bin/phpstan analyse
git add app/Modules/Monitoring/Livewire/Settings/MonitoringSettings.php \
  resources/views/livewire/monitoring/monitoring-settings.blade.php \
  resources/views/settings/monitoring.blade.php routes/web.php \
  resources/views/layouts/sidebar.blade.php app/Modules/Monitoring/MonitoringServiceProvider.php \
  tests/Feature/Settings/
git commit -m "feat(settings): Settings → Monitoring page — four per-tenant values, admin-only save"
```

---

### Task 4: per-tenant retention pruning

**Files:**
- Modify: `app/Platform/Ingestion/Console/PruneStoryMediaCommand.php` (whole `handle()`)
- Modify: `app/Modules/CRM/Console/GdprEnforceRetentionCommand.php` (`handle()` + `pruneCommunicationLogs()`)
- Modify: `config/qds.php` comment lines 175–178 and 390–393
- Test: `tests/Feature/Settings/PerTenantRetentionTest.php`
- Verify unchanged-behavior: `tests/Feature/Ingestion/StoryMediaRetentionTest.php`, `tests/Feature/Crm/GdprTest.php` (no edits expected — config fallback keeps them green)

**Interfaces:**
- Consumes: `MonitoringSettingsResolver::storyRetentionDaysFor()` / `communicationRetentionDaysFor()` (Task 2), `App\Models\Tenant`.
- Produces: both commands prune per tenant with explicit `where('tenant_id', …)` predicates; `0` skips the tenant.

- [ ] **Step 1: Write the failing test**

`tests/Feature/Settings/PerTenantRetentionTest.php`:

```php
<?php

namespace Tests\Feature\Settings;

use App\Models\Tenant;
use App\Modules\CRM\Models\CommunicationLog;
use App\Modules\CRM\Models\Creator;
use App\Modules\Monitoring\Models\MonitoringSetting;
use App\Modules\Monitoring\Models\Story;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * ADR-0025: retention cleanups run tenant by tenant with each workspace's
 * own keep-time — never one global number, and 0 means keep forever.
 */
class PerTenantRetentionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('media');
    }

    private function settingsFor(Tenant $tenant, int $storyDays, int $commsDays): void
    {
        $row = new MonitoringSetting([
            'shipment_window_days' => 60,
            'engagement_trend_window_days' => 30,
            'story_retention_days' => $storyDays,
            'communication_retention_days' => $commsDays,
        ]);
        $row->tenant_id = $tenant->id;
        $row->save();
    }

    private function storyFor(Tenant $tenant, string $path, int $ageDays): Story
    {
        Storage::disk('media')->put($path, 'FAKE-BYTES');

        $story = Story::factory()->make([
            'media_url' => $path,
            'captured_at' => now()->subDays($ageDays),
        ]);
        $story->tenant_id = $tenant->id;
        $story->save();

        return $story;
    }

    public function test_each_tenant_is_pruned_with_its_own_story_retention(): void
    {
        $short = Tenant::factory()->create();
        $keeper = Tenant::factory()->create();
        $this->settingsFor($short, storyDays: 30, commsDays: 0);
        $this->settingsFor($keeper, storyDays: 0, commsDays: 0); // keep forever

        $prunedStory = $this->storyFor($short, 'stories/a/old.mp4', ageDays: 60);
        $keptYoung = $this->storyFor($short, 'stories/a/new.mp4', ageDays: 5);
        $keptForever = $this->storyFor($keeper, 'stories/b/old.mp4', ageDays: 400);

        $this->artisan('qds:prune-story-media')->assertSuccessful();

        Storage::disk('media')->assertMissing('stories/a/old.mp4');
        Storage::disk('media')->assertExists('stories/a/new.mp4');
        Storage::disk('media')->assertExists('stories/b/old.mp4');

        $this->assertNull($prunedStory->refresh()->media_url);
        $this->assertNotNull($keptYoung->refresh()->media_url);
        $this->assertNotNull($keptForever->refresh()->media_url);
    }

    public function test_each_tenant_is_pruned_with_its_own_comms_retention(): void
    {
        $short = Tenant::factory()->create();
        $keeper = Tenant::factory()->create();
        $this->settingsFor($short, storyDays: 0, commsDays: 30);
        $this->settingsFor($keeper, storyDays: 0, commsDays: 0);

        $creatorShort = Creator::factory()->create(['tenant_id' => $short->id]);
        $creatorKeeper = Creator::factory()->create(['tenant_id' => $keeper->id]);

        $pruned = CommunicationLog::factory()->create([
            'tenant_id' => $short->id,
            'creator_id' => $creatorShort->id,
            'occurred_at' => now()->subDays(60),
        ]);
        $kept = CommunicationLog::factory()->create([
            'tenant_id' => $keeper->id,
            'creator_id' => $creatorKeeper->id,
            'occurred_at' => now()->subDays(400),
        ]);

        $this->artisan('qds:gdpr-enforce-retention')->assertSuccessful();

        $this->assertDatabaseMissing('communication_logs', ['id' => $pruned->id]);
        $this->assertDatabaseHas('communication_logs', ['id' => $kept->id]);
    }
}
```

Note: if `Creator`/`CommunicationLog` factories auto-create their own tenants and ignore an explicit `tenant_id` attribute, set it the same way as `MonitoringSetting` above (`make()` then assign `tenant_id` then `save()`).

- [ ] **Step 2: Run test to verify it fails**

Run: `XDEBUG_MODE=off ./vendor/bin/phpunit tests/Feature/Settings/PerTenantRetentionTest.php`
Expected: FAIL — `stories/b/old.mp4` gets deleted (global config 180 < 400 days) / keeper's comms row deleted once configured globally. (If it fails earlier on factory/tenant mechanics, fix the test setup per the note above first.)

- [ ] **Step 3: Rewrite `PruneStoryMediaCommand::handle()`**

Replace the whole `handle()` body (and add imports `use App\Models\Tenant;` + `use App\Shared\Settings\MonitoringSettingsResolver;`):

```php
    public function handle(MonitoringSettingsResolver $settings): int
    {
        $disk = Storage::disk((string) config('qds.ingestion.media_disk'));
        $pruned = 0;

        // ADR-0025: retention is per tenant. The scheduler runs tenant-less
        // (TenantScope is a no-op), so ownership is an EXPLICIT predicate.
        foreach (Tenant::query()->pluck('id') as $tenantId) {
            $retentionDays = $settings->storyRetentionDaysFor((int) $tenantId);

            if ($retentionDays <= 0) {
                continue; // this workspace keeps story files forever
            }

            $cutoff = CarbonImmutable::now()->subDays($retentionDays);

            Story::query()
                ->withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->whereNotNull('media_url')
                ->where('captured_at', '<', $cutoff)
                ->chunkById(100, function ($stories) use ($disk, &$pruned) {
                    foreach ($stories as $story) {
                        $disk->delete((string) $story->media_url);

                        $story->update([
                            'media_url' => null,
                            'media_pruned_at' => CarbonImmutable::now(),
                        ]);

                        $pruned++;
                    }
                });
        }

        $this->info("Pruned archived media for {$pruned} stories past their workspace's keep-time.");

        return self::SUCCESS;
    }
```

Also update the class docblock's second sentence to mention per-tenant settings (ADR-0025), and update `config/qds.php` lines 175–178 comment to:

```php
        // Archived story media retention DEFAULT (media storage lifecycle,
        // DP-005, ADR-0025): the fallback for tenants that never saved
        // Settings → Monitoring. 0 disables pruning for such tenants.
```

- [ ] **Step 4: Rewrite `GdprEnforceRetentionCommand` communication pruning**

Change `handle()` to inject and pass the resolver, and replace `pruneCommunicationLogs()` (add imports `use App\Models\Tenant;` + `use App\Shared\Settings\MonitoringSettingsResolver;`):

```php
    public function handle(MonitoringSettingsResolver $settings): int
    {
        $logs = $this->pruneCommunicationLogs($settings);
        $files = $this->pruneGdprExportFiles();

        $this->info("Pruned {$logs} communication logs and {$files} GDPR export files.");

        return self::SUCCESS;
    }

    private function pruneCommunicationLogs(MonitoringSettingsResolver $settings): int
    {
        $pruned = 0;

        // ADR-0025: per-tenant keep-time; 0 = keep forever for that tenant.
        foreach (Tenant::query()->pluck('id') as $tenantId) {
            $retentionDays = $settings->communicationRetentionDaysFor((int) $tenantId);

            if ($retentionDays <= 0) {
                continue;
            }

            $pruned += CommunicationLog::query()
                ->withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('occurred_at', '<', CarbonImmutable::now()->subDays($retentionDays))
                ->delete();
        }

        return $pruned;
    }
```

Update the class docblock bullet about "NOT canonically decided" to reference ADR-0025 (per-tenant setting, config default 0 = keep forever), and update `config/qds.php` lines 390–393 comment to:

```php
    | communication-log retention DEFAULT is the fallback for tenants that
    | never saved Settings → Monitoring (ADR-0025) — 0 keeps history forever
    | for such tenants. GDPR export files always expire with qds.exports.ttl_hours.
```

- [ ] **Step 5: Run the tests**

Run: `XDEBUG_MODE=off ./vendor/bin/phpunit tests/Feature/Settings/PerTenantRetentionTest.php tests/Feature/Ingestion/StoryMediaRetentionTest.php tests/Feature/Crm/GdprTest.php`
Expected: PASS — the old tests stay green because tenants without rows fall back to the same config values those tests set.

- [ ] **Step 6: Gates + commit**

```bash
./vendor/bin/pint --dirty && XDEBUG_MODE=off ./vendor/bin/phpstan analyse
git add app/Platform/Ingestion/Console/PruneStoryMediaCommand.php \
  app/Modules/CRM/Console/GdprEnforceRetentionCommand.php config/qds.php \
  tests/Feature/Settings/PerTenantRetentionTest.php
git commit -m "feat(retention): per-tenant story-media and comms-log pruning via monitoring settings (ADR-0025)"
```

---

### Task 5: per-tenant gift-link window in `MentionClassifier`

**Files:**
- Modify: `app/Platform/Enrichment/Attribution/MentionClassifier.php` (lines 211 and 278 + new private method + docblock note)
- Modify: `config/qds.php` attribution comment (lines 281–286)
- Test: `tests/Feature/Enrichment/PerTenantShipmentWindowTest.php`
- Verify unchanged-behavior: `tests/Unit/Enrichment/MentionClassifierTest.php` (no edits — no tenant context in unit tests → config fallback 60 still applies, and the resolver does NOT query the DB when no context is bound)

**Interfaces:**
- Consumes: `MonitoringSettingsResolver::shipmentWindowDays()` (Task 2 — already `max(1, …)` clamped).

- [ ] **Step 1: Write the failing test**

`tests/Feature/Enrichment/PerTenantShipmentWindowTest.php` (fixture copied from `MentionClassifierTest::test_strong_recognition_with_aligned_shipment_in_window_is_seeded_high`):

```php
<?php

namespace Tests\Feature\Enrichment;

use App\Models\Tenant;
use App\Modules\Monitoring\Models\MonitoringSetting;
use App\Platform\Enrichment\Attribution\EvidenceBundle;
use App\Platform\Enrichment\Attribution\MentionClassifier;
use App\Platform\Enrichment\Attribution\ShipmentEvidence;
use App\Shared\Enums\ConfidenceLevel;
use App\Shared\Enums\MentionType;
use App\Shared\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ADR-0025: the gift-link (shipment attribution) window is a per-tenant
 * setting. The same evidence classifies differently under a tenant whose
 * window is shorter than the config default.
 */
class PerTenantShipmentWindowTest extends TestCase
{
    use RefreshDatabase;

    /** Published 4 days after delivery of the same brand's shipment. */
    private function evidence(): EvidenceBundle
    {
        return new EvidenceBundle(
            recognitions: [['type' => 'LOGO', 'brand' => 'Maison Lumière', 'level' => ConfidenceLevel::High]],
            shipments: [new ShipmentEvidence(
                reference: 'shipment-record:42',
                brandName: 'Maison Lumière',
                deliveredAt: CarbonImmutable::parse('2026-06-02'),
            )],
            publishedAt: CarbonImmutable::parse('2026-06-06'),
        );
    }

    public function test_default_window_still_classifies_seeded_without_a_tenant_row(): void
    {
        $result = (new MentionClassifier)->classify($this->evidence());

        $this->assertNotNull($result);
        $this->assertSame(MentionType::Seeded, $result->mentionType);
    }

    public function test_a_tenant_with_a_shorter_window_no_longer_links_the_gift(): void
    {
        $tenant = Tenant::factory()->create();
        $row = new MonitoringSetting([
            'shipment_window_days' => 3, // published 4 days after delivery → outside
            'engagement_trend_window_days' => 30,
            'story_retention_days' => 180,
            'communication_retention_days' => 0,
        ]);
        $row->tenant_id = $tenant->id;
        $row->save();

        $result = app(TenantContext::class)->runAs(
            $tenant->id,
            fn () => (new MentionClassifier)->classify($this->evidence()),
        );

        // Timing is KNOWN outside this tenant's window → the shipment is no
        // proving record; whatever remains, it must never be SEEDED.
        $this->assertNotSame(MentionType::Seeded, $result?->mentionType);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `XDEBUG_MODE=off ./vendor/bin/phpunit tests/Feature/Enrichment/PerTenantShipmentWindowTest.php`
Expected: the first test PASSES, the second FAILS (still `Seeded` — the classifier reads global config, ignoring the tenant row).

- [ ] **Step 3: Point the classifier at the resolver**

In `app/Platform/Enrichment/Attribution/MentionClassifier.php` add import `use App\Shared\Settings\MonitoringSettingsResolver;` and a private method at the end of the class:

```php
    /**
     * Per-tenant gift-link window (ADR-0025): enrichment always runs under
     * TenantContext::runAs, so the active tenant's Settings → Monitoring
     * value applies; tenant-less callers get the config default.
     */
    private function shipmentWindowDays(): int
    {
        return app(MonitoringSettingsResolver::class)->shipmentWindowDays();
    }
```

Replace BOTH occurrences of

```php
        $windowDays = max(1, (int) config('qds.enrichment.attribution.shipment_window_days'));
```

(lines 211 and 278) with:

```php
        $windowDays = $this->shipmentWindowDays();
```

Update the `config/qds.php` attribution comment (lines 281–286) to:

```php
        'attribution' => [
            // A shipment supports SEEDED attribution only when the content
            // was published within this many days after delivery/shipping.
            // DEFAULT for tenants without a Settings → Monitoring row
            // (ADR-0025 — per-tenant via MonitoringSettingsResolver).
            'shipment_window_days' => (int) env('QDS_ENRICHMENT_SHIPMENT_WINDOW_DAYS', 60),
        ],
```

- [ ] **Step 4: Run the tests**

Run: `XDEBUG_MODE=off ./vendor/bin/phpunit tests/Feature/Enrichment/PerTenantShipmentWindowTest.php tests/Unit/Enrichment/MentionClassifierTest.php`
Expected: PASS (both files — unit tests keep the 60-day config default because no tenant context is bound).

- [ ] **Step 5: Gates + commit**

```bash
./vendor/bin/pint --dirty && XDEBUG_MODE=off ./vendor/bin/phpstan analyse
git add app/Platform/Enrichment/Attribution/MentionClassifier.php config/qds.php \
  tests/Feature/Enrichment/PerTenantShipmentWindowTest.php
git commit -m "feat(enrichment): per-tenant shipment attribution window (ADR-0025)"
```

---

### Task 6: engagement-trend formula in `DerivedMetricsService`

**Files:**
- Create: `app/Platform/Enrichment/Metrics/EngagementTrend.php`
- Modify: `app/Platform/Enrichment/Metrics/DerivedMetricsService.php` (class docblock lines 37–39, replace `engagementTrend()` lines 210–217, add `observedEngagement()`, add `use App\Modules\CRM\Models\Creator;`)
- Modify: `tests/Feature/Enrichment/MetricsAndReachTest.php` (replace `test_posting_frequency_and_engagement_trend_are_unavailable` lines 263–272, add trend tests)

**Interfaces:**
- Produces: `EngagementTrend` readonly DTO (`currentAverage: float`, `previousAverage: float`, `percentChange: int`, `currentCount: int`, `previousCount: int`) and `DerivedMetricsService::engagementTrend(Creator $creator, int $windowDays): ?EngagementTrend`. `postingFrequency()` is untouched (stays `null` — ADR-0024 leaves it undecided).

- [ ] **Step 1: Write the failing tests**

In `tests/Feature/Enrichment/MetricsAndReachTest.php`, REPLACE the test at lines 263–272 with (keep the surrounding section comments; add imports `use App\Models\Tenant;`, `use App\Modules\CRM\Models\Creator;` if missing — `PlatformAccount`, `ContentItem`, `MetricValue`, `MetricTier`, `CarbonImmutable` are already used in this file):

```php
    public function test_posting_frequency_stays_unavailable(): void
    {
        $account = PlatformAccount::factory()->create();

        // ADR-0024 explicitly leaves posting frequency undecided — NULL
        // (unavailable), never invented.
        $this->assertNull($this->metrics->postingFrequency(
            $account,
            CarbonImmutable::parse('2026-06-01'),
            CarbonImmutable::parse('2026-07-01'),
        ));
    }

    private function trendContent(PlatformAccount $account, int $daysAgo, ?float $likes, ?float $comments): ContentItem
    {
        $metrics = [];

        if ($likes !== null) {
            $metrics[] = new MetricValue($likes, MetricTier::Public, 'likes');
        }

        if ($comments !== null) {
            $metrics[] = new MetricValue($comments, MetricTier::Public, 'comments');
        }

        return ContentItem::factory()->create([
            'platform_account_id' => $account->id,
            'platform' => $account->platform,
            'published_at' => CarbonImmutable::now()->subDays($daysAgo),
            'public_metrics' => $metrics,
        ]);
    }

    public function test_engagement_trend_compares_the_two_rolling_windows(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-07-17 12:00:00'));

        $creator = Creator::factory()->create();
        $account = PlatformAccount::factory()->create(['creator_id' => $creator->id]);

        // Previous window (30–60 days ago): averages (80+120)/2 = 100.
        $this->trendContent($account, 45, likes: 70.0, comments: 10.0);
        $this->trendContent($account, 40, likes: 100.0, comments: 20.0);
        // Current window (0–30 days ago): averages (140+160)/2 = 150.
        $this->trendContent($account, 10, likes: 130.0, comments: 10.0);
        $this->trendContent($account, 5, likes: 150.0, comments: 10.0);
        // Excluded: neither likes nor comments observed (missing ≠ zero).
        $this->trendContent($account, 8, likes: null, comments: null);

        $trend = $this->metrics->engagementTrend($creator->fresh(), 30);

        $this->assertNotNull($trend);
        $this->assertEqualsWithDelta(150.0, $trend->currentAverage, 1e-9);
        $this->assertEqualsWithDelta(100.0, $trend->previousAverage, 1e-9);
        $this->assertSame(50, $trend->percentChange);
        $this->assertSame(2, $trend->currentCount);
        $this->assertSame(2, $trend->previousCount);
    }

    public function test_engagement_trend_counts_a_single_observed_component(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-07-17 12:00:00'));

        $creator = Creator::factory()->create();
        $account = PlatformAccount::factory()->create(['creator_id' => $creator->id]);

        $this->trendContent($account, 40, likes: 100.0, comments: null); // previous avg 100
        $this->trendContent($account, 10, likes: null, comments: 90.0);  // current avg 90

        $trend = $this->metrics->engagementTrend($creator->fresh(), 30);

        $this->assertNotNull($trend);
        $this->assertSame(-10, $trend->percentChange);
    }

    public function test_engagement_trend_is_unavailable_without_both_windows_or_with_zero_base(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-07-17 12:00:00'));

        $creator = Creator::factory()->create();
        $account = PlatformAccount::factory()->create(['creator_id' => $creator->id]);

        // Only current-window content → no comparison base.
        $this->trendContent($account, 10, likes: 100.0, comments: null);
        $this->assertNull($this->metrics->engagementTrend($creator->fresh(), 30));

        // Previous window exists but averages zero → division base is zero.
        $this->trendContent($account, 40, likes: 0.0, comments: null);
        $this->assertNull($this->metrics->engagementTrend($creator->fresh(), 30));
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `XDEBUG_MODE=off ./vendor/bin/phpunit tests/Feature/Enrichment/MetricsAndReachTest.php`
Expected: FAIL — `EngagementTrend` class missing / `engagementTrend()` signature mismatch.

- [ ] **Step 3: Create the DTO**

`app/Platform/Enrichment/Metrics/EngagementTrend.php`:

```php
<?php

namespace App\Platform\Enrichment\Metrics;

/**
 * MET-EngagementTrend (ADR-0024, tier DERIVED): average observed
 * likes + comments per post over the last N days vs the N days before,
 * as a whole signed percent. Exists only when BOTH windows contain
 * observed engagement and the previous average is non-zero.
 */
final readonly class EngagementTrend
{
    public function __construct(
        public float $currentAverage,
        public float $previousAverage,
        public int $percentChange,
        public int $currentCount,
        public int $previousCount,
    ) {}
}
```

- [ ] **Step 4: Implement the formula**

In `DerivedMetricsService`: add `use App\Modules\CRM\Models\Creator;`. Replace class-docblock lines 37–39 with:

```php
 * Engagement trend (MET-EngagementTrend) is canonical per ADR-0024: mean
 * observed likes+comments per post, last N days vs the N days before, as
 * a whole signed percent (N is the per-tenant trend window, ADR-0025).
 *
 * NO canonical formula exists for posting frequency (ADR-0024 explicitly
 * leaves it undecided) — that boundary returns NULL (unavailable); do not
 * invent one here.
```

Replace the `engagementTrend()` method (lines 210–217) with:

```php
    /**
     * ADR-0024: rolling-window engagement trend for a creator across all
     * their platform accounts. Content with NEITHER likes nor comments
     * observed is excluded (missing is never zero); a single observed
     * component counts as-is. NULL when either window has no included
     * content or the previous average is zero — never a fabricated figure.
     */
    public function engagementTrend(Creator $creator, int $windowDays): ?EngagementTrend
    {
        $now = CarbonImmutable::now();
        $currentStart = $now->subDays($windowDays);
        $previousStart = $now->subDays($windowDays * 2);

        $items = ContentItem::query()
            ->whereIn('platform_account_id', $creator->platformAccounts()->select('id'))
            ->where('published_at', '>=', $previousStart)
            ->where('published_at', '<', $now)
            ->get();

        $current = [];
        $previous = [];

        foreach ($items as $item) {
            $engagement = $this->observedEngagement($item);

            if ($engagement === null || $item->published_at === null) {
                continue;
            }

            if ($item->published_at->greaterThanOrEqualTo($currentStart)) {
                $current[] = $engagement;
            } else {
                $previous[] = $engagement;
            }
        }

        if ($current === [] || $previous === []) {
            return null;
        }

        $currentAverage = array_sum($current) / count($current);
        $previousAverage = array_sum($previous) / count($previous);

        if ($previousAverage <= 0.0) {
            return null;
        }

        return new EngagementTrend(
            currentAverage: $currentAverage,
            previousAverage: $previousAverage,
            percentChange: (int) round(($currentAverage - $previousAverage) / $previousAverage * 100),
            currentCount: count($current),
            previousCount: count($previous),
        );
    }

    /**
     * Sum of the OBSERVED likes/comments of one item; null when neither is
     * observed (such an item contributes nothing — missing is never zero).
     */
    private function observedEngagement(ContentItem $content): ?float
    {
        $likes = $this->publicAmount($content, 'likes');
        $comments = $this->publicAmount($content, 'comments');

        if ($likes === null && $comments === null) {
            return null;
        }

        return ($likes ?? 0.0) + ($comments ?? 0.0);
    }
```

- [ ] **Step 5: Run the tests**

Run: `XDEBUG_MODE=off ./vendor/bin/phpunit tests/Feature/Enrichment/MetricsAndReachTest.php`
Expected: PASS.

- [ ] **Step 6: Gates + commit**

```bash
./vendor/bin/pint --dirty && XDEBUG_MODE=off ./vendor/bin/phpstan analyse
git add app/Platform/Enrichment/Metrics/ tests/Feature/Enrichment/MetricsAndReachTest.php
git commit -m "feat(metrics): canonical engagement-trend formula (ADR-0024); posting frequency stays undecided"
```

---

### Task 7: engagement-trend tile on creator detail

**Files:**
- Modify: `app/Modules/Monitoring/Livewire/Dashboard/CreatorDetail.php` (`render()` signature + payload, docblock lines 26–28)
- Modify: `resources/views/livewire/monitoring/creator-detail.blade.php` (tile grid lines 145–159)
- Test: `tests/Feature/Monitoring/CreatorDetailTrendTest.php`

**Interfaces:**
- Consumes: `DerivedMetricsService::engagementTrend(Creator, int): ?EngagementTrend` (Task 6), `MonitoringSettingsResolver::engagementTrendWindowDays()` (Task 2).

- [ ] **Step 1: Write the failing test**

`tests/Feature/Monitoring/CreatorDetailTrendTest.php`:

```php
<?php

namespace Tests\Feature\Monitoring;

use App\Modules\CRM\Models\Creator;
use App\Modules\CRM\Models\PlatformAccount;
use App\Modules\Monitoring\Livewire\Dashboard\CreatorDetail;
use App\Modules\Monitoring\Models\ContentItem;
use App\Shared\Enums\MetricTier;
use App\Shared\Enums\RoleName;
use App\Shared\ValueObjects\MetricValue;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Creator detail shows the ADR-0024 engagement trend as a DERIVED tile —
 * signed percent when both windows have data, unavailable otherwise.
 */
class CreatorDetailTrendTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
    }

    private function post(PlatformAccount $account, int $daysAgo, float $likes): void
    {
        ContentItem::factory()->create([
            'platform_account_id' => $account->id,
            'platform' => $account->platform,
            'published_at' => CarbonImmutable::now()->subDays($daysAgo),
            'public_metrics' => [new MetricValue($likes, MetricTier::Public, 'likes')],
        ]);
    }

    public function test_trend_tile_shows_signed_percent_with_derived_badge(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-07-17 12:00:00'));

        $creator = Creator::factory()->create();
        $account = PlatformAccount::factory()->create(['creator_id' => $creator->id]);
        $this->post($account, 40, 100.0); // previous avg 100
        $this->post($account, 10, 150.0); // current avg 150 → +50%

        Livewire::actingAs($this->makeUser(RoleName::Analyst))
            ->test(CreatorDetail::class, ['creator' => $creator])
            ->assertSee('Engagement trend')
            ->assertSee('+50%')
            ->assertSee('last 30 days');
    }

    public function test_trend_tile_is_unavailable_without_enough_history(): void
    {
        $creator = Creator::factory()->create();
        PlatformAccount::factory()->create(['creator_id' => $creator->id]);

        Livewire::actingAs($this->makeUser(RoleName::Analyst))
            ->test(CreatorDetail::class, ['creator' => $creator])
            ->assertSee('Engagement trend')
            ->assertSee('Not enough posts in the two comparison windows yet.');
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `XDEBUG_MODE=off ./vendor/bin/phpunit tests/Feature/Monitoring/CreatorDetailTrendTest.php`
Expected: FAIL — page does not contain "Engagement trend".

- [ ] **Step 3: Compute the trend in the component**

In `CreatorDetail.php`: add imports `use App\Shared\Settings\MonitoringSettingsResolver;`. Change the `render()` signature to:

```php
    public function render(RollupReader $rollups, DerivedMetricsService $derived, MonitoringSettingsResolver $settings): View
```

Before the `return view(...)` add:

```php
        // ADR-0024 engagement trend: rolling N-day windows (per-tenant N,
        // ADR-0025) over the creator's observed likes+comments.
        $trendWindowDays = $settings->engagementTrendWindowDays();
```

and add to the view payload:

```php
            'engagementTrend' => $derived->engagementTrend($this->creator, $trendWindowDays),
            'trendWindowDays' => $trendWindowDays,
```

Update the component docblock lines 26–28: keep the posting-frequency sentence, and append: `Engagement trend is DERIVED per ADR-0024 (rolling window, ADR-0025 per-tenant length).`

- [ ] **Step 4: Add the tile**

In `resources/views/livewire/monitoring/creator-detail.blade.php` change line 146 from `sm:grid-cols-2` to `sm:grid-cols-2 lg:grid-cols-3`, and add a third tile after the "Median views" card (before the grid's closing `</div>` at line 159):

```blade
        <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
            <p class="text-sm text-gray-500 dark:text-gray-400">Engagement trend (last {{ $trendWindowDays }} days)</p>
            <div class="mt-2">
                @if ($engagementTrend !== null)
                    <div class="flex items-center gap-2">
                        <span class="text-lg font-semibold text-gray-800 dark:text-white/90">
                            {{ $engagementTrend->percentChange >= 0 ? '+' : '' }}{{ $engagementTrend->percentChange }}%
                        </span>
                        <x-metric.tier-badge tier="DERIVED" />
                    </div>
                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                        Average likes + comments per post vs the {{ $trendWindowDays }} days before
                        ({{ $engagementTrend->currentCount }} vs {{ $engagementTrend->previousCount }} posts).
                    </p>
                @else
                    <x-states.unavailable reason="Not enough posts in the two comparison windows yet." />
                @endif
            </div>
        </div>
```

- [ ] **Step 5: Run the tests**

Run: `XDEBUG_MODE=off ./vendor/bin/phpunit tests/Feature/Monitoring/CreatorDetailTrendTest.php tests/Feature/Monitoring/DashboardScreensTest.php`
Expected: PASS (existing dashboard screens tests unaffected).

- [ ] **Step 6: Gates + commit**

```bash
./vendor/bin/pint --dirty && XDEBUG_MODE=off ./vendor/bin/phpstan analyse
git add app/Modules/Monitoring/Livewire/Dashboard/CreatorDetail.php \
  resources/views/livewire/monitoring/creator-detail.blade.php \
  tests/Feature/Monitoring/CreatorDetailTrendTest.php
git commit -m "feat(monitoring): engagement-trend tile on creator detail (ADR-0024)"
```

---

### Task 8: persister exposes created content ids

**Files:**
- Modify: `app/Platform/Ingestion/Persistence/PersistenceResult.php`
- Modify: `app/Platform/Ingestion/Persistence/ContentItemPersister.php`
- Test: `tests/Feature/Ingestion/PersisterCreatedIdsTest.php`

**Interfaces:**
- Produces: `PersistenceResult::$createdIds` (`list<int>`, default `[]`) — the ids of ContentItem rows the persist call CREATED (never refreshed duplicates). All existing constructor call sites keep working (new optional named parameter, placed last).

- [ ] **Step 1: Write the failing test**

`tests/Feature/Ingestion/PersisterCreatedIdsTest.php`:

```php
<?php

namespace Tests\Feature\Ingestion;

use App\Modules\CRM\Models\PlatformAccount;
use App\Modules\Monitoring\Models\ContentItem;
use App\Platform\Ingestion\DTO\ContentData;
use App\Platform\Ingestion\Persistence\ContentItemPersister;
use App\Shared\Enums\Platform;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ADR-0023 (per-pull enrichment): the persister reports WHICH rows a
 * batch created — a metric refresh of an existing row must never look
 * like new content (it would re-bill recognition and append duplicate
 * EMV/reach results downstream).
 */
class PersisterCreatedIdsTest extends TestCase
{
    use RefreshDatabase;

    private function item(string $externalId): ContentData
    {
        $proto = ContentItem::factory()->make(['platform' => Platform::Instagram]);

        return new ContentData(
            platform: Platform::Instagram,
            externalId: $externalId,
            contentType: $proto->content_type,
            caption: 'hello',
            mediaUrls: [],
            publishedAt: CarbonImmutable::now()->subDay(),
            publicMetrics: [],
            provenance: $proto->provenance,
        );
    }

    public function test_created_rows_are_reported_and_refreshes_are_not(): void
    {
        $account = PlatformAccount::factory()->create(['platform' => Platform::Instagram]);
        $persister = app(ContentItemPersister::class);

        $first = $persister->persist($account, [$this->item('per-pull-1'), $this->item('per-pull-2')]);

        $this->assertCount(2, $first->createdIds);
        $this->assertSame(2, $first->created);
        $this->assertEqualsCanonicalizing(
            ContentItem::query()->withoutGlobalScopes()->pluck('id')->all(),
            $first->createdIds,
        );

        // Re-seeing the same records refreshes them — created ids stay empty.
        $second = $persister->persist($account, [$this->item('per-pull-1'), $this->item('per-pull-2')]);

        $this->assertSame([], $second->createdIds);
        $this->assertSame(2, $second->duplicates);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `XDEBUG_MODE=off ./vendor/bin/phpunit tests/Feature/Ingestion/PersisterCreatedIdsTest.php`
Expected: FAIL — unknown property `createdIds`.

- [ ] **Step 3: Add the field and collect ids**

`PersistenceResult.php` — add a final constructor parameter:

```php
        public float $mediaMs = 0.0,
        /** @var list<int> Ids of newly created ContentItem rows (ADR-0023 per-pull enrichment). */
        public array $createdIds = [],
```

`ContentItemPersister.php` — in `persist()`: initialize `$createdIds = [];` next to the counters (line ~32); in the created branch after `$contentItem->save();` (line 66) add `$createdIds[] = (int) $contentItem->id;`; add `createdIds: $createdIds,` to the returned `PersistenceResult` (line ~93).

- [ ] **Step 4: Run the tests**

Run: `XDEBUG_MODE=off ./vendor/bin/phpunit tests/Feature/Ingestion/`
Expected: PASS (whole ingestion suite — the optional parameter breaks no other construction site).

- [ ] **Step 5: Gates + commit**

```bash
./vendor/bin/pint --dirty && XDEBUG_MODE=off ./vendor/bin/phpstan analyse
git add app/Platform/Ingestion/Persistence/ tests/Feature/Ingestion/PersisterCreatedIdsTest.php
git commit -m "feat(ingestion): persister reports created content ids (ADR-0023)"
```

---

### Task 9: per-pull enrichment dispatch

**Files:**
- Create: `app/Platform/Enrichment/PerPullEnrichmentDispatcher.php`
- Modify: `app/Platform/Ingestion/Jobs/IngestContentJob.php` (after `recordCompletion`, line ~144)
- Modify: `app/Platform/Ingestion/Jobs/ArchiveStoryMediaJob.php` (after the success path, line ~112)
- Modify: `config/qds.php` (enrichment header comment lines 240–244 + sweep comment 249–250), `routes/console.php` (sweep comment lines 31–33)
- Test: `tests/Feature/Enrichment/PerPullEnrichmentTest.php`

**Interfaces:**
- Consumes: `PersistenceResult::$createdIds` (Task 8), `EnrichContentItemJob::__construct(int $contentItemId, string $correlationId)`, `EnrichStoryJob::__construct(int $storyId, string $correlationId)`.
- Produces: `PerPullEnrichmentDispatcher::dispatchForContent(array $createdIds, string $correlationId): void` and `dispatchForStory(int $storyId, string $correlationId): void` — both self-gate on `qds.enrichment.enabled`.

- [ ] **Step 1: Write the failing test**

`tests/Feature/Enrichment/PerPullEnrichmentTest.php`:

```php
<?php

namespace Tests\Feature\Enrichment;

use App\Modules\CRM\Models\PlatformAccount;
use App\Modules\Monitoring\Models\ContentItem;
use App\Modules\Monitoring\Models\Story;
use App\Platform\Enrichment\Jobs\EnrichContentItemJob;
use App\Platform\Enrichment\Jobs\EnrichStoryJob;
use App\Platform\Enrichment\PerPullEnrichmentDispatcher;
use App\Platform\Ingestion\Jobs\ArchiveStoryMediaJob;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * ADR-0023: the AI check follows the data pull. New content enriches right
 * away (inside the eligibility window, kill switch respected); stories
 * enrich only after their media archive lands; the sweep stays the backstop.
 */
class PerPullEnrichmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        config(['qds.enrichment.enabled' => true]);
    }

    private function content(int $publishedDaysAgo): ContentItem
    {
        return ContentItem::factory()->create([
            'published_at' => CarbonImmutable::now()->subDays($publishedDaysAgo),
        ]);
    }

    public function test_created_content_inside_the_window_is_dispatched(): void
    {
        $fresh = $this->content(publishedDaysAgo: 2);

        app(PerPullEnrichmentDispatcher::class)->dispatchForContent([$fresh->id], 'corr-1');

        Queue::assertPushed(EnrichContentItemJob::class, fn (EnrichContentItemJob $job): bool => $job->contentItemId === $fresh->id && $job->correlationId === 'corr-1');
    }

    public function test_old_backfilled_content_outside_the_window_is_not_dispatched(): void
    {
        $old = $this->content(publishedDaysAgo: 90); // > content_window_days (30)

        app(PerPullEnrichmentDispatcher::class)->dispatchForContent([$old->id], 'corr-2');

        Queue::assertNotPushed(EnrichContentItemJob::class);
    }

    public function test_kill_switch_off_dispatches_nothing(): void
    {
        config(['qds.enrichment.enabled' => false]);
        $fresh = $this->content(publishedDaysAgo: 2);

        $dispatcher = app(PerPullEnrichmentDispatcher::class);
        $dispatcher->dispatchForContent([$fresh->id], 'corr-3');
        $dispatcher->dispatchForStory(1, 'corr-3');

        Queue::assertNotPushed(EnrichContentItemJob::class);
        Queue::assertNotPushed(EnrichStoryJob::class);
    }

    public function test_story_archive_success_dispatches_story_enrichment(): void
    {
        Storage::fake('media');
        Http::fake(['*' => Http::response('BYTES', 200, ['Content-Type' => 'image/jpeg'])]);

        $account = PlatformAccount::factory()->create();
        $story = Story::factory()->create([
            'platform_account_id' => $account->id,
            'media_url' => null,
        ]);

        (new ArchiveStoryMediaJob($story->id, 'https://cdn.example/story.jpg', 'corr-4'))->handle();

        $this->assertNotNull($story->refresh()->media_url);
        Queue::assertPushed(EnrichStoryJob::class, fn (EnrichStoryJob $job): bool => $job->storyId === $story->id && $job->correlationId === 'corr-4');
    }

    public function test_failed_story_archive_dispatches_no_enrichment(): void
    {
        Storage::fake('media');
        Http::fake(['*' => Http::response('gone', 404)]);

        $account = PlatformAccount::factory()->create();
        $story = Story::factory()->create([
            'platform_account_id' => $account->id,
            'media_url' => null,
        ]);

        (new ArchiveStoryMediaJob($story->id, 'https://cdn.example/story.jpg', 'corr-5'))->handle();

        Queue::assertNotPushed(EnrichStoryJob::class);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `XDEBUG_MODE=off ./vendor/bin/phpunit tests/Feature/Enrichment/PerPullEnrichmentTest.php`
Expected: FAIL — `PerPullEnrichmentDispatcher` not found.

- [ ] **Step 3: Create the dispatcher**

`app/Platform/Enrichment/PerPullEnrichmentDispatcher.php`:

```php
<?php

namespace App\Platform\Enrichment;

use App\Modules\Monitoring\Models\ContentItem;
use App\Platform\Enrichment\Jobs\EnrichContentItemJob;
use App\Platform\Enrichment\Jobs\EnrichStoryJob;
use Carbon\CarbonImmutable;

/**
 * ADR-0023: enrichment follows the data pull. Ingestion calls this with
 * the rows it CREATED (metric refreshes never re-trigger — EMV/reach
 * results are append-only and recognition re-bills). The recurring sweep
 * (qds:run-enrichment) stays scheduled as the recovery backstop: its
 * RUNNING/COMPLETED predicate makes it a no-op for anything enriched here.
 *
 * Every dispatch honours the qds.enrichment.enabled kill switch, and
 * content honours the sweep's eligibility window so deep backfills of old
 * posts cannot trigger surprise recognition cost.
 */
class PerPullEnrichmentDispatcher
{
    /** @param list<int> $createdIds */
    public function dispatchForContent(array $createdIds, string $correlationId): void
    {
        if ($createdIds === [] || ! config('qds.enrichment.enabled')) {
            return;
        }

        $windowDays = max(1, (int) config('qds.enrichment.content_window_days'));

        $eligibleIds = ContentItem::query()
            ->withoutGlobalScopes()
            ->whereIn('id', $createdIds)
            ->where('published_at', '>=', CarbonImmutable::now()->subDays($windowDays))
            ->pluck('id');

        foreach ($eligibleIds as $id) {
            EnrichContentItemJob::dispatch((int) $id, $correlationId);
        }
    }

    /** Stories enrich only AFTER their media archive lands (recognition needs the file). */
    public function dispatchForStory(int $storyId, string $correlationId): void
    {
        if (! config('qds.enrichment.enabled')) {
            return;
        }

        EnrichStoryJob::dispatch($storyId, $correlationId);
    }
}
```

- [ ] **Step 4: Wire the two dispatch sites**

`IngestContentJob.php` — add import `use App\Platform\Enrichment\PerPullEnrichmentDispatcher;`, then directly after `$recorder->recordCompletion($context, $batch, $result);` (line ~144) add:

```php
            // ADR-0023: the AI check follows the pull — newly created rows
            // only (refreshed duplicates never re-enrich).
            app(PerPullEnrichmentDispatcher::class)
                ->dispatchForContent($result->createdIds, $this->correlationId);
```

`ArchiveStoryMediaJob.php` — add the same import, then directly after `$this->recordArchival($story, $startedAt, $startedMono, CallOutcome::Success, null, strlen($response->body()));` (line ~112) add:

```php
        // ADR-0023: story enrichment follows the successful archive —
        // recognition needs the stored media file.
        app(PerPullEnrichmentDispatcher::class)
            ->dispatchForStory((int) $story->id, $this->correlationId);
```

- [ ] **Step 5: Reword the sweep comments (decided by ADR-0023)**

`config/qds.php` lines 240–244, replace the header sentence "Enrichment cadence is NOT canonically decided … until an ADR fixes it." with:

```php
    | Runtime toggles for the enrichment pipeline. Enrichment is dispatched
    | per data pull (ADR-0023); the recurring sweep below is the recovery
    | BACKSTOP for crashed/reaped runs, not the primary trigger. Recognition
    | providers are the frozen SRC-google-* contracts; a provider with no
    | credentials is skipped and its outputs stay unavailable (never fabricated).
```

and the sweep entry comment (line ~249) to:

```php
        // Recovery backstop sweep (ADR-0023): re-collects targets whose
        // per-pull run crashed or was reaped; a no-op for anything already
        // RUNNING/COMPLETED.
```

`routes/console.php` lines 31–33, replace the comment with:

```php
// SVC-EnrichmentAI — recovery backstop sweep (ADR-0023: enrichment is
// dispatched per data pull; this recurring sweep only catches targets
// whose run crashed or was reaped).
```

- [ ] **Step 6: Run the tests**

Run: `XDEBUG_MODE=off ./vendor/bin/phpunit tests/Feature/Enrichment/ tests/Feature/Ingestion/`
Expected: PASS (both suites — existing ingestion tests are unaffected because the flag defaults to false in tests unless set).

- [ ] **Step 7: Gates + commit**

```bash
./vendor/bin/pint --dirty && XDEBUG_MODE=off ./vendor/bin/phpstan analyse
git add app/Platform/Enrichment/PerPullEnrichmentDispatcher.php \
  app/Platform/Ingestion/Jobs/IngestContentJob.php app/Platform/Ingestion/Jobs/ArchiveStoryMediaJob.php \
  config/qds.php routes/console.php tests/Feature/Enrichment/PerPullEnrichmentTest.php
git commit -m "feat(enrichment): per-pull dispatch — content on create, stories after archive; sweep is backstop (ADR-0023)"
```

---

### Task 10: ADR-0023..0026 + doc reconciliation

**Files:**
- Modify: `docs/05-decisions/decision-log.md` (header line 19, index table after the ADR-0022 row line 56, four bodies appended after the ADR-0022 body ~line 571)
- Modify: `docs/80-delivery/00-roadmap.md` (post-P1 TODO #1 lines ~435–446, TODO #3 lines ~457–463)
- Modify: `docs/00-meta/03-glossary.md` (ENUM-MentionType PAID row, ~line 385–390)
- Modify: `config/qds.php` (confidence comment lines 272–275)
- Modify: `app/Platform/Enrichment/Support/ConfidenceScore.php` (docblock "until an ADR fixes them")

**Interfaces:** none (documentation).

- [ ] **Step 1: Decision-log header and index rows**

Line 19: change "All twenty-two ADRs (`ADR-0001` .. `ADR-0022`) are `APPROVED`." to "All twenty-six ADRs (`ADR-0001` .. `ADR-0026`) are `APPROVED`."

After the ADR-0022 index row (line 56) add four rows:

```markdown
| [ADR-0023](#adr-0023) | Enrichment triggers per data pull; sweep demoted to backstop | APPROVED | The AI-enrichment pass for a new ContentItem is dispatched by ingestion at persist time (created rows only, inside the eligibility window, behind the kill switch); a story's pass is dispatched after its media archive lands. The recurring sweep stays scheduled as the recovery backstop for crashed/reaped runs. Closes the flagged "enrichment sweep cadence" gap left open by ADR-0017. |
| [ADR-0024](#adr-0024) | Engagement-trend formula; posting frequency stays undecided | APPROVED | MET-EngagementTrend is canonical: mean observed likes+comments per post over the last N days vs the N days before, as a whole signed percent, tier DERIVED; NULL (unavailable) without both windows or with a zero base. N is per-tenant (default 30, ADR-0025). Rolling windows cannot be served by calendar-grain rollups, so the creator page computes this live over ContentItem.public_metrics — a recorded exception to ADR-0010. Posting frequency remains explicitly undecided and hidden. |
| [ADR-0025](#adr-0025) | Per-tenant monitoring settings & retention policy | APPROVED | New append-only `monitoring_settings` table (latest row per tenant wins) + Settings → Monitoring page (`monitoring-settings.manage`, ADMIN): gift-link/shipment attribution window (default 60d), engagement-trend window (default 30d), story-media keep-time (default 180d, 0 = keep forever), communication-log keep-time (default 0 = keep forever). Reads go through a context-safe resolver (config fallback; tenant-less reads NEVER see another tenant's row); retention prune commands iterate tenants with explicit ownership predicates. Closes the flagged shipment-window gap and the two P4-review retention ADR candidates. |
| [ADR-0026](#adr-0026) | Operational confirmations: confidence cut-points, PAID, sentiment | APPROVED | Product-owner confirmations (2026-07-17): the enrichment confidence cut-points 0.85/0.60 are canonical (env-tunable); `PAID` stays in ENUM-MentionType as an inert compatibility value — only ever asserted from a platform paid-partnership label, never inferred (resolves roadmap post-P1 TODO #1); sentiment (REQ-M1-009) deliberately remains Unavailable — no NLP model is chosen; choosing one is a future ADR. |
```

- [ ] **Step 2: Append the four ADR bodies**

After the end of the ADR-0022 body (the "**Still deferred (do not build ahead):**" bullet, ~line 571) append:

```markdown

<a id="adr-0023"></a>
## ADR-0023 — Enrichment triggers per data pull; sweep demoted to backstop

**Context.**

Since P1, the ONLY enrichment trigger was the recurring sweep (`qds:run-enrichment`), and its cadence was explicitly flagged "NOT canonically decided" — [ADR-0017](#adr-0017) closed the ingestion-polling cadence gap but left enrichment cadence open. Product-owner decision (2026-07-17): the AI check follows the data pull — there is no separate timer to tune.

**Decision.**

1. **Content:** when an ingestion pull persists a batch, the persister reports the rows it CREATED and ingestion dispatches one enrichment job per created row — gated on `qds.enrichment.enabled` and on the sweep's `content_window_days` eligibility window (deep backfills of old posts never trigger recognition cost). Metric refreshes of existing rows NEVER re-trigger enrichment: EMV/reach results are append-only and recognition calls re-bill.
2. **Stories:** the enrichment job is dispatched after the story's media archive succeeds (recognition needs the stored file); stories without media are left to the backstop sweep.
3. **The sweep stays scheduled as recovery backstop.** Its RUNNING/COMPLETED eligibility predicate makes it a no-op for per-pull-enriched targets; it re-collects targets whose run crashed or was reaped (stale-run reaper). Its cron is an operational knob of the backstop, not a product cadence.
4. The `qds.enrichment.enabled` kill switch gates every dispatch site (per-pull and sweep alike).

**Status.** APPROVED ([`ENUM-DocStatus`](../00-meta/03-glossary.md#enum-docstatus)).

**Consequences.**

- Closes the flagged "enrichment sweep cadence" missing decision (config/qds.php and routes/console.php comments are reconciled).
- Enrichment latency now tracks ingestion cadence; recognition cost per pull is bounded by created-rows × window eligibility.
- Failure recovery is unchanged: failed/reaped runs re-enter through the backstop sweep.

<a id="adr-0024"></a>
## ADR-0024 — Engagement-trend formula; posting frequency stays undecided

**Context.**

`DerivedMetricsService::engagementTrend()` and `postingFrequency()` were honest NULL boundaries — "NO canonical formula exists … do not invent one here." Product-owner decision (2026-07-17): show the engagement trend with a defined formula and a configurable window; keep posting frequency hidden.

**Decision.**

1. **MET-EngagementTrend (DERIVED):** for a creator across their platform accounts, mean observed `likes + comments` per ContentItem published in the last N days vs the N days before, reported as a whole signed percent change. An item with NEITHER metric observed is excluded (missing is never zero); a single observed component counts as-is.
2. **Unavailable, never fabricated:** NULL when either window has no included content or the previous average is zero.
3. **N is per-tenant** — Settings → Monitoring, default 30 ([ADR-0025](#adr-0025)).
4. **Read-path exception to [ADR-0010](#adr-0010):** rolling last-N-day windows cannot be served by the calendar-grain rollup matviews; the creator page computes the trend live over `ContentItem.public_metrics`, following the pre-existing sanctioned precedent of the page's average/median views.
5. **Posting frequency remains undecided** — the NULL boundary and its Unavailable surfaces stay.

**Status.** APPROVED ([`ENUM-DocStatus`](../00-meta/03-glossary.md#enum-docstatus)).

**Consequences.**

- Creator detail gains an "Engagement trend" DERIVED tile; exports/lists/analytics schema are unchanged in this iteration.
- The metrics catalog measure named without a formula (engagement trend) now has one; `posting_frequency` stays NULL end-to-end.

<a id="adr-0025"></a>
## ADR-0025 — Per-tenant monitoring settings & retention policy

**Context.**

Four operational values were global env-config flagged "NOT canonically decided": the shipment attribution window (60d), story-media retention (180d), communication-log retention (0), and (new with [ADR-0024](#adr-0024)) the trend window. The P4 review listed the two retention periods as ADR candidates. Product-owner decision (2026-07-17): make all four per-tenant, user-editable settings with the current values as defaults; message history keeps forever by default.

**Decision.**

1. **Storage:** append-only `monitoring_settings` (NOT NULL `tenant_id`, latest row per tenant wins, `updated_by` audit, DB CHECK ranges). Saves insert new rows — history is never edited.
2. **Page:** Settings → Monitoring (staff view via `settings.view`; saving via new ADMIN permission `monitoring-settings.manage`), four plain-language cards. Retention cards expose an on/off toggle where OFF stores 0 = keep forever; the attribution and trend windows have no off-state.
3. **Reads:** only through `MonitoringSettingsResolver` — active-context reads resolve the tenant's latest row; tenant-less reads return the config default and NEVER another tenant's row (the `MonitoringPlanSetting::current()` cross-tenant limitation must not be repeated). Explicit `…For(tenantId)` getters serve tenant-less schedulers.
4. **Retention enforcement is per-tenant:** the story-media and communication-log prune commands iterate tenants, resolve each tenant's keep-time (0 skips), and delete with explicit `tenant_id` predicates.
5. **Canonical defaults:** shipment window 60 days; trend window 30 days; story media 180 days; communication logs 0 (keep forever). The env values remain as fallbacks for tenants that never saved settings.

**Status.** APPROVED ([`ENUM-DocStatus`](../00-meta/03-glossary.md#enum-docstatus)).

**Consequences.**

- Closes the flagged shipment-window gap and the P4-review retention ADR candidates; the config comments are reconciled to "DEFAULT/fallback" semantics.
- `MentionClassifier` resolves its window per tenant at classification time; the same evidence may classify differently across tenants by design.
- New operational register `monitoring_settings` (tenant-owned, append-only) joins the data model's operational-registers section.

<a id="adr-0026"></a>
## ADR-0026 — Operational confirmations: confidence cut-points, PAID, sentiment

**Context.**

Three long-flagged open decisions needed an owner call, gathered 2026-07-17.

**Decision.**

1. **Confidence cut-points are canonical at 0.85 / 0.60** (provider score ≥ 0.85 → HIGH; ≥ 0.60 → MEDIUM; else LOW → review per DP-004). They stay env-tunable (`QDS_ENRICHMENT_CONFIDENCE_*`) as operational calibration, no longer a missing decision.
2. **`PAID` stays in [`ENUM-MentionType`](../00-meta/03-glossary.md#enum-mentiontype) as an inert compatibility value.** QDS works with unpaid organic seeding only; `PAID` is asserted exclusively from a platform paid-partnership label (AC-M1-003) and never inferred. It is kept for possible future use — resolving roadmap post-P1 TODO #1 with the "keep inert" option.
3. **Sentiment (REQ-M1-009) deliberately remains Unavailable.** No NLP model/provider is chosen; the `UnavailableSentimentClassifier` binding stands. Choosing a model is a future ADR — sentiment is "off for now" by product decision, not an oversight.

**Status.** APPROVED ([`ENUM-DocStatus`](../00-meta/03-glossary.md#enum-docstatus)).

**Consequences.**

- ConfidenceScore/config comments drop the "flagged missing decision" wording and cite this ADR.
- The glossary PAID row gains the inert-compatibility note; AC-M1-002/003 stand unchanged.
- Sentiment surfaces keep rendering "unavailable" honestly until a model ADR supersedes point 3.
```

- [ ] **Step 2b: Reconcile the flagged comments**

`config/qds.php` lines 272–275, replace the confidence comment with:

```php
        // Numeric provider score → ENUM-ConfidenceLevel bucketing
        // (ADR-0026): score >= high → HIGH; >= medium → MEDIUM; else LOW
        // (LOW routes to review per DP-004). Env-tunable calibration.
```

`app/Platform/Enrichment/Support/ConfidenceScore.php` — in the class docblock, replace the "NOT canonically decided … until an ADR fixes them." sentence with: `Cut-points are canonical per ADR-0026 (0.85 / 0.60), env-tunable as operational calibration.`

`docs/80-delivery/00-roadmap.md`:
- TODO #1 (lines ~435–446): replace the item's body with a resolution note: `**Resolved by [ADR-0026](../05-decisions/decision-log.md#adr-0026)** — PAID is kept as an inert compatibility value, asserted only from a platform paid-partnership label, never inferred.` (keep the heading/number).
- TODO #3 (lines ~457–463): append at the end: `**Delivered** — the stale-run reaper ships in the data-quality monitor (P4), and [ADR-0023](../05-decisions/decision-log.md#adr-0023) makes the sweep the recovery backstop it feeds.`

`docs/00-meta/03-glossary.md` ENUM-MentionType `PAID` row: append to its description: ` Kept as an inert compatibility value per [ADR-0026](../05-decisions/decision-log.md#adr-0026): asserted only from a platform paid-partnership label, never inferred.`

- [ ] **Step 3: Verify nothing broke**

Run: `XDEBUG_MODE=off ./vendor/bin/phpunit tests/Feature/Enrichment/ tests/Feature/Settings/ && ./vendor/bin/pint --dirty && XDEBUG_MODE=off ./vendor/bin/phpstan analyse`
Expected: PASS / clean (docs-only changes plus two comment edits).

- [ ] **Step 4: Commit**

```bash
git add docs/05-decisions/decision-log.md docs/80-delivery/00-roadmap.md docs/00-meta/03-glossary.md \
  config/qds.php app/Platform/Enrichment/Support/ConfidenceScore.php
git commit -m "docs(adr): ADR-0023..0026 — per-pull enrichment, trend formula, tenant settings, operational confirmations"
```

---

### Task 11: full gates + review handoff

**Files:**
- Create: `reviews/REVIEW-monitoring-settings-2026-07-17.md`

- [ ] **Step 1: Run the full suite and gates**

Run: `XDEBUG_MODE=off ./vendor/bin/phpunit`
Expected: PASS — ~939 pre-existing tests plus the ~25 new ones, 0 failures.

Run: `XDEBUG_MODE=off ./vendor/bin/phpstan analyse && ./vendor/bin/pint --test`
Expected: clean.

- [ ] **Step 2: Write the review handoff**

`reviews/REVIEW-monitoring-settings-2026-07-17.md` — follow `reviews/_TEMPLATE.md` conventions (status `PENDING_REVIEW`; do NOT self-review). Contents: scope = this plan's 10 implementation tasks (list the commits); spec + plan paths; verification checklist for the reviewer covering at minimum:

```markdown
# REVIEW — Per-tenant monitoring settings, engagement trend, per-pull enrichment (ADR-0023..0026)

**Status:** PENDING_REVIEW · **Date:** 2026-07-17 · **Branch:** feat/crm-ux-stage-a
**Spec:** docs/superpowers/specs/2026-07-17-monitoring-settings-and-decisions-design.md
**Plan:** docs/superpowers/plans/2026-07-17-monitoring-settings-and-decisions.md

## Review checklist (for a SEPARATE review model — no self-review)

- [ ] Cross-tenant: monitoring_settings reads/writes cannot leak between tenants (resolver context mode, explicit …For() mode, page save under HTTP context, prune loops' explicit predicates).
- [ ] The resolver's no-context path NEVER queries another tenant's row (contrast MonitoringPlanSetting::current()).
- [ ] Per-pull dispatch fires ONLY for created rows; metric refreshes / RefreshCampaignContentJob updates cannot re-enrich (append-only EmvResult/ReachResult, recognition re-billing).
- [ ] Kill switch: every new dispatch site is gated on qds.enrichment.enabled.
- [ ] Story enrichment ordering: dispatched only after successful archive; failed/expired archives dispatch nothing; sweep still covers media-less stories.
- [ ] Trend math: window boundaries (2N fetch, N split), exclusion of no-metric items, single-component counting, zero/empty previous window → unavailable; DERIVED badge on the tile.
- [ ] Retention semantics: 0 = keep forever preserved end-to-end (page toggle → row → resolver → commands); DB CHECK ranges match page validation.
- [ ] Permission surface: monitoring-settings.manage is ADMIN-only; page view stays settings.view; non-admin save is Forbidden.
- [ ] ADR texts match the shipped behaviour; reconciled comments/docs contain no leftover "NOT canonically decided" wording for the four decided items.
- [ ] Test quality: new tests assert behaviour (not implementation), and the full suite is green.
```

- [ ] **Step 3: Commit**

```bash
git add reviews/REVIEW-monitoring-settings-2026-07-17.md
git commit -m "docs(review): PENDING_REVIEW handoff for monitoring settings / trend / per-pull enrichment"
```

---

## Plan self-review notes (already applied)

- **Spec coverage:** §1–2 → Tasks 1–3; §3 → Task 4; §4 → Task 5; §5 → Tasks 6–7; §6 → Tasks 8–9; §7 → Task 10; §8 → Task 11. The spec's "reuse the ingestion correlation id" is satisfied — both wired sites pass `$this->correlationId`.
- **Type consistency:** `MonitoringSettingsResolver` names match across Tasks 2/4/5/7; `EngagementTrend` fields match between Tasks 6/7; `createdIds` matches between Tasks 8/9; `EnrichContentItemJob(int, string)` / `EnrichStoryJob(int, string)` ctors verified against source.
- **Known flex points for executors:** factory tenant mechanics in `PerTenantRetentionTest` (note in Task 4 Step 1); if `x-form.toggle`'s `:disabled` prop differs, mirror the exact usage in `reach-settings.blade.php:49`.
