# CRM Stage D — Data-Model & Lifecycle Hardening: Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax.

**Goal:** Close the data-model and lifecycle gaps the audit flagged — seeding-run anchors for tasks/comms, a lightweight campaign brief, a brand-coherence guard that stops a campaign brand change from silently breaking its seeding runs, restriction matching that also honours brand aliases and actually enforces the "do not contact" status, and read-only progress + soft suggestion banners so statuses become a guided process instead of free-pick labels.

**Architecture:** Two additive nullable migrations (zero-downtime), one new `CampaignWriter` service (the coherence choke point), a refactor of the existing `BrandRestrictionGuard` to fold aliases in one place, enforcement wiring into the four existing attach paths, and thin read-only/suggestion surfaces on the existing detail pages. Three behaviour changes are recorded in a new ADR.

**Tech Stack:** Laravel 11, Livewire 3, Alpine, Tailwind v4, PHPUnit 11, PostgreSQL (test DB `qds_test` on :5433).

**Spec:** `docs/superpowers/specs/2026-07-16-crm-ux-redesign-audit-and-plan.md` §Stage D (line 333) + §2.5/§2.6. Fixes F06, F07, F14, F18, F24, F27; enables §2.5.

## ALREADY DONE — do NOT redo (verified by scout)

- **Item 3 (F03 repair) is fully shipped in Stage A.** The backfill migration `database/migrations/2026_07_17_100001_backfill_seeding_campaign_creator_from_shipments.php` (idempotent `insertOrIgnore`), the shipment self-heal auto-attach inside `ShipmentsPanel::save()`'s `DB::transaction` (lines ~218-295) with a `BrandRestrictionGuard` check, the demo-seeder fix, the `SeedingCreatorsPanel` detach guard, and the full test suite `tests/Feature/Crm/SeedingRosterRepairTest.php` all exist. **Item 3 is a precondition, not work.** Touch none of it.
- `DocumentAttachment.seeding_campaign_id` + `DocumentsPanel` seeding parent + `SeedingCampaign::documentAttachments()` already exist — the seeding **documents** anchor is done; only **tasks** and **comms** anchors are missing.
- Status `description()` on every enum, the context-header status chip, and `campaign.status_changed`/`shipment.status_changed` audit events already exist (Stage A/B). Reuse them.
- `brands.aliases` (jsonb, cast `array`) already exists — item 5 alias matching needs **no** migration.
- The Stage C bulk restriction matchers (`restrictedCreatorIds`, `restrictedCreatorIdsForName`) exist — item 5 **widens** them, does not recreate them. `blocklistedIds` is already computed for the picker **display** — item 5 wires it into the attach **enforcement**.

## Global Constraints

- Test command: `XDEBUG_MODE=off php artisan test` (targeted `--filter=X`). Baseline at branch start: **1124 passed / 0 failed** (main, verified). Branch: `feat/crm-ux-stage-d` off main.
- **NEVER `migrate:fresh`** or any DB-wiping command. New migrations run via `php artisan migrate`; `RefreshDatabase` runs the full set in tests. Migrations are **additive nullable** (zero-downtime); the one data-writing migration (F03) already shipped.
- Formatter before each commit: `vendor/bin/pint --dirty`. Commit per task, `feat(crm)`/`fix(crm)`/`docs(adr)` subject, **NO Co-Authored-By / AI-attribution trailer, ever.** `git add` only your own files; never `git add -A`.
- Copy lint: new/changed blades under `resources/views/{crm,livewire/crm,components/crm,components/metric,components/states}` must pass `tests/Feature/Ui/CrmCopyLintTest`. Banned in rendered prose AND in `title/reason/aria-label/placeholder/label` attributes: spec IDs (`ADR-…`, `REQ-…`, `AC-…`, `DP-…`), `rollup`, `authoritative`, `v1`, `Module N`, `Step N`, `phase PN`, `Seeding campaign`, `variant(s)` unless exactly `Product variant`, `hard filter(s)`, `operator-managed`, `before commit`. Curly apostrophes (’) in copy. **Never write a spec ID (ADR-0027 etc.) in a blade** — it lives only in `docs/` and PHP comments.
- Controlled vocabulary: entity = "seeding run"; "Seeding type" not Variant; "Sector" not Category. Identifiers stay engineering-named (`SeedingCampaign`, `seeding_campaign_id`, `crm.seeding.*`) — never rename.
- Multi-tenant: FK validation via `TenantRule::exists('table','id')` (plain `Rule::exists` is a cross-tenant oracle). A new **tenant-scoped FK column** needs BOTH the single-column `->constrained()` FK AND a raw composite FK `(<col>, tenant_id) → seeding_campaigns(id, tenant_id)` added in the same migration (the parent already has `UNIQUE(id, tenant_id)`). Scalar/jsonb columns (objective/markets) need no FK. Case-folding for restriction matching stays in **PHP `mb_strtolower`**, never SQL `lower()`.
- Every mutating Livewire action: `$this->authorize(...)` (crm.manage; never a route gate), `AuditLogger::record('entity.event', $model, [...])`, `$this->dispatch('notify', type: 'success'|'error', message: '…')`. Reads stay behind crm.view.
- Detail pages (`campaign-detail`, `seeding-detail`) are route **closures** returning a view — no page-owning Livewire component. A read-only strip can be `@php` in the blade; any **write** action (suggestion banner button) must be its own small Livewire component mounted in the blade.
- Behaviour changes (alias-widened matching, BLOCKLISTED enforcement, campaign brand-change block) are **release-noted** in a new ADR-0027 in `docs/05-decisions/decision-log.md` (Task 12).
- Update `.superpowers/sdd/progress.md` per task (Stage D header added in Task 0 setup).

## Design decisions (locked)

