# Monitoring "Active Seeding Only" Filter Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add an "Active seeding only" toggle to `/monitoring` that re-scopes the page's creator-keyed cards to creators enrolled in ACTIVE/SHIPPING seeding campaigns.

**Architecture:** A CRM-owned resolver (`ActiveSeedingCreatorIds`) turns "active seeding" into a tenant-scoped creator-ID array once per render; the Livewire component (`MonitoringOverview`) threads that array into five card queries via `whereIn`/`whereHas`, gated on the toggle boolean (never on array truthiness). Reach/EMV cards (brand-keyed rollup, no creator dimension) show an explanatory unavailable state instead.

**Tech Stack:** Laravel 12, Livewire 3 (`#[Url]` attributes), PostgreSQL (materialized-view rollups), PHPUnit.

**Spec:** `docs/superpowers/specs/2026-07-17-monitoring-active-seeding-filter-design.md`

## Global Constraints

- **DP-001:** an unmeasured aggregate comes back `null`, never a fabricated zero. `creatorTotals()` with an empty ID array returns `null` sums (SQL-natural); the tiles render their existing unavailable state.
- **ADR-0010:** KPI aggregates read materialized rollups via `RollupReader` only — never live OLTP aggregation.
- **ADR-0019 tenancy:** the resolver goes through Eloquent on `SeedingCampaign` (has `BelongsToTenant`); never raw-query the pivot without a tenant predicate.
- **Empty-array guard:** every card gates scoping on `$this->activeSeedingOnly` (boolean), never `->when($creatorIds, …)` — an empty array is falsy in PHP and would silently disable the filter.
- **"Active" definition:** `SeedingCampaignStatus::Active` + `SeedingCampaignStatus::Shipping`, owned solely by `ActiveSeedingCreatorIds::ACTIVE_STATUSES`.
- **No new permission:** `monitoring.view` (route) + `viewAny Mention` (mount) still gate everything.
- **Tests:** run with `XDEBUG_MODE=off php artisan test <path>` (project convention).
- **Git:** the branch (`feat/reach-settings`) has unrelated uncommitted reach-settings work. `git add` ONLY the exact files listed in each commit step — never `git add -A`.

---

### Task 1: `ActiveSeedingCreatorIds` resolver + single ownership of the "active" definition

**Files:**
- Create: `app/Modules/CRM/Services/ActiveSeedingCreatorIds.php`
- Modify: `app/Modules/Monitoring/Livewire/Dashboard/HomeOverview.php:51-56`
- Test: `tests/Feature/Crm/ActiveSeedingCreatorIdsTest.php`

**Interfaces:**
- Consumes: `SeedingCampaign` model (`BelongsToTenant`, `creators()` belongsToMany stamping pivot `tenant_id`), `SeedingCampaignStatus` enum.
- Produces: `ActiveSeedingCreatorIds::ACTIVE_STATUSES` (`list<SeedingCampaignStatus>`), `ActiveSeedingCreatorIds::statusValues(): list<string>`, `forCurrentTenant(): list<int>` (distinct creator IDs, current tenant). Task 4 calls `app(ActiveSeedingCreatorIds::class)->forCurrentTenant()`.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/Crm/ActiveSeedingCreatorIdsTest.php`:

```php
<?php

namespace Tests\Feature\Crm;

use App\Modules\CRM\Models\Creator;
use App\Modules\CRM\Models\SeedingCampaign;
use App\Modules\CRM\Services\ActiveSeedingCreatorIds;
use App\Shared\Enums\SeedingCampaignStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ActiveSeedingCreatorIds — the single owner of the "active seeding"
 * definition (ACTIVE + SHIPPING) behind the monitoring "Active seeding
 * only" filter. Tenant-scoped via SeedingCampaign's BelongsToTenant.
 */
class ActiveSeedingCreatorIdsTest extends TestCase
{
    use RefreshDatabase;

    private function runWithCreators(SeedingCampaignStatus $status, Creator ...$creators): SeedingCampaign
    {
        $run = SeedingCampaign::factory()->create(['status' => $status]);
        $run->creators()->attach(array_map(fn (Creator $c): int => $c->id, $creators));

        return $run;
    }

    public function test_returns_only_creators_of_active_and_shipping_campaigns(): void
    {
        $active = Creator::factory()->create();
        $shipping = Creator::factory()->create();
        $this->runWithCreators(SeedingCampaignStatus::Active, $active);
        $this->runWithCreators(SeedingCampaignStatus::Shipping, $shipping);

        // Every OTHER status must be excluded — iterate the enum, no hardcoded list.
        foreach (SeedingCampaignStatus::cases() as $status) {
            if (in_array($status, ActiveSeedingCreatorIds::ACTIVE_STATUSES, true)) {
                continue;
            }
            $this->runWithCreators($status, Creator::factory()->create());
        }

        $ids = app(ActiveSeedingCreatorIds::class)->forCurrentTenant();

        $this->assertEqualsCanonicalizing([$active->id, $shipping->id], $ids);
    }