1. **CampaignWriter** (new `App\Modules\CRM\Services\CampaignWriter`, mirroring `CreatorWriter`'s shape): `createCampaign(array $attributes, AuditLogger): Campaign` and `updateCampaign(Campaign, array $attributes, AuditLogger): Campaign`. `updateCampaign` calls `assertBrandChangeAllowed()` — throws typed `CampaignBrandLocked` when `brand_id` changes AND the campaign has ≥1 seeding run. Both `CampaignsIndex::save()` and `CampaignWizard::commit()` route campaign writes through it, so the guard is the single choke point future edit paths inherit. Livewire catches `CampaignBrandLocked` → `ValidationException` on `campaign_brand_id` with a count + a pointer to the campaign's Seeding-runs tab.
2. **Restriction matching** becomes alias-aware in ONE place: private `needlesForBrand(Brand): array` (name + aliases, folded) and `restrictedCreatorIdsForNeedles(array $ids, array $needles): array`. `assertNotRestricted` and `restrictedCreatorIds(Brand)` use needles (alias-aware); `restrictedCreatorIdsForName(string)` stays name-only (the wizard's typed brand may not exist yet, has no aliases).
3. **BLOCKLISTED enforcement** is centralized: `BrandRestrictionGuard::blocklistedCreatorIds(array $ids): array`. Wired into all four attach paths — **soft-skip with a distinct notice** in the bulk paths (`attachSelected`, `copyCampaignRoster`, `CampaignWizard::commit`), **hard `ValidationException`** in the single-recipient `ShipmentsPanel` auto-attach.
4. **Comms-log anchor** = a nullable `seeding_campaign_id` column + optional **Campaign** and **Seeding run** selects added to the existing creator-profile `CommunicationLogPanel` form (also surfacing the already-present-but-dead `campaign_id`). Not a new seeding-detail panel (a comms log requires a creator).
5. **Campaign brief** = `objective` (text) + `markets` (jsonb list, one market per line) edited in the `CampaignsIndex` **edit** modal (edit-only, like spend), displayed read-only on the campaign-detail Overview.
6. **Progress strip** = read-only, blade-only, live from the Shipment table (never rollups — they lag). **Suggestion banners** = thin Livewire components (`CampaignStatusActions`, `SeedingStatusActions`) on the Overview tabs; one suggestion at a time; `wire:confirm`; authorize + audit. **Shipment field→status** and **relationship auto-suggest** are soft one-tap prompts, never automatic.

## Shared conventions cheat-sheet (verified file:line)

- Additive nullable FK (new migration, `Schema::table`): `$table->foreignId('seeding_campaign_id')->nullable()->index()->constrained();` then raw `DB::statement("ALTER TABLE <child> ADD CONSTRAINT <child>_seeding_campaign_id_tenant_fk FOREIGN KEY (seeding_campaign_id, tenant_id) REFERENCES seeding_campaigns (id, tenant_id)")`. `down()`: `DB::statement("ALTER TABLE <child> DROP CONSTRAINT IF EXISTS …")` then `$table->dropConstrainedForeignId('seeding_campaign_id')`.
- jsonb column: `$table->jsonb('markets')->nullable();` text: `$table->text('objective')->nullable();`. Cast: `'markets' => 'array'` (objective no cast).
- Three-parent panel (copy `DocumentsPanel`): props `?Creator $creator`/`?Campaign $campaign`/`?SeedingCampaign $seedingCampaign`; `mount()` does `array_filter([...])` requires count===1 then `authorize('view', $parent)`; write path stamps the anchor from whichever prop is set.
- Migration test idiom (`SeedingRosterRepairTest:144`): `$m = require database_path('migrations/<file>.php'); $m->up();` (twice for idempotency), then `assertDatabaseHas` + count.
- Schema column assertions: `tests/Feature/Crm/CrmSchemaTest.php` `test_tables_carry_their_canonical_columns` — add new columns there.
- Status-write reference (`CampaignsIndex::save` ~206-211): capture `$previous = $model->status`; set; if changed `$audit->record('campaign.status_changed', $model, ['from' => $previous->value, 'to' => $model->status->value])`.
- Roster attach reference (`ManagesCreatorRoster::attachSelected`): bulk pre-filter (`restrictedCreatorIds`) + per-creator `assertNotRestricted` fallback + `$skippedNames` notice. Keep the belt-and-suspenders shape.
- Card recipe: `rounded-2xl border border-gray-200 bg-white p-6 dark:border-gray-800 dark:bg-white/[0.03]`; nested `rounded-xl border border-gray-200 p-4 dark:border-gray-800`. Info banner: reuse `x-ui.alert` (`variant="info"|"warning"|"success"`, optional `title`). Progress bar: a `bg-gray-100 dark:bg-white/5` track with a `bg-brand-500` fill `style="width: …%"`.

---

### Task 1: Schema — seeding anchors + campaign brief (migrations, models, factories, schema test)

**Files:**
- Create: `database/migrations/2026_07_18_100001_add_seeding_campaign_id_to_tasks_and_communication_logs.php`
- Create: `database/migrations/2026_07_18_100002_add_brief_columns_to_campaigns.php`
- Modify: `app/Modules/CRM/Models/Task.php`, `CommunicationLog.php`, `Campaign.php`, `SeedingCampaign.php`
- Modify: `database/factories/TaskFactory.php`, `CommunicationLogFactory.php`, `CampaignFactory.php`
- Modify: `tests/Feature/Crm/CrmSchemaTest.php`
- Test: `tests/Feature/Crm/StageDSchemaTest.php` (new — migration idempotency-safe + column presence + relation wiring)

**Interfaces produced (consumed by Tasks 2-11):**
- `Task.seeding_campaign_id` (nullable), `Task::seedingCampaign(): BelongsTo`
- `CommunicationLog.seeding_campaign_id` (nullable), `CommunicationLog::seedingCampaign(): BelongsTo`
- `Campaign.objective` (?string), `Campaign.markets` (?array), both fillable; `markets` cast `array`
- `SeedingCampaign::tasks(): HasMany`, `SeedingCampaign::communicationLogs(): HasMany`

- [ ] **Step 1: Write the migrations.** Migration A (`…_add_seeding_campaign_id_to_tasks_and_communication_logs`):

```php
public function up(): void
{
    Schema::table('tasks', function (Blueprint $table) {
        $table->foreignId('seeding_campaign_id')->nullable()->index()->constrained();
    });
    DB::statement('ALTER TABLE tasks ADD CONSTRAINT tasks_seeding_campaign_id_tenant_fk FOREIGN KEY (seeding_campaign_id, tenant_id) REFERENCES seeding_campaigns (id, tenant_id)');

    Schema::table('communication_logs', function (Blueprint $table) {
        $table->foreignId('seeding_campaign_id')->nullable()->index()->constrained();
    });
    DB::statement('ALTER TABLE communication_logs ADD CONSTRAINT communication_logs_seeding_campaign_id_tenant_fk FOREIGN KEY (seeding_campaign_id, tenant_id) REFERENCES seeding_campaigns (id, tenant_id)');
}

public function down(): void
{
    DB::statement('ALTER TABLE tasks DROP CONSTRAINT IF EXISTS tasks_seeding_campaign_id_tenant_fk');
    Schema::table('tasks', fn (Blueprint $t) => $t->dropConstrainedForeignId('seeding_campaign_id'));
    DB::statement('ALTER TABLE communication_logs DROP CONSTRAINT IF EXISTS communication_logs_seeding_campaign_id_tenant_fk');
    Schema::table('communication_logs', fn (Blueprint $t) => $t->dropConstrainedForeignId('seeding_campaign_id'));
}
```

Migration B (`…_add_brief_columns_to_campaigns`):

```php
public function up(): void
{
    Schema::table('campaigns', function (Blueprint $table) {
        $table->text('objective')->nullable();
        $table->jsonb('markets')->nullable();
    });
}
public function down(): void
{
    Schema::table('campaigns', fn (Blueprint $t) => $t->dropColumn(['objective', 'markets']));
}
```

- [ ] **Step 2: Models.** Task: add `'seeding_campaign_id'` to `$fillable`, `@property int|null $seeding_campaign_id`, and `public function seedingCampaign(): BelongsTo { return $this->belongsTo(SeedingCampaign::class); }` (copy `campaign()`). CommunicationLog: same. Campaign: add `'objective'`, `'markets'` to `$fillable`, `@property ?string $objective`, `@property ?array $markets`, and `'markets' => 'array'` in `casts()`. SeedingCampaign: add `tasks(): HasMany` and `communicationLogs(): HasMany` (copy `documentAttachments()`).

- [ ] **Step 3: Factories.** `TaskFactory::definition()` add `'seeding_campaign_id' => null`. `CommunicationLogFactory::definition()` add `'seeding_campaign_id' => null`. `CampaignFactory::definition()` add `'objective' => null, 'markets' => null`. (Optional states `withSeedingCampaign()` / `withBrief()` if a test needs them.)

- [ ] **Step 4: Column assertions.** In `tests/Feature/Crm/CrmSchemaTest.php`, add `seeding_campaign_id` to the tasks and communication_logs canonical column lists, and `objective`, `markets` to campaigns. Then write `tests/Feature/Crm/StageDSchemaTest.php`:

```php
public function test_new_columns_exist_and_relations_resolve(): void
{
    $this->actingAsCrmStaff();
    $run = SeedingCampaign::factory()->create();
    $task = Task::factory()->create(['seeding_campaign_id' => $run->id]);
    $log = CommunicationLog::factory()->create(['seeding_campaign_id' => $run->id]);
    $campaign = Campaign::factory()->create(['objective' => 'Awareness in DACH', 'markets' => ['DE', 'AT']]);

    $this->assertTrue($task->seedingCampaign->is($run));
    $this->assertTrue($log->seedingCampaign->is($run));
    $this->assertSame(['DE', 'AT'], $campaign->fresh()->markets);
    $this->assertSame('Awareness in DACH', $campaign->fresh()->objective);
    $this->assertTrue($run->tasks->contains($task));
    $this->assertTrue($run->communicationLogs->contains($log));
}

public function test_seeding_anchor_is_tenant_scoped(): void
{
    $this->actingAsCrmStaff();
    $foreignRun = $this->withTenant($this->makeTenant('Tenant B'), fn () => SeedingCampaign::factory()->create());
    // A task cannot point at a foreign-tenant run (composite FK blocks it at the DB layer).
    $this->expectException(\Illuminate\Database\QueryException::class);
    Task::factory()->create(['seeding_campaign_id' => $foreignRun->id]);
}
```

- [ ] **Step 5:** `XDEBUG_MODE=off php artisan test --filter=StageDSchemaTest --filter=CrmSchemaTest` → green; `XDEBUG_MODE=off php artisan migrate` on the dev DB (forward only — confirms the migration applies cleanly outside tests). Then regression `--filter=Crm`, `vendor/bin/pint --dirty`, commit:

```
feat(crm): add seeding-run anchors for tasks and comms, plus a lightweight campaign brief (objective, markets)
```

---

### Task 2: Tasks on a seeding run (F18) — TasksPanel third parent + seeding-detail mount + board scope

**Files:**
- Modify: `app/Modules/CRM/Livewire/Tasks/TasksPanel.php` (add `?SeedingCampaign $seedingCampaign`)
- Modify: `app/Modules/CRM/Livewire/Tasks/TasksIndex.php` (seeding scope: eager-load + form field + validation)
- Modify: `resources/views/livewire/crm/tasks-index.blade.php` (seeding select in the task modal)
- Modify: `resources/views/crm/seeding-detail.blade.php` (mount `crm.tasks-panel` in the Docs & Tasks tab)
- Test: `tests/Feature/Crm/TasksOnSeedingTest.php` (new)

**Interfaces:** consumes Task 1's `Task.seeding_campaign_id` + `SeedingCampaign::tasks()`. No new alias.

- [ ] **Step 1:** TasksPanel — copy the `DocumentsPanel` three-parent pattern exactly: add `public ?SeedingCampaign $seedingCampaign = null;`, include it in the `array_filter([...])` exactly-one check in `mount()`, add it to `quickAdd()`'s write (`'seeding_campaign_id' => $this->seedingCampaign?->id`), and add a third `->when($this->seedingCampaign !== null, fn ($q) => $q->where('seeding_campaign_id', $this->seedingCampaign->id))` to `tasksQuery()`.
- [ ] **Step 2:** TasksIndex — add `'seedingCampaign'` to the `tasksQuery()` eager-load; add `public string $task_seeding_campaign_id = '';` hydrated in `edit()`; validate `['nullable', 'integer', TenantRule::exists('seeding_campaigns', 'id')]` in `save()` and persist it; add a "Seeding run (optional)" select to the task modal blade with tenant seeding runs; show the run label in the board's anchor column.
- [ ] **Step 3:** seeding-detail blade — in the Docs & Tasks tab body (currently only `crm.documents-panel`), add `@livewire('crm.tasks-panel', ['seedingCampaign' => $seedingCampaign])` beneath the documents panel, mirroring `campaign-detail.blade.php`'s two-panel stack.
- [ ] **Step 4: Tests** (`TasksOnSeedingTest`): panel mounts with a seedingCampaign parent and `quickAdd` writes `seeding_campaign_id`; `tasksQuery` shows only that run's tasks; a crm.view-only user's `quickAdd` is forbidden; the board's seeding select validates `TenantRule::exists`; the seeding-detail page renders the tasks panel (`$this->get('/crm/seeding/'.$run->id)->assertSeeLivewire(TasksPanel::class)`).
- [ ] **Step 5:** `--filter=Tasks --filter=TasksOnSeeding` green, `--filter=Crm` + `--filter=CrmCopyLintTest`, pint, commit: `feat(crm): tasks can live on a seeding run — panel on the run's Docs & Tasks tab and a seeding option on the board`.

---

### Task 3: Communication-log context (F18) — Campaign + Seeding-run anchors on the creator comms form

**Files:**
- Modify: `app/Modules/CRM/Livewire/Creators/CommunicationLogPanel.php`
- Modify: `resources/views/livewire/crm/creator-communication-log.blade.php` (verify exact blade name via CrmServiceProvider alias `crm.creator-communication-log`)
- Test: `tests/Feature/Crm/CommunicationLogContextTest.php` (new)

**Interfaces:** consumes Task 1's `CommunicationLog.seeding_campaign_id` + `seedingCampaign()`. Fixes the dead `campaign_id` column at the same time.

- [ ] **Step 1:** CommunicationLogPanel — add `public string $log_campaign_id = '';` and `public string $log_seeding_campaign_id = '';`, hydrated in `edit()` from the model. In `save()`, validate both `['nullable', 'integer', TenantRule::exists('campaigns','id')]` / `TenantRule::exists('seeding_campaigns','id')` and include them in the `create()`/`update()` attributes (empty string → null). Keep `creator_id` required (it stays the primary anchor — the creator owns the panel). `validationAttributes()` maps `log_campaign_id => 'campaign'`, `log_seeding_campaign_id => 'seeding run'`.
- [ ] **Step 2:** render() passes `campaigns` (tenant, ordered) and `seedingRuns` (tenant, ordered by name). Blade: add two optional selects ("Campaign (optional)", "Seeding run (optional)") to the comms form with placeholder "No campaign" / "No seeding run".
- [ ] **Step 3: Tests:** logging a comms entry with a campaign + seeding run persists both; both optional (a log with neither still saves); foreign-tenant campaign/run id → validation error (`withTenant`); crm.view-only user's `save` forbidden.
- [ ] **Step 4:** `--filter=CommunicationLog --filter=CommunicationLogContext` green, `--filter=Crm` + lint, pint, commit: `feat(crm): note the campaign or seeding run an outreach was about on the communication log`.

---

### Task 4: Campaign brief (item 2 UI) — edit objective + markets, display on the campaign

**Files:**
- Modify: `app/Modules/CRM/Livewire/Campaigns/CampaignsIndex.php` (edit modal: objective + markets)
- Modify: `resources/views/livewire/crm/campaigns-index.blade.php` (edit-only brief fields)
- Modify: `resources/views/crm/campaign-detail.blade.php` (Overview: read-only brief)
- Test: `tests/Feature/Crm/CampaignBriefTest.php` (new)

**Interfaces:** consumes Task 1's `Campaign.objective` / `Campaign.markets`. Edit-only (mirrors the Stage C spend/status split — create stays minimal). Markets stored as a jsonb array, one market per line in the textarea.

- [ ] **Step 1:** CampaignsIndex — add `public string $campaign_objective = '';` and `public string $campaign_markets = '';` (a newline-joined string for the textarea). `edit()` hydrates: `$this->campaign_objective = $campaign->objective ?? ''; $this->campaign_markets = implode("\n", $campaign->markets ?? []);`. In `save()`, only on the **edit** path, validate `campaign_objective => ['nullable','string','max:2000']` and `campaign_markets => ['nullable','string','max:2000']`, then persist `objective` = the trimmed string or null, `markets` = `parseMarkets()` (split on `/\R/`, trim, drop empties → array, null when empty) — a private helper mirroring `BrandsIndex::parseLines`. Keep this inside the existing Task-1(Stage C) create/edit split so create never asks for a brief.
- [ ] **Step 2:** campaigns-index blade — inside the existing `@if ($editingCampaignId !== null)` region (next to Spend/Status), add an "Objective" textarea (`campaign_objective`, helper "What this campaign is trying to achieve.") and a "Markets" textarea (`campaign_markets`, helper "One market per line — e.g. Germany, Austria.").
- [ ] **Step 3:** campaign-detail Overview — add a read-only "Brief" card (only when `$campaign->objective` or `$campaign->markets`): objective as a paragraph, markets as `x-ui.badge` chips. Place it in the Overview tab near the key-facts/spend area.
- [ ] **Step 4: Tests:** editing sets objective + markets (array round-trips); markets textarea splits lines into an array and drops blanks; create modal shows no brief fields (`assertDontSeeHtml('id="campaign_objective"')` on `create`); the detail page shows the brief when set and omits the card when empty.
- [ ] **Step 5:** `--filter=Campaign --filter=CampaignBrief` green, `--filter=Crm` + lint, pint, commit: `feat(crm): give a campaign a short brief — objective and target markets, shown on its overview`.

---

### Task 5: CampaignWriter + brand-coherence guard (F14)

**Files:**
- Create: `app/Modules/CRM/Services/CampaignWriter.php`
- Create: `app/Modules/CRM/Exceptions/CampaignBrandLocked.php`
- Modify: `app/Modules/CRM/Livewire/Campaigns/CampaignsIndex.php` (route create/update through the writer; catch the exception)
- Modify: `app/Modules/CRM/Livewire/Campaigns/CampaignWizard.php` (route campaign create through the writer)
- Test: `tests/Feature/Crm/CampaignBrandGuardTest.php` (new)

**Interfaces produced:**
```php
final class CampaignWriter {
    public function createCampaign(array $attributes, AuditLogger $audit): Campaign; // Campaign::create + audit 'campaign.created'
    public function updateCampaign(Campaign $campaign, array $attributes, AuditLogger $audit): Campaign; // guard + update + audit
    // updateCampaign throws App\Modules\CRM\Exceptions\CampaignBrandLocked when
    //   isset($attributes['brand_id']) && (int)$attributes['brand_id'] !== $campaign->brand_id
    //   && $campaign->seedingCampaigns()->exists()
}
final class CampaignBrandLocked extends \RuntimeException {
    public static function forCampaign(Campaign $campaign, int $runCount): self;
}
```

**Rationale (from scout):** no `CampaignWriter` exists; the coherence invariant lives only on the seeding write path today, so editing a campaign's brand silently desyncs every run's denormalized `brand_id`. Housing the guard in a service means the current edit path (`CampaignsIndex`) and the create path (`CampaignWizard`) — and any future edit path — inherit it. Block-and-tell, never cascade (do NOT auto-rewrite child brand_ids).

- [ ] **Step 1: Failing test** (`CampaignBrandGuardTest`):

```php
public function test_changing_brand_is_blocked_when_the_campaign_has_seeding_runs(): void
{
    $this->actingAsCrmStaff();
    $campaign = Campaign::factory()->create();
    $otherBrand = Brand::factory()->create();
    SeedingCampaign::factory()->forCampaign($campaign)->create(['brand_id' => $campaign->brand_id]);

    Livewire::test(CampaignsIndex::class)
        ->call('edit', $campaign->id)
        ->set('campaign_brand_id', (string) $otherBrand->id)
        ->call('save')
        ->assertHasErrors(['campaign_brand_id']);

    $this->assertSame($campaign->brand_id, $campaign->fresh()->brand_id); // unchanged
}

public function test_changing_brand_is_allowed_when_no_seeding_runs_exist(): void
{
    $this->actingAsCrmStaff();
    $campaign = Campaign::factory()->create();
    $otherBrand = Brand::factory()->create();

    Livewire::test(CampaignsIndex::class)
        ->call('edit', $campaign->id)
        ->set('campaign_brand_id', (string) $otherBrand->id)
        ->call('save')
        ->assertHasNoErrors();

    $this->assertSame($otherBrand->id, $campaign->fresh()->brand_id);
}

public function test_editing_other_fields_with_runs_and_same_brand_succeeds(): void
{
    $this->actingAsCrmStaff();
    $campaign = Campaign::factory()->create();
    SeedingCampaign::factory()->forCampaign($campaign)->create(['brand_id' => $campaign->brand_id]);

    Livewire::test(CampaignsIndex::class)
        ->call('edit', $campaign->id)
        ->set('campaign_name', 'Renamed With Runs')
        ->call('save')
        ->assertHasNoErrors();

    $this->assertSame('Renamed With Runs', $campaign->fresh()->name);
}

public function test_writer_service_enforces_the_guard_directly(): void
{
    $this->actingAsCrmStaff();
    $campaign = Campaign::factory()->create();
    $otherBrand = Brand::factory()->create();
    SeedingCampaign::factory()->forCampaign($campaign)->create(['brand_id' => $campaign->brand_id]);

    $this->expectException(\App\Modules\CRM\Exceptions\CampaignBrandLocked::class);
    app(\App\Modules\CRM\Services\CampaignWriter::class)
        ->updateCampaign($campaign, ['brand_id' => $otherBrand->id], app(\App\Shared\Audit\AuditLogger::class));
}
```

- [ ] **Step 2:** Implement `CampaignBrandLocked` (message: `"This campaign has {$runCount} seeding run(s) under its current brand. Change their brand or move them before changing the campaign's brand."`) and `CampaignWriter`. `updateCampaign` guards, then `$campaign->update($attributes)`; audit `campaign.updated`; when `status` changed also audit `campaign.status_changed` (move that logic out of CampaignsIndex or keep it in the Livewire layer — keep the audit shape identical). `createCampaign` = `Campaign::create($attributes)` + audit `campaign.created` (no transaction of its own, so the wizard can call it inside its transaction).
- [ ] **Step 3:** CampaignsIndex::save — build `$attributes`, then for the editing path call the writer inside try/catch: `catch (CampaignBrandLocked $e) { throw ValidationException::withMessages(['campaign_brand_id' => $e->getMessage()]); }`. Create path calls `createCampaign`. Preserve the existing status/spend/brief handling by passing them in `$attributes`. Keep the `campaign.status_changed` audit behaviour exactly (whether it stays in the Livewire layer or moves into `updateCampaign`, assert it still fires).
- [ ] **Step 4:** CampaignWizard::commit — replace the inline `Campaign::create([...])` + audit with `$writer->createCampaign([...], $audit)` (method-inject `CampaignWriter` into `finish()`/`createNow()` alongside the existing `$audit`/`$guard`, or `app(CampaignWriter::class)` inside `commit()`). Behaviour identical (create is coherent by construction — the guard only fires on brand *change*).
- [ ] **Step 5:** run new test → green; `--filter=Campaign` (incl. `CampaignsCrudTest`, `CampaignWizardTest`, `CampaignFormSplitTest`, `CampaignBriefTest`) all green — fix any that set brand on edit expecting success without runs (they should still pass; only edit-with-runs-and-brand-change is newly blocked). `--filter=Crm`, pint, commit: `fix(crm): stop a campaign brand change from silently breaking its seeding runs — CampaignWriter guard`.

---

### Task 6: Alias-aware restriction matching (item 5a)

**Files:**
- Modify: `app/Modules/CRM/Services/BrandRestrictionGuard.php`
- Test: `tests/Feature/Crm/BrandRestrictionAliasTest.php` (new)

**Interfaces:** `assertNotRestricted(Creator, Brand)` and `restrictedCreatorIds(array, Brand)` become alias-aware; `restrictedCreatorIdsForName(array, string)` stays name-only. Signatures unchanged — callers (Stage C roster picker, copy-roster, wizard, F03 shipment auto-attach) inherit alias-awareness for free.

**Refactor (from scout's minimal-surface plan):** extract the shared needle assembly + predicate so both the throwing and bulk paths fold aliases together (they are currently duplicated inline):

```php
/** @return list<string> folded needles: brand name + aliases, lowercased/trimmed, unique, non-empty */
private function needlesForBrand(Brand $brand): array
{
    return collect([$brand->name, ...($brand->aliases ?? [])])
        ->map(fn ($n) => mb_strtolower(trim((string) $n)))
        ->filter()->unique()->values()->all();
}

/** True if any restricted entry (folded) intersects the needle set. */
private function restrictedByNeedles(?array $restrictedBrands, array $needles): bool
{
    return collect($restrictedBrands ?? [])
        ->map(fn ($n) => mb_strtolower(trim((string) $n)))
        ->intersect($needles)->isNotEmpty();
}

/** @return list<int> */
private function restrictedCreatorIdsForNeedles(array $creatorIds, array $needles): array
{
    if ($creatorIds === [] || $needles === []) { return []; }
    return BrandPreference::query()
        ->whereIn('creator_id', $creatorIds)
        ->get(['creator_id', 'restricted_brands'])
        ->filter(fn ($p) => $this->restrictedByNeedles($p->restricted_brands, $needles))
        ->pluck('creator_id')->unique()->values()->map(fn ($id) => (int) $id)->all();
}
```

Then: `assertNotRestricted` builds `$needles = $this->needlesForBrand($brand)` and throws when `$creator->brandPreferences()->get()` has any row where `restrictedByNeedles($row->restricted_brands, $needles)`. `restrictedCreatorIds(array $ids, Brand $brand)` calls `restrictedCreatorIdsForNeedles($ids, $this->needlesForBrand($brand))` (STOPS delegating to the name-only string method). `restrictedCreatorIdsForName(array $ids, string $name)` becomes `restrictedCreatorIdsForNeedles($ids, [mb_strtolower(trim($name))])` — name-only by design (the wizard's typed brand may not exist and has no aliases). Case-folding stays in PHP throughout.

- [ ] **Step 1: Failing test:**

```php
public function test_a_creator_who_restricts_a_brand_alias_is_now_flagged_and_blocked(): void
{
    $this->actingAsCrmStaff();
    $brand = Brand::factory()->create(['name' => 'Aurelia', 'aliases' => ['Aurelia Cosmetics', 'AC Beauty']]);
    $creator = Creator::factory()->create();
    BrandPreference::factory()->create(['creator_id' => $creator->id, 'restricted_brands' => ['ac beauty']]); // alias, not the name

    $guard = app(\App\Modules\CRM\Services\BrandRestrictionGuard::class);
    $this->assertSame([$creator->id], $guard->restrictedCreatorIds([$creator->id], $brand));
    $this->expectException(\App\Modules\CRM\Exceptions\BrandRestrictionViolation::class);
    $guard->assertNotRestricted($creator, $brand);
}

public function test_name_only_string_matcher_ignores_aliases(): void
{
    $this->actingAsCrmStaff();
    $creator = Creator::factory()->create();
    BrandPreference::factory()->create(['creator_id' => $creator->id, 'restricted_brands' => ['ac beauty']]);

    $guard = app(\App\Modules\CRM\Services\BrandRestrictionGuard::class);
    // The typed-name path (wizard, brand may not exist yet) matches the name only.
    $this->assertSame([], $guard->restrictedCreatorIdsForName([$creator->id], 'Aurelia'));
    $this->assertSame([$creator->id], $guard->restrictedCreatorIdsForName([$creator->id], 'AC Beauty'));
}

public function test_name_match_still_works_and_folding_is_unicode_safe(): void
{
    $this->actingAsCrmStaff();
    $brand = Brand::factory()->create(['name' => 'Müller', 'aliases' => null]);
    $creator = Creator::factory()->create();
    BrandPreference::factory()->create(['creator_id' => $creator->id, 'restricted_brands' => ['  MÜLLER ']]);

    $this->assertSame([$creator->id],
        app(\App\Modules\CRM\Services\BrandRestrictionGuard::class)->restrictedCreatorIds([$creator->id], $brand));
}
```

- [ ] **Step 2:** implement the refactor; run new test → green. Then `--filter=BrandRestriction --filter=CampaignRosterPicker --filter=SeedingRosterPicker --filter=SeedingRosterRepair` — all existing restriction/roster tests must stay green (aliases only ADD matches; name matching is unchanged). `--filter=Crm`, pint, commit: `feat(crm): brand no-go lists now also match a brand's other names (aliases)`.

---

### Task 7: BLOCKLISTED enforcement (item 5b) — the "do not contact" status finally blocks

**Files:**
- Modify: `app/Modules/CRM/Services/BrandRestrictionGuard.php` (add `blocklistedCreatorIds`)
- Modify: `app/Modules/CRM/Livewire/Concerns/ManagesCreatorRoster.php` (attachSelected soft-skip)
- Modify: `app/Modules/CRM/Livewire/Seeding/SeedingCreatorsPanel.php` (copyCampaignRoster soft-skip)
- Modify: `app/Modules/CRM/Livewire/Campaigns/CampaignWizard.php` (commit soft-skip)
- Modify: `app/Modules/CRM/Livewire/Seeding/ShipmentsPanel.php` (auto-attach hard block)
- Modify (REWRITE the now-wrong assertions): `tests/Feature/Crm/CampaignRosterPickerTest.php`, `tests/Feature/Crm/SeedingRosterPickerTest.php`
- Test: `tests/Feature/Crm/BlocklistEnforcementTest.php` (new)

**BEHAVIOUR CHANGE:** a `RelationshipStatus::Blocklisted` creator is currently flagged in the picker but still attachable (F06). After this task, an attach **skips** them (bulk paths) or **blocks** them (single shipment recipient). Two existing tests assert the old behaviour and MUST be rewritten.

**Interface:** `BrandRestrictionGuard::blocklistedCreatorIds(array $creatorIds): array` — `Creator::query()->whereIn('id', $ids)->where('relationship_status', RelationshipStatus::Blocklisted)->pluck('id')->map('intval')->all()` (tenant-scoped automatically; `relationship_status` is nullable, so `where(=, Blocklisted)` is null-safe).

- [ ] **Step 1:** add `blocklistedCreatorIds`. In `ManagesCreatorRoster::attachSelected`, after computing `$restrictedIds`, also compute `$blocklistedIds = $guard->blocklistedCreatorIds($ids)`; a creator is skipped if restricted OR blocklisted; keep the per-creator `assertNotRestricted` fallback for restrictions; build the skipped notice with two reasons ("… skipped with brand restrictions: …" and "… skipped — marked do not contact: …"), only the non-empty parts. Mirror in `SeedingCreatorsPanel::copyCampaignRoster` (add blocklisted to the excluded set) and `CampaignWizard::commit` (add blocklisted to `$restricted`/skip set; surface names on the Done screen alongside restricted). In `ShipmentsPanel` auto-attach, before the transaction, if the recipient is blocklisted throw `ValidationException::withMessages(['shipment_creator_id' => 'This creator is marked ‘do not contact or book’ and cannot receive a shipment.'])` (hard path, mirroring the restriction conversion).
- [ ] **Step 2: Rewrite** `CampaignRosterPickerTest::test_a_blocklisted_candidate_is_marked_but_still_selectable` (and the SeedingRosterPickerTest twin) to `test_a_blocklisted_candidate_is_skipped_at_attach`: still shown/selectable in the picker, but `attachSelected` skips it with a "do not contact" notice and it does NOT land on the roster.
- [ ] **Step 3: New tests** (`BlocklistEnforcementTest`): bulk attach skips a blocklisted creator with the distinct notice; `copyCampaignRoster` skips blocklisted; wizard commit skips blocklisted and reports the name on the Done screen; a shipment to a blocklisted recipient hard-errors on `shipment_creator_id`; a non-blocklisted creator with a matching brand restriction still skips for the restriction reason (both reasons coexist).
- [ ] **Step 4:** `--filter=RosterPicker --filter=Blocklist --filter=CampaignWizard --filter=Shipment --filter=Crm` green, pint, commit: `fix(crm): a creator marked ‘do not contact’ is now kept off rosters and shipments (was flagged only)`.

---

### Task 8: Re-check restrictions when one is added (item 5c) — warn about existing rosters, don't auto-remove

**Files:**
- Modify: `app/Modules/CRM/Livewire/Creators/BrandPreferencesPanel.php`
- Test: `tests/Feature/Crm/RestrictionRecheckTest.php` (new)

**Behaviour:** when a restriction is newly ADDED to a creator, surface a warning listing the creator's existing campaign/seeding rosters whose brand now matches (name or alias) — the operator decides what to do; **never auto-detach**.

- [ ] **Step 1:** BrandPreferencesPanel::save — compute `$added = new restricted_brands minus old restricted_brands` (folded compare). If non-empty, gather the creator's rosters: `$creator->campaigns()->with('brand')->get()` and `$creator->seedingCampaigns()->with('brand')->get()`; for each, fold its brand's name+aliases (reuse the guard — inject `BrandRestrictionGuard` and expose a small public `matchesAnyNeedle(Brand $brand, array $names): bool` OR compute inline with the same `mb_strtolower(trim)` rule) and collect those whose brand matches any newly-added restricted name. Persist the preference regardless (no detach). If matches exist, `dispatch('notify', type: 'warning'|'error', message: "Heads up: this creator is already on N roster(s) for a brand you just restricted: …")` — list ≤3 names + "and K more". (If `notify` supports only success/error, use `type: 'error'` with a "Heads up" message, or add a `warning` type to the toast component in this task — check the toast-area component first and prefer the least-change option.)
- [ ] **Step 2: Tests:** adding a restriction that matches a brand the creator is rostered on surfaces the warning with the roster name; adding an unrelated restriction does not warn; the preference is saved either way (assert `restricted_brands` persisted); an alias-only match also warns (folds aliases). crm.manage still required.
- [ ] **Step 3:** `--filter=BrandPreference --filter=RestrictionRecheck --filter=Crm` + lint, pint, commit: `feat(crm): adding a no-go brand warns you which rosters already include that creator`.

---

### Task 9: Read-only seeding progress strip (item 6a)

**Files:**
- Modify: `app/Modules/CRM/routes.php` (seeding.show closure — add constrained shipment sub-counts)
- Modify: `resources/views/crm/seeding-detail.blade.php` (Overview: progress strip)
- Test: `tests/Feature/Crm/SeedingProgressStripTest.php` (new)

**Derivations (live from the Shipment table, never rollups):** Roster = `creators_count`; Shipped = shipments with `status IN (Shipped, InTransit, Delivered) OR shipped_at IS NOT NULL`; Delivered = `status = Delivered OR delivered_at IS NOT NULL`; Posted = `posted = true`; Expected = `posting_required = true` count, fallback to total shipments. Read-only — never writes `posted`/`posted_at`.

- [ ] **Step 1:** in the `seeding.show` route closure, extend `loadCount` with constrained aliases:

```php
$seedingCampaign->loadCount([
    'creators', 'shipments',
    'shipments as shipped_count' => fn ($q) => $q->where(fn ($w) => $w->whereIn('status', [ShipmentStatus::Shipped, ShipmentStatus::InTransit, ShipmentStatus::Delivered])->orWhereNotNull('shipped_at')),
    'shipments as delivered_count' => fn ($q) => $q->where(fn ($w) => $w->where('status', ShipmentStatus::Delivered)->orWhereNotNull('delivered_at')),
    'shipments as posted_count' => fn ($q) => $q->where('posted', true),
    'shipments as expected_posts_count' => fn ($q) => $q->where('posting_required', true),
]);
```

(Import `ShipmentStatus`. Keep the existing `->load([...])`.)

- [ ] **Step 2:** seeding-detail Overview — add a read-only progress strip (a labeled row `Roster N · Shipped X/N · Delivered Y/N · Posted Z/expected` + a proportional bar) that renders when `$seedingCampaign->shipments_count > 0`. Copy uses "seeding run" vocabulary, no banned tokens, curly apostrophes. Note in a small caption that "Posted updates after monitoring matches the content" (plain words — no "rollup").
- [ ] **Step 3: Tests** (`SeedingProgressStripTest`): a run with mixed shipment states renders the right counts (seed shipments with explicit statuses/timestamps, hit `/crm/seeding/{id}`, assert the strip counts); a run with zero shipments does not render the strip; posted count reflects the `posted` flag. Assert against rendered text.
- [ ] **Step 4:** `--filter=SeedingProgressStrip --filter=Crm` + lint, pint, commit: `feat(crm): a seeding run shows live progress — roster, shipped, delivered, posted`.

---

### Task 10: Suggestion banners (item 6b) — campaign + seeding one-click next-step

**Files:**
- Create: `app/Modules/CRM/Livewire/Campaigns/CampaignStatusActions.php` + `resources/views/livewire/crm/campaign-status-actions.blade.php`
- Create: `app/Modules/CRM/Livewire/Seeding/SeedingStatusActions.php` + `resources/views/livewire/crm/seeding-status-actions.blade.php`
- Modify: `app/Modules/CRM/CrmServiceProvider.php` (register `crm.campaign-status-actions`, `crm.seeding-status-actions`)
- Modify: `resources/views/crm/campaign-detail.blade.php` + `resources/views/crm/seeding-detail.blade.php` (mount on Overview)
- Test: `tests/Feature/Crm/StatusSuggestionTest.php` (new)

**Behaviour:** at most one suggestion at a time; a `wire:click` action with `wire:confirm`; `authorize('update', $model)`; set status; audit; notify. No scheduler.

Campaign suggestions (compute one, in priority order): `Draft` + `creators_count > 0` → "Roster set — mark as Planned?" (→ Planned); `Planned` + `start_at !== null && start_at <= now()` → "Start date reached — start the campaign?" (→ Active); `Active` + `end_at !== null && end_at < now()` → "End date passed — mark as Completed?" (→ Completed). Seeding suggestion: `status !== Completed && shipments_count > 0 && all shipments Delivered` → "All shipments delivered — mark the run as Completed?" (→ Completed). Add a `seeding_campaign.status_changed` audit event (parity with campaign).

- [ ] **Step 1:** CampaignStatusActions — `public Campaign $campaign;` `mount(Campaign $campaign)` authorizes `view`; a `suggestion(): ?array` computes `['label' => …, 'next' => CampaignStatus, 'action' => 'applyStatus']`; `applyStatus(AuditLogger $audit)` re-authorizes `update`, guards that the suggestion still holds, sets status, audits `campaign.status_changed` (from/to), notifies. render() passes the suggestion. Blade: `@if ($suggestion)` an `x-ui.alert variant="info"` with a `wire:click="applyStatus" wire:confirm="…"` button. SeedingStatusActions mirrors it (uses `SeedingCampaignStatus`, the all-delivered check, `seeding_campaign.status_changed`).
- [ ] **Step 2:** register aliases; mount `@livewire('crm.campaign-status-actions', ['campaign' => $campaign])` on campaign-detail Overview (top), `@livewire('crm.seeding-status-actions', ['seedingCampaign' => $seedingCampaign])` on seeding-detail Overview.
- [ ] **Step 3: Tests** (`StatusSuggestionTest`): a Draft campaign with a roster shows the "Planned?" suggestion and `applyStatus` moves it to Planned + audits; a Planned campaign whose start_at is in the past shows "start?"; a campaign with no trigger shows no banner; `applyStatus` requires crm.manage (view-only → forbidden); a stale suggestion (status changed underneath) is a no-op; a seeding run with all shipments delivered shows "Completed?" and applying it audits `seeding_campaign.status_changed`.
- [ ] **Step 4:** `--filter=StatusSuggestion --filter=Crm` + lint, pint, commit: `feat(crm): gentle next-step prompts — start a campaign, complete it, close a finished seeding run`.

---

### Task 11: Shipment field→status suggestion + relationship auto-suggest (item 6c/d, §2.5)

**Files:**
- Modify: `app/Modules/CRM/Livewire/Seeding/ShipmentsPanel.php` (dates → status one-tap hint)
- Modify: `app/Modules/CRM/Livewire/Creators/CommunicationLogPanel.php` (outbound → "Mark as Contacted?")
- Modify their blades
- Test: `tests/Feature/Crm/ShipmentStatusHintTest.php`, `tests/Feature/Crm/RelationshipAutoSuggestTest.php` (new)

- [ ] **Step 1: Shipment hint.** In ShipmentsPanel, a computed `statusHint(): ?array` — when `shipment_shipped_at` is set but `shipment_status` is below Shipped → suggest Shipped; when `shipment_delivered_at` is set but status below Delivered → suggest Delivered. Render an inline "Set status to Shipped/Delivered?" button (`wire:click="acceptStatusHint('SHIPPED')"`) that just sets `shipment_status` (saved via the existing `save()`). Suggestion only — never auto-set. (Keep the Stage C progressive-form field visibility intact.)
- [ ] **Step 2: Relationship suggest.** In CommunicationLogPanel, after a successful `save()` where `direction === 'outbound'` and `$this->creator->relationship_status` ∈ {None, Prospect, null}, set `public bool $suggestContacted = true;`. Blade: a dismissible `x-ui.alert` "Mark {creator} as Contacted?" with `wire:click="markContacted"` (authorize `update` on creator, set `relationship_status = Contacted`, audit `creator.updated` or a `creator.relationship_changed`, clear the flag) and a "Not now" dismiss.
- [ ] **Step 3: Tests:** setting shipped_at with a Pending status surfaces the hint and accepting it sets Shipped (then saves); logging an outbound comms for a Prospect creator surfaces the suggestion and `markContacted` sets Contacted + audits; logging outbound for an already-Active creator shows no suggestion; `markContacted` requires crm.manage.
- [ ] **Step 4:** `--filter=ShipmentStatusHint --filter=RelationshipAutoSuggest --filter=Crm` + lint, pint, commit: `feat(crm): one-tap status nudges — mark a parcel shipped/delivered, mark a contacted creator Contacted`.

---

### Task 12: ADR-0027 (release note) + final sweep + review handoff

**Files:**
- Modify: `docs/05-decisions/decision-log.md` (add ADR-0027)
- Modify: `.superpowers/sdd/progress.md`
- Create: `reviews/REVIEW-crm-stage-d-2026-07-17.md`

- [ ] **Step 1: ADR-0027** in `docs/05-decisions/decision-log.md` following the house format (`## ADR-0027 — CRM lifecycle & restriction hardening`, Context / Decision / Status APPROVED / Consequences). Record the three behaviour changes: (a) brand no-go matching now folds brand aliases (attaches that passed only because the alias — not the canonical name — was on a no-go list are now blocked); (b) BLOCKLISTED relationship status is now enforced at attach (skip in bulk, block for shipments) where it was display-only; (c) a campaign's brand can no longer be changed while it has seeding runs. Also note the additive columns (tasks/comms `seeding_campaign_id`, campaigns `objective`/`markets`). This file is `docs/`, NOT a blade — spec IDs are fine here; never in a blade.
- [ ] **Step 2: Full-suite gate.** `XDEBUG_MODE=off php artisan test` — 0 failures, total ≥ 1124 + new tests; record the number. `--filter=CrmCopyLintTest` green (all new Stage D blades scanned). `--filter=Reach` green (the 78 reach tests — a full-suite gate, no coupling).
- [ ] **Step 3: Spec acceptance self-check** (fix before committing): F14 brand change blocked with runs; F06 BLOCKLISTED + alias matching enforced; F18 tasks + comms anchor a seeding run; F07/F27 statuses have a progress strip + next-step prompts; F24 relationship auto-suggest; item 2 campaign brief present. Migrations additive; `crm.index` and all routes still resolve.
- [ ] **Step 4:** write `reviews/REVIEW-crm-stage-d-2026-07-17.md` (Stage B/C handoff shape: branch + commit range + suite number + what shipped per task + a checkbox list for a fresh adversarial pass — at minimum: migration composite-tenant-FK correctness + rollback; CampaignWriter guard completeness across BOTH write paths; alias-matcher parity between `assertNotRestricted` and the bulk matchers + the name-only string path staying name-only; BLOCKLISTED enforcement across all four attach sites incl. the hard/soft asymmetry; re-check-warning query cost + no-auto-detach; progress-strip N+1/live-vs-rollup; suggestion-banner stale-state guards + authorize; comms/tasks tenant scoping on the new anchors; the two rewritten blocklist tests). Note the merge-coordination: Stage D edits `seeding-detail`/`campaign-detail` blades and `ShipmentsPanel`/`CampaignWizard`/`ManagesCreatorRoster` again — all already on main from Stage C, no external branch in flight.
- [ ] **Step 5:** `vendor/bin/pint --dirty`; commit: `docs(adr): ADR-0027 CRM lifecycle & restriction hardening + Stage D review handoff`.

---

## Self-review (spec §Stage D → tasks)

| Spec Stage D item | Task |
|---|---|
| 1. tasks/comms `seeding_campaign_id` + tasks panel on seeding detail | 1 (schema), 2 (tasks UI), 3 (comms UI) |
| 2. campaigns `objective` + `markets` (lightweight brief) | 1 (schema), 4 (UI) |
| 3. **F03 repair** | ALREADY DONE (Stage A) — verify only, do not redo |
| 4. brand-coherence guard (CampaignWriter) | 5 |
| 5. restriction hardening (aliases + re-check-on-add + BLOCKLISTED enforcement) | 6 (aliases), 7 (blocklist), 8 (re-check) |
| 6. status suggestions (progress strip + banners) | 9 (strip), 10 (campaign/seeding banners), 11 (shipment/relationship) |
| Permissions: no new classes; writes behind crm.manage, reads crm.view | every task |
| Release-note the behaviour changes | 12 (ADR-0027) |

**Type-consistency check:** `seeding_campaign_id` is the column name on tasks/communication_logs (matching `DocumentAttachment`). `CampaignWriter::createCampaign/updateCampaign` are consumed by Tasks 5 (CampaignsIndex, CampaignWizard). `BrandRestrictionGuard::needlesForBrand/restrictedCreatorIdsForNeedles` (private) back the public `assertNotRestricted`/`restrictedCreatorIds(Brand)` (alias-aware) and `restrictedCreatorIdsForName` (name-only) consumed by Tasks 6/7 and every Stage C attach site. `blocklistedCreatorIds` (Task 7) is consumed by the four attach paths. Status-write audit events: `campaign.status_changed` (exists), new `seeding_campaign.status_changed` (Task 10).

**Known intentional non-goals:** no automatic status transitions (all suggestions are one-click + confirm); no cascade of a campaign brand change onto its runs (block-and-tell); no new comms-log panel on the seeding page (creator_id is required); no scheduler; no DB CHECK for brand coherence (service guard per spec); global search and multi-currency remain out of scope.