    public function test_deduplicates_a_creator_enrolled_in_two_active_campaigns(): void
    {
        $creator = Creator::factory()->create();
        $this->runWithCreators(SeedingCampaignStatus::Active, $creator);
        $this->runWithCreators(SeedingCampaignStatus::Shipping, $creator);

        $this->assertSame([$creator->id], app(ActiveSeedingCreatorIds::class)->forCurrentTenant());
    }

    public function test_scoped_to_the_current_tenant(): void
    {
        $tenantB = $this->makeTenant('Tenant B');
        $this->withTenant($tenantB, function (): void {
            $this->runWithCreators(SeedingCampaignStatus::Active, Creator::factory()->create());
        });

        $this->assertSame([], app(ActiveSeedingCreatorIds::class)->forCurrentTenant());
    }

    public function test_returns_empty_array_when_nothing_is_active(): void
    {
        $this->runWithCreators(SeedingCampaignStatus::Completed, Creator::factory()->create());

        $this->assertSame([], app(ActiveSeedingCreatorIds::class)->forCurrentTenant());
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `XDEBUG_MODE=off php artisan test tests/Feature/Crm/ActiveSeedingCreatorIdsTest.php`
Expected: FAIL — `Class "App\Modules\CRM\Services\ActiveSeedingCreatorIds" not found`.

- [ ] **Step 3: Write the resolver**

Create `app/Modules/CRM/Services/ActiveSeedingCreatorIds.php`:

```php
<?php

namespace App\Modules\CRM\Services;

use App\Modules\CRM\Models\SeedingCampaign;
use App\Shared\Enums\SeedingCampaignStatus;

/**
 * Creators enrolled in a currently running seeding — the single owner of
 * the "active seeding" definition (ACTIVE + SHIPPING) shared by the home
 * dashboard tile and the monitoring "Active seeding only" filter.
 * Tenant-scoped via SeedingCampaign's BelongsToTenant (ADR-0019).
 */
class ActiveSeedingCreatorIds
{
    /** @var list<SeedingCampaignStatus> */
    public const ACTIVE_STATUSES = [
        SeedingCampaignStatus::Active,
        SeedingCampaignStatus::Shipping,
    ];

    /** @return list<string> */
    public static function statusValues(): array
    {
        return array_map(
            fn (SeedingCampaignStatus $status): string => $status->value,
            self::ACTIVE_STATUSES,
        );
    }

    /**
     * Distinct IDs of creators on the roster of an ACTIVE/SHIPPING seeding
     * run in the current tenant. Empty array when none — callers must gate
     * their filters on an explicit flag, never on this array's truthiness.
     *
     * @return list<int>
     */
    public function forCurrentTenant(): array
    {
        return SeedingCampaign::query()
            ->whereIn('status', self::statusValues())
            ->join(
                'seeding_campaign_creator',
                'seeding_campaign_creator.seeding_campaign_id',
                '=',
                'seeding_campaigns.id',
            )
            ->pluck('seeding_campaign_creator.creator_id')
            ->unique()
            ->values()
            ->all();
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `XDEBUG_MODE=off php artisan test tests/Feature/Crm/ActiveSeedingCreatorIdsTest.php`
Expected: PASS (4 tests).

- [ ] **Step 5: Point the `/dashboard` tile at the shared constant**

In `app/Modules/Monitoring/Livewire/Dashboard/HomeOverview.php`, replace lines 51-56:

```php
        $activeSeedingRuns = SeedingCampaign::query()
            ->whereIn('status', [
                SeedingCampaignStatus::Active->value,
                SeedingCampaignStatus::Shipping->value,
            ])
            ->count();
```

with:

```php
        $activeSeedingRuns = SeedingCampaign::query()
            ->whereIn('status', ActiveSeedingCreatorIds::statusValues())
            ->count();
```

Add `use App\Modules\CRM\Services\ActiveSeedingCreatorIds;` to the imports; remove the now-unused `use App\Shared\Enums\SeedingCampaignStatus;` import.

- [ ] **Step 6: Run the monitoring feature tests to prove the dashboard is unchanged**

Run: `XDEBUG_MODE=off php artisan test tests/Feature/Monitoring/`
Expected: PASS (no regressions).

- [ ] **Step 7: Commit**

```bash
git add app/Modules/CRM/Services/ActiveSeedingCreatorIds.php \
        app/Modules/Monitoring/Livewire/Dashboard/HomeOverview.php \
        tests/Feature/Crm/ActiveSeedingCreatorIdsTest.php \
        docs/superpowers/specs/2026-07-17-monitoring-active-seeding-filter-design.md \
        docs/superpowers/plans/2026-07-17-monitoring-active-seeding-filter.md
git commit -m "feat(monitoring): ActiveSeedingCreatorIds resolver owns the active-seeding definition"
```

---

### Task 2: `seeding_campaigns (tenant_id, status)` index

**Files:**
- Create: `database/migrations/2026_07_17_100002_add_tenant_status_index_to_seeding_campaigns.php`
- Test: `tests/Feature/Crm/ActiveSeedingCreatorIdsTest.php` (add one test)

**Interfaces:**
- Consumes: existing `seeding_campaigns` table (only `tenant_id` indexed today; `status` is a residual filter).
- Produces: index `seeding_campaigns_tenant_id_status_index` serving the resolver's driving predicate.

- [ ] **Step 1: Write the failing test**

Add to `tests/Feature/Crm/ActiveSeedingCreatorIdsTest.php` (import `Illuminate\Support\Facades\DB`):

```php
    public function test_resolver_driving_predicate_is_indexed(): void
    {
        $this->assertTrue(
            DB::table('pg_indexes')
                ->where('tablename', 'seeding_campaigns')
                ->where('indexname', 'seeding_campaigns_tenant_id_status_index')
                ->exists(),
            'Expected composite index (tenant_id, status) on seeding_campaigns.',
        );
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `XDEBUG_MODE=off php artisan test tests/Feature/Crm/ActiveSeedingCreatorIdsTest.php --filter=test_resolver_driving_predicate_is_indexed`
Expected: FAIL — assertion false (index missing).

- [ ] **Step 3: Write the migration**

Create `database/migrations/2026_07_17_100002_add_tenant_status_index_to_seeding_campaigns.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ActiveSeedingCreatorIds (the monitoring "Active seeding only" filter)
 * and the home-dashboard tile both drive on
 * (tenant_id = ? AND status IN (ACTIVE, SHIPPING)); status carried no
 * index, leaving it a residual filter after the tenant narrow.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('seeding_campaigns', function (Blueprint $table): void {
            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('seeding_campaigns', function (Blueprint $table): void {
            $table->dropIndex(['tenant_id', 'status']);
        });
    }
};
```

- [ ] **Step 4: Run test to verify it passes**

Run: `XDEBUG_MODE=off php artisan test tests/Feature/Crm/ActiveSeedingCreatorIdsTest.php`
Expected: PASS (5 tests — RefreshDatabase runs the new migration).

- [ ] **Step 5: Commit**

```bash
git add database/migrations/2026_07_17_100002_add_tenant_status_index_to_seeding_campaigns.php \
        tests/Feature/Crm/ActiveSeedingCreatorIdsTest.php
git commit -m "perf(crm): index seeding_campaigns (tenant_id, status) for the active-seeding resolver"
```

---

### Task 3: Generalize `RollupReader::creatorTotals()` to a creator set

**Files:**
- Modify: `app/Platform/Analytics/RollupReader.php:137-155`
- Test: `tests/Feature/Analytics/CreatorTotalsFilterTest.php`

**Interfaces:**
- Consumes: `rollup_creator_by_period` materialized view (per-creator weekly rows); `AnalyticsService::refreshRollups()` in tests.
- Produces: `creatorTotals(?Carbon $from = null, ?Carbon $to = null, ?array $creatorIds = null): object` — `null` = unfiltered (today's behavior), array = `whereIn('creator_id', …)`, empty array = no rows ⇒ `null` sums (DP-001). Task 4 passes `$seedingCreatorIds` as the third argument. The old `?int $creatorId` param is replaced in place — its only call site (`MonitoringOverview.php:124`) passes two arguments, verified 2026-07-17.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/Analytics/CreatorTotalsFilterTest.php`:

```php
<?php

namespace Tests\Feature\Analytics;

use App\Modules\CRM\Models\Creator;
use App\Modules\CRM\Models\PlatformAccount;
use App\Modules\Monitoring\Models\ContentItem;
use App\Modules\Monitoring\Models\MetricSnapshot;
use App\Platform\Analytics\Contracts\AnalyticsService;
use App\Platform\Analytics\RollupReader;
use App\Shared\Enums\MetricTier;
use App\Shared\Enums\Platform;
use App\Shared\ValueObjects\MetricValue;
use App\Shared\ValueObjects\Provenance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * creatorTotals() narrowed to a creator set (the monitoring "Active
 * seeding only" filter). NULL ⇒ unavailable stays intact (DP-001): an
 * empty set aggregates no rows and must come back null, never zero.
 */
class CreatorTotalsFilterTest extends TestCase
{
    use RefreshDatabase;

    /** Seed one creator whose content carries the given measured view count. */
    private function seedCreatorViews(int $views): Creator
    {
        $creator = Creator::factory()->create();
        $account = PlatformAccount::factory()->create([
            'creator_id' => $creator->id,
            'platform' => Platform::Instagram,
        ]);
        $content = ContentItem::factory()->create([
            'platform_account_id' => $account->id,
            'published_at' => '2026-06-10 12:00:00',
        ]);

        MetricSnapshot::create([
            'content_item_id' => $content->id,
            'captured_at' => '2026-06-12 09:00:00',
            'metrics' => [new MetricValue($views, MetricTier::Public, 'views')],
            'provenance' => new Provenance('SRC-apify-instagram-scraper', now()->toImmutable(), 'v1'),
        ]);

        return $creator;
    }

    public function test_creator_set_narrows_totals_and_null_means_unfiltered(): void
    {
        $a = $this->seedCreatorViews(100);
        $this->seedCreatorViews(999);

        app(AnalyticsService::class)->refreshRollups();
        $reader = app(RollupReader::class);

        // null = today's unfiltered behavior: both creators.
        $this->assertSame(1099.0, (float) $reader->creatorTotals()->views_sum);

        // A one-creator set sums only that creator's buckets.
        $this->assertSame(100.0, (float) $reader->creatorTotals(creatorIds: [$a->id])->views_sum);
    }

    public function test_empty_creator_set_returns_null_sums_never_zero(): void
    {
        $this->seedCreatorViews(100);

        app(AnalyticsService::class)->refreshRollups();

        $totals = app(RollupReader::class)->creatorTotals(creatorIds: []);

        // DP-001: aggregate over no rows is null (unavailable), not zero.
        $this->assertNull($totals->views_sum);
        $this->assertNull($totals->engagement_sum);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `XDEBUG_MODE=off php artisan test tests/Feature/Analytics/CreatorTotalsFilterTest.php`
Expected: FAIL — `creatorTotals(): Argument #3 ($creatorId) must be of type ?int, array given` (named arg `creatorIds` unknown).

Note: if the first test's `1099.0`/`100.0` expectations fail AFTER the signature change with a *different measured value*, inspect `rollup_creator_by_period` semantics before touching expectations — the numbers must remain "sum of that creator's views", never adjusted to whatever comes back.

- [ ] **Step 3: Replace the parameter in place**

In `app/Platform/Analytics/RollupReader.php`, replace the whole `creatorTotals` method (lines 137-155):

```php
    /**
     * PUBLIC-component totals across ROLLUP-CreatorByPeriod for a period
     * (views + engagement components; DERIVED rates are recomputed by the
     * consumer, never summed), optionally narrowed to a creator set. An
     * empty set aggregates no rows — sums come back NULL (unavailable),
     * never zero (DP-001).
     *
     * @param  list<int>|null  $creatorIds
     * @return object{views_sum: string|null, engagement_sum: string|null, content_count: string|null}
     */
    public function creatorTotals(?Carbon $from = null, ?Carbon $to = null, ?array $creatorIds = null): object
    {
        return $this->tenant(DB::table('rollup_creator_by_period'))
            ->where('grain', 'week')
            ->when($from, fn ($q) => $q->where('bucket_start', '>=', $from->startOfWeek()->toDateString()))
            ->when($to, fn ($q) => $q->where('bucket_start', '<=', $to->toDateString()))
            ->when($creatorIds !== null, fn ($q) => $q->whereIn('creator_id', $creatorIds))
            ->selectRaw('sum(views_sum) as views_sum')
            ->selectRaw('sum(engagement_sum) as engagement_sum')
            ->selectRaw('sum(content_count) as content_count')
            ->first();
    }
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `XDEBUG_MODE=off php artisan test tests/Feature/Analytics/CreatorTotalsFilterTest.php`
Expected: PASS (2 tests).

Also run the neighboring analytics suites (same reader):
`XDEBUG_MODE=off php artisan test tests/Feature/Analytics/ tests/Feature/Tenancy/CrossTenantAnalyticsTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Platform/Analytics/RollupReader.php \
        tests/Feature/Analytics/CreatorTotalsFilterTest.php
git commit -m "feat(analytics): creatorTotals() accepts a creator-ID set"
```

---

### Task 4: `MonitoringOverview` — toggle property, card scoping, review-counts dedup

**Files:**
- Modify: `app/Modules/Monitoring/Livewire/Dashboard/MonitoringOverview.php`
- Test: `tests/Feature/Monitoring/MonitoringSeedingFilterTest.php`

**Interfaces:**
- Consumes: `ActiveSeedingCreatorIds::forCurrentTenant(): list<int>` (Task 1), `creatorTotals(?Carbon, ?Carbon, ?array)` (Task 3).
- Produces: `#[Url] public bool $activeSeedingOnly = false;` and view data `seedingSetEmpty` (bool) consumed by the Blade in Task 5. Existing view keys unchanged.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/Monitoring/MonitoringSeedingFilterTest.php`:

```php
<?php

namespace Tests\Feature\Monitoring;

use App\Models\User;
use App\Modules\CRM\Models\Creator;
use App\Modules\CRM\Models\PlatformAccount;
use App\Modules\CRM\Models\SeedingCampaign;
use App\Modules\Monitoring\Livewire\Dashboard\MonitoringOverview;
use App\Modules\Monitoring\Models\ContentItem;
use App\Modules\Monitoring\Models\Mention;
use App\Modules\Monitoring\Models\MonitoredSubject;
use App\Modules\Monitoring\Models\Story;
use App\Shared\Enums\MonitoredSubjectType;
use App\Shared\Enums\Platform;
use App\Shared\Enums\RoleName;
use App\Shared\Enums\SeedingCampaignStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The "Active seeding only" toggle (spec 2026-07-17): re-scopes roster,
 * new-content, active-stories, mentions-by-type and the creatorTotals KPI
 * to creators enrolled in ACTIVE/SHIPPING seeding. Toggle OFF must be
 * byte-identical to today; toggle ON with an empty set filters to zero,
 * never silently unfiltered.
 */
class MonitoringSeedingFilterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
        $this->actingAs($this->makeUser(RoleName::Analyst));
    }

    /**
     * One enrolled + one unenrolled roster creator, each with content,
     * an active story and a mention.
     *
     * @return array{seeded: Creator, other: Creator}
     */
    private function seedWorld(): array
    {
        $world = [];

        foreach (['seeded', 'other'] as $key) {
            $creator = Creator::factory()->create();
            $subject = MonitoredSubject::factory()->create([
                'subject_type' => MonitoredSubjectType::Creator->value,
                'creator_id' => $creator->id,
                'active' => true,
            ]);
            $account = PlatformAccount::factory()->create([
                'creator_id' => $creator->id,
                'platform' => Platform::Instagram,
            ]);
            $content = ContentItem::factory()->create(['platform_account_id' => $account->id]);
            Story::factory()->create(['platform_account_id' => $account->id]);
            Mention::factory()->create([
                'monitored_subject_id' => $subject->id,
                'content_item_id' => $content->id,
            ]);

            $world[$key] = $creator;
        }

        $run = SeedingCampaign::factory()->create(['status' => SeedingCampaignStatus::Active]);
        $run->creators()->attach([$world['seeded']->id]);

        return $world;
    }

    public function test_toggle_off_counts_all_roster_creators(): void
    {
        $this->seedWorld();

        Livewire::test(MonitoringOverview::class)
            ->assertSet('activeSeedingOnly', false)
            ->assertViewHas('rosterCount', 2)
            ->assertViewHas('newContent', 2)
            ->assertViewHas('activeStories', 2)
            ->assertViewHas('seedingSetEmpty', false)
            ->assertViewHas('mentionsByType', fn (Collection $byType): bool => (int) $byType->sum() === 2);
    }

    public function test_toggle_on_scopes_every_creator_keyed_card_to_enrolled_creators(): void
    {
        $this->seedWorld();

        Livewire::test(MonitoringOverview::class)
            ->set('activeSeedingOnly', true)
            ->assertViewHas('rosterCount', 1)
            ->assertViewHas('newContent', 1)
            ->assertViewHas('activeStories', 1)
            ->assertViewHas('seedingSetEmpty', false)
            ->assertViewHas('mentionsByType', fn (Collection $byType): bool => (int) $byType->sum() === 1);
    }

    public function test_toggle_on_with_no_active_seeding_filters_to_zero_not_unfiltered(): void
    {
        $world = $this->seedWorld();
        // Retire the only active run: enrolled creators exist, but no ACTIVE/SHIPPING campaign.
        SeedingCampaign::query()->update(['status' => SeedingCampaignStatus::Completed->value]);

        Livewire::test(MonitoringOverview::class)
            ->set('activeSeedingOnly', true)
            ->assertViewHas('rosterCount', 0)
            ->assertViewHas('newContent', 0)
            ->assertViewHas('activeStories', 0)
            ->assertViewHas('seedingSetEmpty', true)
            ->assertViewHas('mentionsByType', fn (Collection $byType): bool => (int) $byType->sum() === 0)
            // KPI aggregate over no rows: null (unavailable), never zero (DP-001).
            ->assertViewHas('creatorTotals', fn (object $totals): bool => $totals->views_sum === null);
    }

    public function test_url_round_trip_restores_the_toggle(): void
    {
        Livewire::withQueryParams(['activeSeedingOnly' => true])
            ->test(MonitoringOverview::class)
            ->assertSet('activeSeedingOnly', true);
    }

    public function test_toggle_intersects_with_the_platform_filter(): void
    {
        $world = $this->seedWorld(); // seeded creator has 1 Instagram content item

        // Give the enrolled creator a TikTok item too.
        $ttAccount = PlatformAccount::factory()->create([
            'creator_id' => $world['seeded']->id,
            'platform' => Platform::TikTok,
        ]);
        ContentItem::factory()->create([
            'platform_account_id' => $ttAccount->id,
            'platform' => Platform::TikTok,
        ]);

        Livewire::test(MonitoringOverview::class)
            ->set('activeSeedingOnly', true)
            ->set('platform', Platform::TikTok->value)
            ->assertViewHas('newContent', 1)   // TikTok ∩ enrolled — not the Instagram item
            // Roster card ignores platform by design; it responds only to the toggle.
            ->assertViewHas('rosterCount', 1);
    }

    public function test_cross_tenant_active_seeding_never_leaks_into_this_tenants_page(): void
    {
        // Tenant B: fully active seeding world.
        $tenantB = $this->makeTenant('Tenant B');
        $this->withTenant($tenantB, function (): void {
            $creator = Creator::factory()->create();
            MonitoredSubject::factory()->create([
                'subject_type' => MonitoredSubjectType::Creator->value,
                'creator_id' => $creator->id,
                'active' => true,
            ]);
            $run = SeedingCampaign::factory()->create(['status' => SeedingCampaignStatus::Active]);
            $run->creators()->attach([$creator->id]);
        });

        // Tenant A (default): roster creator, NO active seeding.
        $creatorA = Creator::factory()->create();
        MonitoredSubject::factory()->create([
            'subject_type' => MonitoredSubjectType::Creator->value,
            'creator_id' => $creatorA->id,
            'active' => true,
        ]);

        Livewire::test(MonitoringOverview::class)
            ->set('activeSeedingOnly', true)
            ->assertViewHas('rosterCount', 0)       // tenant B's enrollment must not count here
            ->assertViewHas('seedingSetEmpty', true);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `XDEBUG_MODE=off php artisan test tests/Feature/Monitoring/MonitoringSeedingFilterTest.php`
Expected: FAIL — `Unable to set component data. Public property [activeSeedingOnly] not found` (and missing `seedingSetEmpty` view key on the OFF test).

- [ ] **Step 3: Implement the component changes**

In `app/Modules/Monitoring/Livewire/Dashboard/MonitoringOverview.php`:

(a) Add imports:

```php
use App\Modules\CRM\Services\ActiveSeedingCreatorIds;
```

(b) Add the property after `$brandId` (line 44):

```php
    #[Url(except: false)]
    public bool $activeSeedingOnly = false;
```

(c) In `render()`, resolve the set once, right after `$brandId = $this->brandFilter();`:

```php
        // "Active seeding only" (spec 2026-07-17): resolve the enrolled
        // creator set ONCE per render. Cards gate on the boolean — an empty
        // set must filter to zero rows, never fall back to unfiltered.
        $seedingCreatorIds = $this->activeSeedingOnly
            ? app(ActiveSeedingCreatorIds::class)->forCurrentTenant()
            : null;
```

(d) Scope the four OLTP cards (`$this->activeSeedingOnly` gates, never the array):

```php
        $rosterCount = MonitoredSubject::query()
            ->where('subject_type', MonitoredSubjectType::Creator->value)
            ->where('active', true)
            ->when($this->activeSeedingOnly, fn ($q) => $q->whereIn('creator_id', $seedingCreatorIds))
            ->count();

        $newContent = ContentItem::query()
            ->when($platform, fn ($q) => $q->where('platform', $platform->value))
            ->when($from, fn ($q) => $q->where('published_at', '>=', $from))
            ->when($to, fn ($q) => $q->where('published_at', '<=', $to))
            ->when($this->activeSeedingOnly, fn ($q) => $q->whereHas(
                'platformAccount',
                fn ($account) => $account->whereIn('creator_id', $seedingCreatorIds),
            ))
            ->count();

        $activeStories = Story::query()
            ->when($platform, fn ($q) => $q->where('platform', $platform->value))
            ->where(fn ($q) => $q
                ->where('expires_at', '>', now())
                ->orWhere(fn ($inner) => $inner
                    ->whereNull('expires_at')
                    ->where('captured_at', '>=', now()->subDay())))
            ->when($this->activeSeedingOnly, fn ($q) => $q->whereHas(
                'platformAccount',
                fn ($account) => $account->whereIn('creator_id', $seedingCreatorIds),
            ))
            ->count();

        $mentionsByType = Mention::query()
            ->when($from, fn ($q) => $q->where('created_at', '>=', $from))
            ->when($to, fn ($q) => $q->where('created_at', '<=', $to))
            ->when($brandId !== null, fn ($q) => $q->whereHas(
                'campaign',
                fn ($c) => $c->where('brand_id', $brandId),
            ))
            ->when($this->activeSeedingOnly, fn ($q) => $q->whereHas(
                'monitoredSubject',
                fn ($subject) => $subject->whereIn('creator_id', $seedingCreatorIds),
            ))
            ->selectRaw('mention_type, count(*) as total')
            ->groupBy('mention_type')
            ->pluck('total', 'mention_type');
```

(e) Deduplicate the review counts and thread the KPI + view flags — replace the `return view(...)` array entries:

```php
        $reviewCounts = $queue->counts();
```

(before the `return`), then in the view data replace:

```php
            'pendingReviews' => array_sum($queue->counts()),
            'reviewCounts' => $queue->counts(),
            'creatorTotals' => $rollups->creatorTotals($from, $to),
```

with:

```php
            'pendingReviews' => array_sum($reviewCounts),
            'reviewCounts' => $reviewCounts,
            'creatorTotals' => $rollups->creatorTotals($from, $to, $seedingCreatorIds),
            'seedingSetEmpty' => $seedingCreatorIds === [],
```

(f) Extend the class docblock's filter sentence — append to the paragraph ending "(ADR-0010).":

```
The "Active seeding only" toggle re-scopes the creator-keyed cards to
ActiveSeedingCreatorIds (ACTIVE+SHIPPING enrollment); brand-keyed
reach/EMV cannot be creator-scoped and render an explanatory
unavailable state instead.
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `XDEBUG_MODE=off php artisan test tests/Feature/Monitoring/MonitoringSeedingFilterTest.php tests/Feature/Monitoring/DashboardScreensTest.php`
Expected: PASS — new tests green, existing overview test untouched (toggle OFF = today's behavior).

- [ ] **Step 5: Commit**

```bash
git add app/Modules/Monitoring/Livewire/Dashboard/MonitoringOverview.php \
        tests/Feature/Monitoring/MonitoringSeedingFilterTest.php
git commit -m "feat(monitoring): active-seeding-only toggle scopes the overview's creator-keyed cards"
```

---

### Task 5: Blade — toggle control, date-input debounce, Reach/EMV seeding state, empty-set notice

**Files:**
- Modify: `resources/views/livewire/monitoring/monitoring-overview.blade.php`
- Test: `tests/Feature/Monitoring/MonitoringSeedingFilterTest.php` (add rendering tests)

**Interfaces:**
- Consumes: `$activeSeedingOnly` (Livewire public property, auto-available), `seedingSetEmpty` view flag (Task 4), existing `x-form.toggle`, `x-form.label`, `x-states.unavailable` components.
- Produces: user-visible toggle + states; exact copy strings asserted by tests: `Active seeding only`, `No creators are currently in an active seeding.`, `Aggregated by brand — not available for the seeding filter.`

- [ ] **Step 1: Write the failing rendering tests**

Add to `tests/Feature/Monitoring/MonitoringSeedingFilterTest.php`:

```php
    public function test_page_renders_toggle_and_scoped_view_with_brand_only_reach_state(): void
    {
        $this->seedWorld();

        Livewire::test(MonitoringOverview::class)
            ->assertSee('Active seeding only')
            ->assertDontSee('Aggregated by brand — not available for the seeding filter.')
            ->set('activeSeedingOnly', true)
            // Reach/EMV: message only, no brand-wide number, distinct from
            // the generic rollup-unavailable copy.
            ->assertSee('Aggregated by brand — not available for the seeding filter.')
            ->assertDontSee('No estimated reach in the rollups for this period yet');
    }

    public function test_empty_set_notice_shows_only_when_toggled_on_with_no_active_seeding(): void
    {
        $this->seedWorld();

        $component = Livewire::test(MonitoringOverview::class)
            ->assertDontSee('No creators are currently in an active seeding.');

        // Enrolled creators exist → no notice even when counts are zeroed
        // by a restrictive date range.
        $component->set('activeSeedingOnly', true)
            ->set('from', '2030-01-01')
            ->set('to', '2030-01-02')
            ->assertDontSee('No creators are currently in an active seeding.');

        SeedingCampaign::query()->update(['status' => SeedingCampaignStatus::Completed->value]);

        Livewire::test(MonitoringOverview::class)
            ->set('activeSeedingOnly', true)
            ->assertSee('No creators are currently in an active seeding.');
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `XDEBUG_MODE=off php artisan test tests/Feature/Monitoring/MonitoringSeedingFilterTest.php`
Expected: FAIL — `assertSee('Active seeding only')` (control not in the Blade yet).

- [ ] **Step 3: Implement the Blade changes**

In `resources/views/livewire/monitoring/monitoring-overview.blade.php`:

(a) Filter bar (line 3): widen the grid and add the toggle as a fifth cell; switch the date inputs to `.blur`:

```blade
    <div class="mb-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
        <div>
            <x-form.label for="overview-platform">Platform</x-form.label>
            <x-form.select id="overview-platform" wire:model.live="platform">
                <option value="">All platforms</option>
                @foreach ($platforms as $p)
                    <option value="{{ $p->value }}">{{ $p->value }}</option>
                @endforeach
            </x-form.select>
        </div>
        <div>
            <x-form.label for="overview-brand">Brand</x-form.label>
            <x-form.select id="overview-brand" wire:model.live="brandId">
                <option value="0">All brands</option>
                @foreach ($brands as $brand)
                    <option value="{{ $brand->id }}">{{ $brand->name }}</option>
                @endforeach
            </x-form.select>
        </div>
        <div>
            <x-form.label for="overview-from">From</x-form.label>
            <x-form.input id="overview-from" type="date" wire:model.blur="from" />
        </div>
        <div>
            <x-form.label for="overview-to">To</x-form.label>
            <x-form.input id="overview-to" type="date" wire:model.blur="to" />
        </div>
        <div>
            <x-form.label for="overview-seeding">Seeding</x-form.label>
            <div class="mt-2.5">
                <x-form.toggle id="overview-seeding" wire:model.live="activeSeedingOnly" label="Active seeding only" />
            </div>
        </div>
    </div>
```

(b) Empty-set notice, immediately after the closing `</div>` of the filter grid (before the `{{-- KPI cards --}}` comment):

```blade
    @if ($activeSeedingOnly && $seedingSetEmpty)
        <div class="mb-4 rounded-2xl border border-gray-200 bg-white p-4 text-sm text-gray-600 dark:border-gray-800 dark:bg-white/[0.03] dark:text-gray-300">
            No creators are currently in an active seeding.
        </div>
    @endif
```

(c) Estimated reach card (lines 78-88): message-only when the toggle is ON — the brand-wide figure must never render under the seeding filter:

```blade
        <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
            <p class="text-sm text-gray-500 dark:text-gray-400">Estimated reach (period)</p>
            <div class="mt-2">
                @if ($activeSeedingOnly)
                    <x-states.unavailable reason="Aggregated by brand — not available for the seeding filter." />
                @elseif ($mentionTotals->total_estimated_reach !== null)
                    <span class="text-2xl font-semibold text-gray-800 dark:text-white/90">{{ number_format((float) $mentionTotals->total_estimated_reach) }}</span>
                    <x-metric.tier-badge tier="ESTIMATED" />
                @else
                    <x-states.unavailable reason="No estimated reach in the rollups for this period yet (REQ-M1-006)." />
                @endif
            </div>
        </div>
```

(d) EMV card (lines 89-99): same pattern:

```blade
        <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
            <p class="text-sm text-gray-500 dark:text-gray-400">EMV (period)</p>
            <div class="mt-2">
                @if ($activeSeedingOnly)
                    <x-states.unavailable reason="Aggregated by brand — not available for the seeding filter." />
                @elseif ($mentionTotals->total_emv !== null)
                    <span class="text-2xl font-semibold text-gray-800 dark:text-white/90">{{ number_format((float) $mentionTotals->total_emv, 2) }}</span>
                    <x-metric.tier-badge tier="ESTIMATED" />
                @else
                    <x-states.unavailable reason="EMV requires an active, user-managed EMV configuration (REQ-M1-011) and calculated results." />
                @endif
            </div>
        </div>
```

The Views/Engagement tiles (lines 56-77) stay untouched: with the toggle ON their sums come from the scoped `creatorTotals()`, and a `null` (empty set / no measured buckets) falls into their existing unavailable state per DP-001.

- [ ] **Step 4: Run tests to verify they pass**

Run: `XDEBUG_MODE=off php artisan test tests/Feature/Monitoring/`
Expected: PASS — including `DashboardScreensTest::test_overview_renders_kpis_deferred_states_and_review_counts` (toggle OFF keeps every existing state and copy).

- [ ] **Step 5: Commit**

```bash
git add resources/views/livewire/monitoring/monitoring-overview.blade.php \
        tests/Feature/Monitoring/MonitoringSeedingFilterTest.php
git commit -m "feat(monitoring): seeding toggle UI, brand-only reach/EMV state, empty-set notice, date-input blur"
```

---

### Task 6: Full-suite verification

**Files:**
- No new files — regression gate.

**Interfaces:**
- Consumes: everything above.
- Produces: a green full test suite on the branch.

- [ ] **Step 1: Run the full test suite**

Run: `XDEBUG_MODE=off php artisan test`
Expected: PASS — same green count as the branch baseline plus the new tests (baseline before this feature: run `git stash` is NOT needed; if unrelated reach-settings tests were already failing, compare against a pre-change run, not zero).

- [ ] **Step 2: Fix anything the suite surfaces**

If a failure traces to this feature (e.g. an untouched test asserting the old 4-column filter grid or the exact `creatorTotals` signature), fix the feature code — do not weaken the unrelated test. Re-run until green.

- [ ] **Step 3: Commit (only if fixes were needed)**

```bash
git add <only-files-actually-changed-by-the-fix>
git commit -m "fix(monitoring): full-suite regressions from the seeding filter"
```
