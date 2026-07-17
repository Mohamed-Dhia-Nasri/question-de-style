# CRM Stage C — Guided Flows, CRM Home, Roster Picker: Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A brand-new tenant can go from zero to a seeding run with attached creators without ever leaving a flow to create a prerequisite: new `/crm` Overview with a dynamic setup checklist, inline "+ create" inside parent selects, a searchable multi-select roster picker with pre-submit restriction warnings and "Copy campaign roster", a "New seeding run" button on campaign detail, an optional campaign wizard, CSV creator import through `CreatorWriter`, and create/edit form splits (Spend/Status leave create modals; shipment form reveals fields progressively). Also lands the ClientViewer decision (see Decision Record below).

**Architecture:** Pure Livewire 3 + Blade + Alpine on the existing CRM module. **No migrations** (the F03 pivot repair already landed in Stage A). New write paths reuse the exact existing per-entity validation rules, `TenantRule::exists`, policies, `AuditLogger` events, and `notify` toasts. Two new shared traits (`WithInlineCreate`, `ManagesCreatorRoster`), one new bulk method on `BrandRestrictionGuard`, one optional-param extension on `CreatorWriter`. Everything else is components + blades.

**Tech Stack:** Laravel 11, Livewire 3, Alpine.js, Tailwind v4 (class-based dark mode), PHPUnit 11 (NOT Pest), PostgreSQL test DB on port 5433.

**Spec:** `docs/superpowers/specs/2026-07-16-crm-ux-redesign-audit-and-plan.md` §Stage C (line 318). Fixes F01, F02, F04 (UX half), F08, F10, F11 (fully), F12, F28.
**Already done elsewhere (do NOT redo):** F03 backfill + shipment auto-attach (Stage A, `ShipmentsPanel.php:160-215`); removal of the false "proposed by Monitoring or Discovery" empty-state promise (Stage A commit `bcf164a` — current creators empty state is already correct).

## Global Constraints

- Test command: `XDEBUG_MODE=off php artisan test` (PHPUnit 11; targeted: `--filter=ClassName`). Baseline at branch start: **1035 passed / 0 failed** on `feat/crm-ux-stage-c` = main `1475d25` (verified).
- Formatter before every commit: `vendor/bin/pint --dirty`.
- Commit per task, conventional subject `feat(crm): … — elaboration`, **NO Co-Authored-By trailer, no AI attribution, ever** (user directive; the Stage A plan's contrary line is obsolete).
- `git add` only the exact files you touched. Never `git add -A`. **Never run `migrate:fresh`** or any DB-wiping artisan command (a Stage A subagent once wiped the dev DB).
- Copy lint: every new/changed blade under `resources/views/crm`, `resources/views/livewire/crm`, `resources/views/components/crm`, `components/metric`, `components/states` must pass `tests/Feature/Ui/CrmCopyLintTest`. Banned in rendered copy AND in `title/reason/aria-label/placeholder/label` attribute values: spec IDs (`ADR-…`, `REQ-…`, `AC-…`, `DP-…`), `rollup`, `authoritative`, `v1`, `Module N`, `Step N`, `phase PN`, `Seeding campaign`, `variant(s)` unless written exactly `Product variant`, `hard filter(s)`, `operator-managed`, `before commit`, `stored at tier`, `agency input`. **Wizard/checklist labels must not say "Step 1"** — use `1 · Client & brand` style. Curly apostrophes (’) in user-facing copy.
- Controlled vocabulary: entity = "seeding run"; index = "Seeding runs"; field = "Seeding type" (never Variant); "Sector" not Category; dates column = "Dates". Identifiers stay engineering-named (`SeedingCampaign`, `seeding_campaign_id`, `crm.seeding.*`) — never rename identifiers, aliases, or route names.
- New routes go in `app/Modules/CRM/routes.php` inside the existing group `Route::middleware(['web','auth','can:'.PermissionsCatalog::CRM_VIEW,'subscribed'])->prefix('crm')->as('crm.')`. New Livewire components registered explicitly in `CrmServiceProvider::boot()` as `crm.<kebab>`. Full page loads only — no `wire:navigate`.
- Every mutating Livewire action: `$this->authorize(...)` inside the action (crm.manage is NEVER route middleware), FK validation via `TenantRule::exists('table','id')` (plain `Rule::exists` is a cross-tenant oracle), `AuditLogger::record('entity.event', $model, [...])`, `$this->dispatch('notify', type: 'success'|'error', message: '…')`.
- Modals are server-driven: rendered under `@if($someState)`, closed via a wire method — never Alpine-toggled. `x-ui.button` defaults `type="button"`; submits need explicit `type="submit"`. Livewire id-bearing form props are `string` with `''` = empty; checkbox arrays bind **string** ids.
- Dark mode is class-based: every new class needs `dark:` variants (standard pairs: `border-gray-200/dark:border-gray-800`, `bg-white/dark:bg-white/[0.03]`, `text-gray-800/dark:text-white/90`, `text-gray-500/dark:text-gray-400`).
- Attach through relations only (`$owner->creators()->syncWithoutDetaching(...)`) — they stamp pivot `tenant_id` via `withPivotValue`; never raw `DB::table` pivot inserts; never attach via a model instance whose `tenant_id` is not loaded.
- Tests: `use RefreshDatabase;` + `$this->seedRoles();` + `$this->actingAs($this->makeUser(RoleName::InfluencerRelationsManager))` (staff), `RoleName::ClientViewer` = refused role, foreign tenant via `$this->withTenant($this->makeTenant('Tenant B'), fn () => …)` → expect 404/assertDontSee. New CRM feature tests live flat in `tests/Feature/Crm/`.
- Update `.superpowers/sdd/progress.md` after each task (Stage C header already exists at EOF).
- Do NOT touch `resources/views/livewire/monitoring/creator-detail.blade.php` (Monitoring UI beyond cross-links is out of scope; that file also awaits the monitoring-settings adversarial review). Sidebar edits happen ONLY in Task 12.

## Decision Record — ClientViewer zero navigation (from reviews/REVIEW-crm-stage-b-2026-07-17.md:16)

**Decision: keep the role dormant; zero sidebar navigation is correct and intentional.** ADR-0016 (APPROVED, unsuperseded through ADR-0026; reaffirmed by ADR-0020/0021) defines CLIENT_VIEWER as deny-everything with a containment shell: `/reports` is its only page and `LoginResponse` already lands it there — the sidebar entry Stage B removed was cosmetic. Restoring a nav entry gated `@can('reports.view-approved')` would re-show a permanently-empty link to every staff role (they all hold that permission — the exact noise Stage B removed), and exposing `/crm/results` would violate REQ-M3-012 and require a superseding ADR. What Stage C DOES fix (Task 12) is the real inconsistency: the admin Users form still offers ClientViewer in its role dropdown (`UsersIndex.php:152,334`) while the invitation flow blocks non-staff roles per ADR-0016 (`TeamInvitationsPanel.php:44-46`) — we align Users to staff-only (existing ClientViewer users stay editable), and make the sidebar/header logo links role-aware so a ClientViewer session isn’t sent to a 403. No ADR change needed — this enforces the existing ADR.

## Design decisions locked by this plan

1. **Inline create = same-component state via a shared trait** (`WithInlineCreate`) + one shared Blade component (`x-crm.inline-create`), NOT a nested Livewire child: zero `#[On]` precedent exists, option lists rebuild in `render()` on every request, and server-driven state survives DOM morphing. The double-Escape problem (both open modals listen `@keydown.escape.window`) is solved server-side: each host’s `cancelForm()` first delegates to `cancelInlineCreate()` when an inline form is open.
2. **Roster picker = shared trait** (`ManagesCreatorRoster`) + shared Blade partial, wired into the two existing panels (aliases/classes keep their names). Pre-submit restriction flags come from a new **bulk, non-throwing** `BrandRestrictionGuard::restrictedCreatorIdsForName()` that replicates the guard’s exact PHP matching (`mb_strtolower(trim(…))`, union across ALL BrandPreference rows, batch `whereIn` query — never per-row guard calls, never SQL `lower()`). `assertNotRestricted()` stays the unchanged enforcement path at attach time; restricted creators are selectable but **skipped** at save with a named report (spec: flagged before submit, blocked at save).
3. **Create/edit splits stay in the existing index components**, branching on `$editing… === null`: create paths must not read the status/spend Livewire props at all (they are client-tamperable) — status is forced `Draft` server-side, spend forced null.
4. **Seeding create-from-campaign is a new slim panel component** (`SeedingRunCreatePanel`) on the campaign detail Seeding tab — brand and campaign ids come from the mounted `$campaign` server-side (never from user input, so the brand-coherence guards hold by construction), asks only Name + Seeding type + optional product, then redirects to the new run’s detail page.
5. **Wizard = one full-page Livewire component** at `/crm/campaigns/new` (route registered BEFORE `/campaigns/{campaign}` so the literal segment wins), all writes in ONE `DB::transaction` on finish, Draft status forced, ends on an in-wizard Done screen (so skipped-restricted-creator names are never lost to a redirect).
6. **CSV import parses the Livewire temp upload directly** (no permanent storage), previews with per-row verdicts, imports row-by-row in per-row transactions through `CreatorWriter` (`createCreator` + `addManualPlatformAccount` per handle, mirroring `CreatorProposalIntake::propose`), so Monitoring auto-enrollment keeps working and a handle conflict rolls back only its own row. Handles are normalized (trim + strip one leading `@`). Cap: 200 data rows.
7. **No migrations, no schema, no new permissions.** Overview reads stay behind `crm.view`; all writes behind existing policies (crm.manage).

## Shared conventions cheat-sheet (verified file:line)

- Page skeleton: `<x-layouts.app title="…">` + `<x-page-header :title :breadcrumbs="['Dashboard' => route('dashboard'), 'CRM' => route('crm.index'), '…' => null]" />` (+ optional `<x-slot:actions>`), then `@livewire('crm.xxx')`. Registration: `Livewire::component('crm.xxx', Class::class)` in `CrmServiceProvider::boot()` (lines 113-151).
- Modal: `@if($show…) <x-ui.modal :title close-action="cancelX" max-width="md|lg|xl|2xl"> <form wire:submit="save" class="space-y-5">…</form> <x-slot:footer> Cancel (wire:click) + primary button wire:click="save" with dual `wire:loading` spans </x-slot:footer> </x-ui.modal> @endif`.
- Confirm: `x-ui.confirm-modal` + `?int $confirmingXId` trio. `wire:confirm` is used nowhere — don’t introduce it.
- Empty state: `<x-states.empty title="…">body</x-states.empty>` + `<x-slot:action>` (button when action is on-screen, `Go to X →` brand link when it’s another page).
- Card recipe: `rounded-2xl border border-gray-200 bg-white p-6 dark:border-gray-800 dark:bg-white/[0.03]`; nested section `rounded-xl border border-gray-200 p-4 dark:border-gray-800`; stat value `mt-1 text-2xl font-semibold text-gray-800 dark:text-white/90`.
- Checklist markup (copy from `crm/campaign-detail.blade.php:47-74`): ✓ circle `bg-success-50 text-success-600 dark:bg-success-500/10`, ○ circle `bg-gray-100 text-gray-400 dark:bg-white/5`, done rows `text-gray-400 line-through`.
- Status-select live description: `render()` passes `'…Descriptions' => collect(Enum::cases())->mapWithKeys(fn ($s) => [$s->value => $s->description()])->all()`; blade wraps select in `<div x-data="{ s: @js($prop), map: @js($map) }">` + `<p x-text="map[s] ?? ''">` — move the WHOLE wrapper when reordering fields.
- Search inputs: `wire:model.live.debounce.300ms`. Currency label: `app(\App\Shared\Support\TenantCurrency::class)->code()`.
- Followers: `$account->follower_count?->amount` (nullable MetricValue envelope); display via `\Illuminate\Support\Number::abbreviate((int) $amount)`.

---

### Task 1: Campaign create/edit split — Spend and Status leave the create modal (F28a)

**Files:**
- Modify: `app/Modules/CRM/Livewire/Campaigns/CampaignsIndex.php` (save() ~lines 140-189)
- Modify: `resources/views/livewire/crm/campaigns-index.blade.php` (modal ~lines 109-176)
- Test: `tests/Feature/Crm/CampaignFormSplitTest.php` (new)

**Interfaces:**
- Consumes: existing `CampaignsIndex::create()` (already presets `campaign_status = Draft->value`), `save(AuditLogger)`, `CampaignStatus`, `TenantRule`.
- Produces: create path forcing `status=Draft`, `spend=null` regardless of Livewire prop tampering. Task 10 (wizard) copies this Draft-forcing convention.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Crm;

use App\Modules\CRM\Livewire\Campaigns\CampaignsIndex;
use App\Modules\CRM\Models\Brand;
use App\Modules\CRM\Models\Campaign;
use App\Shared\Enums\CampaignStatus;
use App\Shared\Enums\RoleName;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class CampaignFormSplitTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsCrmStaff(): void
    {
        $this->seedRoles();
        $this->actingAs($this->makeUser(RoleName::InfluencerRelationsManager));
    }

    public function test_create_saves_as_draft_and_ignores_tampered_status_and_spend(): void
    {
        $this->actingAsCrmStaff();
        $brand = Brand::factory()->create();

        Livewire::test(CampaignsIndex::class)
            ->call('create')
            ->set('campaign_name', 'Spring Push')
            ->set('campaign_brand_id', (string) $brand->id)
            ->set('campaign_status', CampaignStatus::Active->value) // tampering — must be ignored
            ->set('campaign_spend', '999')                          // tampering — must be ignored
            ->call('save')
            ->assertHasNoErrors();

        $campaign = Campaign::query()->where('name', 'Spring Push')->firstOrFail();
        $this->assertSame(CampaignStatus::Draft, $campaign->status);
        $this->assertNull($campaign->spend);
    }

    public function test_create_modal_shows_no_status_or_spend_fields(): void
    {
        $this->actingAsCrmStaff();
        Brand::factory()->create();

        Livewire::test(CampaignsIndex::class)
            ->call('create')
            ->assertDontSeeHtml('id="campaign_status"')
            ->assertDontSeeHtml('id="campaign_spend"');
    }

    public function test_edit_modal_still_edits_status_and_spend(): void
    {
        $this->actingAsCrmStaff();
        $campaign = Campaign::factory()->create();

        Livewire::test(CampaignsIndex::class)
            ->call('edit', $campaign->id)
            ->assertSeeHtml('id="campaign_status"')
            ->assertSeeHtml('id="campaign_spend"')
            ->set('campaign_status', CampaignStatus::Paused->value)
            ->set('campaign_spend', '1500')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(CampaignStatus::Paused, $campaign->fresh()->status);
        $this->assertSame(1500.0, $campaign->fresh()->spend->amount);
    }
}
```

- [ ] **Step 2: Run it — expect FAIL** (`assertDontSeeHtml('id="campaign_status"')` fails; tampered status persists as Active).

Run: `XDEBUG_MODE=off php artisan test --filter=CampaignFormSplitTest`

- [ ] **Step 3: Implement the component split.** In `CampaignsIndex::save()`, branch the rules and force the create-path values (keep the existing authorize/audit/notify code untouched):

```php
$creating = $this->editingCampaignId === null;

$rules = [
    'campaign_name' => ['required', 'string', 'max:255'],
    'campaign_brand_id' => ['required', 'integer', TenantRule::exists('brands', 'id')],
    'campaign_start_at' => ['nullable', 'date'],
    'campaign_end_at' => ['nullable', 'date', 'after_or_equal:campaign_start_at'],
];

if (! $creating) {
    $rules['campaign_status'] = ['required', Rule::in(array_column(CampaignStatus::cases(), 'value'))];
    $rules['campaign_spend'] = ['nullable', 'numeric', 'min:0'];
}

$validated = $this->validate($rules);

if ($creating) {
    // Never read the client-tamperable props on create.
    $validated['campaign_status'] = CampaignStatus::Draft->value;
    $validated['campaign_spend'] = '';
}
```

Then keep the existing downstream code reading from `$validated` (adapt the current body which reads validated keys — spend MetricValue conversion and status audit stay as-is; the `campaign.status_changed` audit only fires on edit, which is already the case).

- [ ] **Step 4: Blade.** Wrap the Status block (the whole Alpine `x-data` wrapper div, ~lines 130-140) and the Spend block (~159-167) in `@if ($editingCampaignId !== null) … @endif`. Make the Brand select full-width on create (the 2-col grid can stay; when status is hidden, Brand simply occupies its column alone — acceptable; or move Brand out of the grid — implementer’s choice, keep it visually clean). Add one helper line inside the create modal below the fields: `<p class="text-xs text-gray-500 dark:text-gray-400">New campaigns start as a draft — you can change the status and record spend once it’s set up.</p>` (curly apostrophe).

- [ ] **Step 5: Run the new test — expect PASS.** Also update any existing test that sets `campaign_status`/`campaign_spend` during a `create` flow (check `tests/Feature/Crm/CampaignsCrudTest.php` — move those assertions to edit flows or drop the sets; keep coverage intent).

Run: `XDEBUG_MODE=off php artisan test --filter=CampaignFormSplitTest && XDEBUG_MODE=off php artisan test --filter=Campaigns`

- [ ] **Step 6: Regression + format + commit**

```bash
XDEBUG_MODE=off php artisan test --filter=Crm
vendor/bin/pint --dirty
git add app/Modules/CRM/Livewire/Campaigns/CampaignsIndex.php resources/views/livewire/crm/campaigns-index.blade.php tests/Feature/Crm/CampaignFormSplitTest.php tests/Feature/Crm/CampaignsCrudTest.php
git commit -m "feat(crm): campaign create asks less — new campaigns start as drafts, spend moves to edit"
```

---

### Task 2: Seeding run form overhaul — field order, live brand, brand-filtered products, create split (F12)

**Files:**
- Modify: `app/Modules/CRM/Livewire/Seeding/SeedingCampaignsIndex.php` (form props ~44-64, create() ~122-129, save() ~163-239, render() ~312-333)
- Modify: `resources/views/livewire/crm/seeding-campaigns-index.blade.php` (modal ~118-211)
- Test: `tests/Feature/Crm/SeedingFormBehaviourTest.php` (new)

**Interfaces:**
- Consumes: `SeedingType`, `SeedingCampaignStatus`, `TenantRule`, existing brand-coherence checks in `save()` (lines 183-201 — keep them).
- Produces: `updatedSeedingBrandId(): void` (resets `seeding_product_id`, `seeding_campaign_id`); `render()` products filtered by brand; create path forces Draft + no spend. Tasks 5 and 8 rely on the brand select being `wire:model.live`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Crm;

use App\Modules\CRM\Livewire\Seeding\SeedingCampaignsIndex;
use App\Modules\CRM\Models\Brand;
use App\Modules\CRM\Models\Product;
use App\Modules\CRM\Models\SeedingCampaign;
use App\Shared\Enums\RoleName;
use App\Shared\Enums\SeedingCampaignStatus;
use App\Shared\Enums\SeedingType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SeedingFormBehaviourTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsCrmStaff(): void
    {
        $this->seedRoles();
        $this->actingAs($this->makeUser(RoleName::InfluencerRelationsManager));
    }

    public function test_switching_brand_resets_product_and_parent_campaign(): void
    {
        $this->actingAsCrmStaff();
        $brandA = Brand::factory()->create();
        $brandB = Brand::factory()->create();
        $productA = Product::factory()->create(['brand_id' => $brandA->id]);

        Livewire::test(SeedingCampaignsIndex::class)
            ->call('create')
            ->set('seeding_brand_id', (string) $brandA->id)
            ->set('seeding_product_id', (string) $productA->id)
            ->set('seeding_brand_id', (string) $brandB->id)
            ->assertSet('seeding_product_id', '')
            ->assertSet('seeding_campaign_id', '');
    }

    public function test_product_options_are_filtered_to_the_chosen_brand(): void
    {
        $this->actingAsCrmStaff();
        $brandA = Brand::factory()->create();
        $brandB = Brand::factory()->create();
        $productA = Product::factory()->create(['brand_id' => $brandA->id, 'name' => 'Alpha Serum']);
        Product::factory()->create(['brand_id' => $brandB->id, 'name' => 'Beta Balm']);

        Livewire::test(SeedingCampaignsIndex::class)
            ->call('create')
            ->set('seeding_brand_id', (string) $brandA->id)
            ->assertSee('Alpha Serum')
            ->assertDontSee('Beta Balm');
    }

    public function test_create_saves_as_draft_and_ignores_tampered_status_and_spend(): void
    {
        $this->actingAsCrmStaff();
        $brand = Brand::factory()->create();

        Livewire::test(SeedingCampaignsIndex::class)
            ->call('create')
            ->set('seeding_name', 'Autumn Gifting')
            ->set('seeding_type', SeedingType::Gifting->value)
            ->set('seeding_brand_id', (string) $brand->id)
            ->set('seeding_status', SeedingCampaignStatus::Active->value)
            ->set('seeding_spend', '500')
            ->call('save')
            ->assertHasNoErrors();

        $run = SeedingCampaign::query()->where('name', 'Autumn Gifting')->firstOrFail();
        $this->assertSame(SeedingCampaignStatus::Draft, $run->status);
        $this->assertNull($run->spend);
    }

    public function test_create_modal_shows_no_status_or_spend_fields(): void
    {
        $this->actingAsCrmStaff();
        Brand::factory()->create();

        Livewire::test(SeedingCampaignsIndex::class)
            ->call('create')
            ->assertDontSeeHtml('id="seeding_status"')
            ->assertDontSeeHtml('id="seeding_spend"');
    }
}
```

- [ ] **Step 2: Run — expect FAIL.** `XDEBUG_MODE=off php artisan test --filter=SeedingFormBehaviourTest`

- [ ] **Step 3: Component.** (a) Add the reset hook:

```php
public function updatedSeedingBrandId(): void
{
    $this->seeding_product_id = '';
    $this->seeding_campaign_id = '';
}
```

(b) In `render()`, filter products exactly like campaigns already are (lines 321-323):

```php
'products' => $this->seeding_brand_id !== ''
    ? Product::query()->where('brand_id', (int) $this->seeding_brand_id)->orderBy('name')->get()
    : Product::query()->whereRaw('false')->get(),
```

(c) Split create/edit in `save()` exactly like Task 1: `$creating = $this->editingSeedingId === null;` — `seeding_status`/`seeding_spend` rules only when editing; on create force `$validated['seeding_status'] = SeedingCampaignStatus::Draft->value; $validated['seeding_spend'] = '';`. Keep both brand-coherence guards (183-201) untouched.

- [ ] **Step 4: Blade.** New field order in the modal: **Name → Brand → [Seeding type | Status(edit only)] → [Primary product | Parent campaign] → Spend (edit only)**. Brand select becomes `wire:model.live="seeding_brand_id"`. Product and Parent-campaign selects: when `$seeding_brand_id === ''` render them `disabled` with placeholder option `Choose a brand first`. Wrap Status (whole Alpine wrapper) and Spend blocks in `@if ($editingSeedingId !== null)`. Keep every Alpine description wrapper (`x-data="{ s: …, map: … }"`) glued to its own select when moving fields. Add create-mode helper line: `<p class="text-xs text-gray-500 dark:text-gray-400">New seeding runs start as a draft — status and spend move to editing once it exists.</p>`

- [ ] **Step 5: Run new tests — expect PASS; fix any existing seeding CRUD tests** that set status/spend on create (`tests/Feature/Crm/SeedingCampaignsCrudTest.php` — move to edit flows).

Run: `XDEBUG_MODE=off php artisan test --filter=Seeding`

- [ ] **Step 6: Regression + format + commit**

```bash
XDEBUG_MODE=off php artisan test --filter=Crm
vendor/bin/pint --dirty
git add app/Modules/CRM/Livewire/Seeding/SeedingCampaignsIndex.php resources/views/livewire/crm/seeding-campaigns-index.blade.php tests/Feature/Crm/SeedingFormBehaviourTest.php tests/Feature/Crm/SeedingCampaignsCrudTest.php
git commit -m "feat(crm): seeding run form guides the flow — brand first, products follow the brand, drafts by default"
```

---

### Task 3: Progressive shipment form (F28b)

**Files:**
- Modify: `app/Modules/CRM/Livewire/Seeding/ShipmentsPanel.php` (props ~45-61, save() ~134-241)
- Modify: `resources/views/livewire/crm/seeding-shipments.blade.php` (modal ~122-219)
- Test: `tests/Feature/Crm/ShipmentProgressiveFormTest.php` (new)

**Interfaces:**
- Consumes: `ShipmentStatus` (PENDING/PREPARING/SHIPPED/IN_TRANSIT/DELIVERED/RETURNED/FAILED), existing save() incl. the F03 auto-attach transaction (lines 160-235 — DO NOT restructure it).
- Produces: `ShipmentsPanel::showsTrackingFields(): bool`, `showsDeliveryFields(): bool`, `updatedShipmentStatus(): void`. Field-visibility rule used by the blade and save().

**Visibility rule (single source of truth, implement as two public methods):**
- tracking fields (`shipment_tracking_number`, `shipment_shipped_at`) show when status ∈ {SHIPPED, IN_TRANSIT, DELIVERED, RETURNED, FAILED}
- delivery field (`shipment_delivered_at`) shows when status ∈ {DELIVERED, RETURNED}

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Crm;

use App\Modules\CRM\Livewire\Seeding\ShipmentsPanel;
use App\Modules\CRM\Models\Creator;
use App\Modules\CRM\Models\Product;
use App\Modules\CRM\Models\SeedingCampaign;
use App\Modules\CRM\Models\Shipment;
use App\Shared\Enums\RoleName;
use App\Shared\Enums\ShipmentStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ShipmentProgressiveFormTest extends TestCase
{
    use RefreshDatabase;

    private SeedingCampaign $run;

    private function actingAsCrmStaff(): void
    {
        $this->seedRoles();
        $this->actingAs($this->makeUser(RoleName::InfluencerRelationsManager));
        $this->run = SeedingCampaign::factory()->create();
    }

    public function test_pending_shipment_form_hides_tracking_and_delivery_fields(): void
    {
        $this->actingAsCrmStaff();

        Livewire::test(ShipmentsPanel::class, ['seedingCampaign' => $this->run])
            ->call('create')
            ->assertDontSeeHtml('id="shipment_tracking_number"')
            ->assertDontSeeHtml('id="shipment_shipped_at"')
            ->assertDontSeeHtml('id="shipment_delivered_at"');
    }

    public function test_shipped_status_reveals_tracking_but_not_delivery(): void
    {
        $this->actingAsCrmStaff();

        Livewire::test(ShipmentsPanel::class, ['seedingCampaign' => $this->run])
            ->call('create')
            ->set('shipment_status', ShipmentStatus::Shipped->value)
            ->assertSeeHtml('id="shipment_tracking_number"')
            ->assertSeeHtml('id="shipment_shipped_at"')
            ->assertDontSeeHtml('id="shipment_delivered_at"');
    }

    public function test_downgrading_status_clears_hidden_values(): void
    {
        $this->actingAsCrmStaff();
        $creator = Creator::factory()->create();
        $this->run->creators()->syncWithoutDetaching([$creator->id]);
        $product = Product::factory()->create(['brand_id' => $this->run->brand_id]);

        Livewire::test(ShipmentsPanel::class, ['seedingCampaign' => $this->run->fresh()])
            ->call('create')
            ->set('shipment_creator_id', (string) $creator->id)
            ->set('shipment_product_id', (string) $product->id)
            ->set('shipment_status', ShipmentStatus::Delivered->value)
            ->set('shipment_tracking_number', 'TRK-1')
            ->set('shipment_shipped_at', '2026-07-01T10:00')
            ->set('shipment_delivered_at', '2026-07-03T10:00')
            ->set('shipment_status', ShipmentStatus::Pending->value)
            ->assertSet('shipment_tracking_number', '')
            ->assertSet('shipment_shipped_at', '')
            ->assertSet('shipment_delivered_at', '')
            ->call('save')
            ->assertHasNoErrors();

        $shipment = Shipment::query()->latest('id')->firstOrFail();
        $this->assertSame(ShipmentStatus::Pending, $shipment->status);
        $this->assertNull($shipment->tracking_number);
        $this->assertNull($shipment->shipped_at);
        $this->assertNull($shipment->delivered_at);
    }

    public function test_editing_a_delivered_shipment_shows_all_fields(): void
    {
        $this->actingAsCrmStaff();
        $shipment = Shipment::factory()->delivered()->create(['seeding_campaign_id' => $this->run->id]);

        Livewire::test(ShipmentsPanel::class, ['seedingCampaign' => $this->run->fresh()])
            ->call('edit', $shipment->id)
            ->assertSeeHtml('id="shipment_tracking_number"')
            ->assertSeeHtml('id="shipment_delivered_at"');
    }
}
```

(Note: `Shipment::factory()->delivered()` creates its own nested creator/product — if brand coherence trips, create creator/product explicitly against `$this->run` as in the third test.)

- [ ] **Step 2: Run — expect FAIL.** `XDEBUG_MODE=off php artisan test --filter=ShipmentProgressiveFormTest`

- [ ] **Step 3: Component.**

```php
public function showsTrackingFields(): bool
{
    return in_array($this->shipment_status, [
        ShipmentStatus::Shipped->value, ShipmentStatus::InTransit->value,
        ShipmentStatus::Delivered->value, ShipmentStatus::Returned->value,
        ShipmentStatus::Failed->value,
    ], true);
}

public function showsDeliveryFields(): bool
{
    return in_array($this->shipment_status, [
        ShipmentStatus::Delivered->value, ShipmentStatus::Returned->value,
    ], true);
}

public function updatedShipmentStatus(): void
{
    if (! $this->showsTrackingFields()) {
        $this->shipment_tracking_number = '';
        $this->shipment_shipped_at = '';
    }
    if (! $this->showsDeliveryFields()) {
        $this->shipment_delivered_at = '';
    }
}
```

In `save()`, immediately after `$validated = $this->validate(...)`, add defense-in-depth (hidden fields can be tampered back in):

```php
if (! $this->showsTrackingFields()) {
    $validated['shipment_tracking_number'] = '';
    $validated['shipment_shipped_at'] = '';
}
if (! $this->showsDeliveryFields()) {
    $validated['shipment_delivered_at'] = '';
}
```

(Keep the existing empty-string→null persistence conversion as-is; verify it exists — the current save() already maps `''` to null for nullable columns. If it maps via `?: null`, nothing more to do.)

- [ ] **Step 4: Blade.** Status select becomes `wire:model.live="shipment_status"` (keep its Alpine description wrapper). Wrap the Tracking input + Shipped-at input in `@if ($this->showsTrackingFields())`, the Delivered-at input in `@if ($this->showsDeliveryFields())` (restructure the 2-col grids so hidden fields don’t leave gaps: `[Status | Tracking?]`, `[Shipped at? | Delivered at?]`). Below the status select add: `<p class="text-xs text-gray-500 dark:text-gray-400">More fields appear once the parcel is on its way.</p>`

- [ ] **Step 5: Run new tests — expect PASS; fix existing `ShipmentsPanelTest`** flows that set tracking/dates while status is Pending (set an appropriate status first).

Run: `XDEBUG_MODE=off php artisan test --filter=Shipment`

- [ ] **Step 6: Regression + format + commit**

```bash
XDEBUG_MODE=off php artisan test --filter=Crm
vendor/bin/pint --dirty
git add app/Modules/CRM/Livewire/Seeding/ShipmentsPanel.php resources/views/livewire/crm/seeding-shipments.blade.php tests/Feature/Crm/ShipmentProgressiveFormTest.php tests/Feature/Crm/ShipmentsPanelTest.php
git commit -m "feat(crm): shipment form reveals tracking and delivery fields as the parcel progresses"
```

---

### Task 4: Inline-create foundation — `WithInlineCreate` trait + `x-crm.inline-create` + first two hosts (F01a)

**Files:**
- Create: `app/Modules/CRM/Livewire/Concerns/WithInlineCreate.php`
- Create: `resources/views/components/crm/inline-create.blade.php`
- Modify: `app/Modules/CRM/Livewire/Brands/BrandsIndex.php` (host: client select)
- Modify: `resources/views/livewire/crm/brands-index.blade.php` (client select ~106-113)
- Modify: `app/Modules/CRM/Livewire/Campaigns/CampaignsIndex.php` (host: brand select)
- Modify: `resources/views/livewire/crm/campaigns-index.blade.php` (brand select ~120-127)
- Test: `tests/Feature/Crm/InlineCreateTest.php` (new)

**Interfaces:**
- Consumes: `Client` (fillable name,country; `Country::values()`), `Brand` (fillable client_id,name,sector,aliases), `Product` (fillable brand_id,name,…), `Campaign`, `TenantRule::exists`, `AuditLogger`, `CampaignStatus::Draft`.
- Produces (used by Tasks 5, 8):

```php
trait WithInlineCreate
{
    public ?string $inlineCreate = null;           // 'client'|'brand'|'product'|'campaign'
    public string $inline_client_name = '';
    public string $inline_client_country = '';
    public string $inline_brand_name = '';
    public string $inline_brand_client_id = '';
    public bool $inline_new_client = false;        // brand form: toggle select → new-client name input
    public string $inline_product_name = '';
    public string $inline_campaign_name = '';

    public function openInlineCreate(string $type): void;
    public function cancelInlineCreate(): void;
    public function saveInlineCreate(AuditLogger $audit): void;

    /** Host hooks */
    abstract protected function inlineCreateTypes(): array;               // whitelist, e.g. ['client']
    abstract protected function inlineCreated(string $type, int $id): void; // assign to the host's select prop
    protected function inlineBrandContextId(): ?int { return null; }      // brand for product/campaign inline creation
}
```

**Behavioral contract (all hosts):**
- `openInlineCreate($type)`: reject types not in `inlineCreateTypes()` (silently return); `$this->authorize('create', <ModelClass>)`; for product/campaign require `inlineBrandContextId() !== null` else `notify error 'Choose a brand first.'`; reset `inline_*`; set `$inlineCreate = $type`.
- `saveInlineCreate()`: re-authorize; validate with the SAME rules as the full forms (client: name required|string|max:255 + country nullable|Rule::in(Country::values()) after uppercasing; brand: name required|string|max:255 + (client select: `TenantRule::exists('clients','id')` OR when `$inline_new_client` a required client name); product: name required|string|max:255, brand forced from context; campaign: name required|string|max:255, brand from context, status forced `CampaignStatus::Draft`); create inside `DB::transaction` when two records (new client + brand); audit with the standard event names (`client.created`, `brand.created`, `product.created`, `campaign.created`) and same context shape as the index components; call `$this->inlineCreated($type, $model->id)`; reset + close inline state; `notify` success (`'Client created.'` etc.).
- **Escape delegation:** every host’s `cancelForm()` gains as FIRST lines:

```php
if ($this->inlineCreate !== null) {
    $this->cancelInlineCreate();

    return;
}
```

- `validationAttributes()` of each host merges friendly names: `['inline_client_name' => 'client name', 'inline_client_country' => 'country', 'inline_brand_name' => 'brand name', 'inline_brand_client_id' => 'client', 'inline_product_name' => 'product name', 'inline_campaign_name' => 'campaign name']` (extract a `protected function inlineValidationAttributes(): array` on the trait and merge it in each host).

**`x-crm.inline-create` blade component** — props `['type' => null, 'clients' => null]`; renders nothing when `$type === null`; otherwise a `max-width="md"` `x-ui.modal` titled `New client|New brand|New product|New campaign` with `close-action="cancelInlineCreate"`, a `<form wire:submit="saveInlineCreate" class="space-y-4">` containing per-type fields bound to the trait’s `inline_*` names (the trait property names are part of the contract, so the component can hardcode them):
- client: Name input (`inline_client_name`), Country select (`inline_client_country`, options `\App\Shared\Enums\Country::cases()` → `->name` labels, placeholder `No country`).
- brand: Name input (`inline_brand_name`); then EITHER a Client select (`inline_brand_client_id`, options `$clients`) with a small toggle button `+ New client` (`wire:click="$set('inline_new_client', true)"`) OR when `$inline_new_client` (pass it as a prop or read `$wire`? — simplest: give the component a third prop `:new-client="$inline_new_client"`) a Client-name input (`inline_client_name`) with toggle `Pick an existing client instead` (`wire:click="$set('inline_new_client', false)"`). When the tenant has zero clients, render the name input directly (host passes `:clients`).
- product / campaign: single Name input + one context line `<p class="text-sm text-gray-500 dark:text-gray-400">Created under the brand you’ve chosen.</p>`.
- Footer: outline Cancel (`wire:click="cancelInlineCreate"`) + primary `type="submit"`-in-form… **footer buttons sit outside the form** — use `wire:click="saveInlineCreate"` + dual loading spans, per convention.

**Host wiring in this task:**
- `BrandsIndex`: `use WithInlineCreate;` `inlineCreateTypes() = ['client']`; `inlineCreated` sets `$this->brand_client_id = (string) $id;`. Blade: under the client select add `@can('create', \App\Modules\CRM\Models\Client::class)<button type="button" wire:click="openInlineCreate('client')" class="mt-1.5 text-sm font-medium text-brand-500 hover:text-brand-600 dark:text-brand-400">+ New client</button>@endcan` and after the main modal’s `@endif` add `<x-crm.inline-create :type="$inlineCreate" />`.
- `CampaignsIndex`: types `['brand']`; `inlineCreated` sets `$this->campaign_brand_id = (string) $id;`. Blade: `+ New brand` button under the brand select; `<x-crm.inline-create :type="$inlineCreate" :clients="$clients ?? \App\Modules\CRM\Models\Client::orderBy('name')->get()" :new-client="$inline_new_client" />` — pass clients from `render()` (add `'clients' => Client::orderBy('name')->get()` to CampaignsIndex render data).

- [ ] **Step 1: Write the failing tests**

```php
<?php

namespace Tests\Feature\Crm;

use App\Modules\CRM\Livewire\Brands\BrandsIndex;
use App\Modules\CRM\Livewire\Campaigns\CampaignsIndex;
use App\Modules\CRM\Models\Brand;
use App\Modules\CRM\Models\Client;
use App\Shared\Authorization\PermissionsCatalog;
use App\Shared\Enums\RoleName;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class InlineCreateTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsCrmStaff(): void
    {
        $this->seedRoles();
        $this->actingAs($this->makeUser(RoleName::InfluencerRelationsManager));
    }

    public function test_brand_form_creates_a_client_inline_and_selects_it(): void
    {
        $this->actingAsCrmStaff();

        Livewire::test(BrandsIndex::class)
            ->call('create')
            ->call('openInlineCreate', 'client')
            ->set('inline_client_name', 'Maison Brückner')
            ->call('saveInlineCreate')
            ->assertHasNoErrors()
            ->assertSet('inlineCreate', null)
            ->assertSet('brand_client_id', (string) Client::query()->where('name', 'Maison Brückner')->firstOrFail()->id)
            ->assertDispatched('notify', type: 'success');
    }

    public function test_campaign_form_creates_a_brand_with_a_new_client_in_one_go(): void
    {
        $this->actingAsCrmStaff();

        Livewire::test(CampaignsIndex::class)
            ->call('create')
            ->call('openInlineCreate', 'brand')
            ->set('inline_new_client', true)
            ->set('inline_client_name', 'Neue Agentur')
            ->set('inline_brand_name', 'Atelier Nord')
            ->call('saveInlineCreate')
            ->assertHasNoErrors();

        $brand = Brand::query()->where('name', 'Atelier Nord')->firstOrFail();
        $this->assertSame('Neue Agentur', $brand->client->name);
    }

    public function test_escape_on_the_inline_form_closes_only_the_inline_form(): void
    {
        $this->actingAsCrmStaff();

        Livewire::test(BrandsIndex::class)
            ->call('create')
            ->call('openInlineCreate', 'client')
            ->call('cancelForm') // what both Escape handlers reach first
            ->assertSet('inlineCreate', null)
            ->assertSet('showForm', true);
    }

    public function test_inline_create_requires_create_permission(): void
    {
        $this->seedRoles();
        $viewer = \App\Models\User::factory()->create();
        $viewer->givePermissionTo(PermissionsCatalog::CRM_VIEW);
        $this->actingAs($viewer);

        Livewire::test(BrandsIndex::class)
            ->call('openInlineCreate', 'client')
            ->assertForbidden();
    }

    public function test_unlisted_type_is_rejected(): void
    {
        $this->actingAsCrmStaff();

        Livewire::test(BrandsIndex::class)
            ->call('openInlineCreate', 'campaign')
            ->assertSet('inlineCreate', null);
    }
}
```

- [ ] **Step 2: Run — expect FAIL** (trait/component missing). `XDEBUG_MODE=off php artisan test --filter=InlineCreateTest`

- [ ] **Step 3: Implement the trait** (complete class per the contract above — the save branch for brand, the only multi-write path):

```php
if ($this->inlineCreate === 'brand') {
    $this->authorize('create', Brand::class);

    if ($this->inline_new_client) {
        $this->authorize('create', Client::class);
        $validated = $this->validate([
            'inline_client_name' => ['required', 'string', 'max:255'],
            'inline_brand_name' => ['required', 'string', 'max:255'],
        ]);
        $brand = DB::transaction(function () use ($validated, $audit) {
            $client = Client::create(['name' => $validated['inline_client_name']]);
            $audit->record('client.created', $client, ['name' => $client->name]);
            $brand = Brand::create(['client_id' => $client->id, 'name' => $validated['inline_brand_name']]);
            $audit->record('brand.created', $brand, ['name' => $brand->name]);

            return $brand;
        });
    } else {
        $validated = $this->validate([
            'inline_brand_client_id' => ['required', 'integer', TenantRule::exists('clients', 'id')],
            'inline_brand_name' => ['required', 'string', 'max:255'],
        ]);
        $brand = Brand::create(['client_id' => (int) $validated['inline_brand_client_id'], 'name' => $validated['inline_brand_name']]);
        $audit->record('brand.created', $brand, ['name' => $brand->name]);
    }

    $this->inlineCreated('brand', $brand->id);
    $this->resetInlineCreate();
    $this->dispatch('notify', type: 'success', message: 'Brand created.');

    return;
}
```

(client/product/campaign branches analogous and simpler; `resetInlineCreate()` private helper resets all `inline_*` + `$inlineCreate = null`; country uppercased before validating like `ClientsIndex::save()` line 113.)

- [ ] **Step 4: Implement the blade component + wire both hosts** (buttons + component tag + `cancelForm()` delegation + `inlineCreated` + `validationAttributes` merge + `clients` in CampaignsIndex render data).

- [ ] **Step 5: Run — expect PASS.** `XDEBUG_MODE=off php artisan test --filter=InlineCreateTest`

- [ ] **Step 6: Regression + lint + format + commit**

```bash
XDEBUG_MODE=off php artisan test --filter=Crm && XDEBUG_MODE=off php artisan test --filter=CrmCopyLintTest
vendor/bin/pint --dirty
git add app/Modules/CRM/Livewire/Concerns/WithInlineCreate.php resources/views/components/crm/inline-create.blade.php app/Modules/CRM/Livewire/Brands/BrandsIndex.php resources/views/livewire/crm/brands-index.blade.php app/Modules/CRM/Livewire/Campaigns/CampaignsIndex.php resources/views/livewire/crm/campaigns-index.blade.php tests/Feature/Crm/InlineCreateTest.php
git commit -m "feat(crm): create a client or brand inline, right where the form needs one"
```

---

### Task 5: Inline create everywhere else — seeding form (brand/product/campaign), product form (brand), shipment form (product) (F01b)

**Files:**
- Modify: `app/Modules/CRM/Livewire/Seeding/SeedingCampaignsIndex.php` + `resources/views/livewire/crm/seeding-campaigns-index.blade.php`
- Modify: `app/Modules/CRM/Livewire/Products/ProductsIndex.php` + `resources/views/livewire/crm/products-index.blade.php`
- Modify: `app/Modules/CRM/Livewire/Seeding/ShipmentsPanel.php` + `resources/views/livewire/crm/seeding-shipments.blade.php`
- Test: `tests/Feature/Crm/InlineCreateHostsTest.php` (new)

**Interfaces:**
- Consumes: `WithInlineCreate` exactly as produced by Task 4 (do not change the trait).
- Produces: nothing new — three more hosts.

**Host contracts:**
- `SeedingCampaignsIndex`: types `['brand','product','campaign']`; `inlineBrandContextId()` returns `$this->seeding_brand_id !== '' ? (int) $this->seeding_brand_id : null`; `inlineCreated`: brand → set `seeding_brand_id` AND reset `seeding_product_id`/`seeding_campaign_id` (same as `updatedSeedingBrandId()`), product → `seeding_product_id`, campaign → `seeding_campaign_id`. Blade buttons: `+ New brand` under Brand select; `+ New product` under Primary product select and `+ New campaign` under Parent campaign select — both rendered only when a brand is chosen (`@if ($seeding_brand_id !== '')`), with the proper `@can('create', Model::class)` wrappers. Pass `:clients` to the component tag.
- `ProductsIndex`: types `['brand']`; `inlineCreated` sets `product_brand_id`. `+ New brand` under the brand select; pass `:clients` (add to render data).
- `ShipmentsPanel`: types `['product']`; `inlineBrandContextId()` returns `$this->seedingCampaign->brand_id` (always non-null); `inlineCreated` sets `shipment_product_id`. `+ New product` under the product select. The created product lands under the RUN’s brand by construction, so the save() coherence guard passes.

- [ ] **Step 1: Write failing tests** — one per host, following the Task 4 shapes; the critical ones:

```php
public function test_seeding_form_inline_product_is_created_under_the_chosen_brand(): void
{
    $this->actingAsCrmStaff();
    $brand = Brand::factory()->create();

    Livewire::test(SeedingCampaignsIndex::class)
        ->call('create')
        ->set('seeding_brand_id', (string) $brand->id)
        ->call('openInlineCreate', 'product')
        ->set('inline_product_name', 'Sample Kit')
        ->call('saveInlineCreate')
        ->assertHasNoErrors();

    $product = Product::query()->where('name', 'Sample Kit')->firstOrFail();
    $this->assertSame($brand->id, $product->brand_id);
}

public function test_seeding_form_inline_product_requires_a_brand_first(): void
{
    $this->actingAsCrmStaff();

    Livewire::test(SeedingCampaignsIndex::class)
        ->call('create')
        ->call('openInlineCreate', 'product')
        ->assertSet('inlineCreate', null)
        ->assertDispatched('notify', type: 'error');
}

public function test_seeding_form_inline_campaign_starts_as_draft_under_the_chosen_brand(): void
{
    $this->actingAsCrmStaff();
    $brand = Brand::factory()->create();

    Livewire::test(SeedingCampaignsIndex::class)
        ->call('create')
        ->set('seeding_brand_id', (string) $brand->id)
        ->call('openInlineCreate', 'campaign')
        ->set('inline_campaign_name', 'Herbst Push')
        ->call('saveInlineCreate')
        ->assertHasNoErrors()
        ->assertSet('seeding_campaign_id', (string) Campaign::query()->where('name', 'Herbst Push')->firstOrFail()->id);

    $this->assertSame(CampaignStatus::Draft, Campaign::query()->where('name', 'Herbst Push')->firstOrFail()->status);
}

public function test_shipment_form_inline_product_lands_under_the_runs_brand(): void
{
    $this->actingAsCrmStaff();
    $run = SeedingCampaign::factory()->create();

    Livewire::test(ShipmentsPanel::class, ['seedingCampaign' => $run])
        ->call('create')
        ->call('openInlineCreate', 'product')
        ->set('inline_product_name', 'Gift Box')
        ->call('saveInlineCreate')
        ->assertHasNoErrors()
        ->assertSet('shipment_product_id', (string) Product::query()->where('name', 'Gift Box')->firstOrFail()->id);

    $this->assertSame($run->brand_id, Product::query()->where('name', 'Gift Box')->firstOrFail()->brand_id);
}
```

(+ a ProductsIndex `+ New brand` happy path.)

- [ ] **Step 2: Run — expect FAIL.** `XDEBUG_MODE=off php artisan test --filter=InlineCreateHostsTest`
- [ ] **Step 3: Wire the three hosts** (trait use, hooks, `cancelForm()` delegation, buttons, component tags, validationAttributes merges, `clients` render data where the brand type is offered).
- [ ] **Step 4: Run — expect PASS**, then regression + lint + format + commit:

```bash
XDEBUG_MODE=off php artisan test --filter=Crm && XDEBUG_MODE=off php artisan test --filter=CrmCopyLintTest
vendor/bin/pint --dirty
git add app/Modules/CRM/Livewire/Seeding/SeedingCampaignsIndex.php resources/views/livewire/crm/seeding-campaigns-index.blade.php app/Modules/CRM/Livewire/Products/ProductsIndex.php resources/views/livewire/crm/products-index.blade.php app/Modules/CRM/Livewire/Seeding/ShipmentsPanel.php resources/views/livewire/crm/seeding-shipments.blade.php tests/Feature/Crm/InlineCreateHostsTest.php
git commit -m "feat(crm): inline create reaches every parent select — brand, product and campaign never dead-end a form"
```

---

### Task 6: Roster picker on campaigns — bulk restriction check + `ManagesCreatorRoster` + multi-select UI + inline new creator (F08)

**Files:**
- Modify: `app/Modules/CRM/Services/BrandRestrictionGuard.php` (add bulk methods; do NOT touch `assertNotRestricted`)
- Create: `app/Modules/CRM/Livewire/Concerns/ManagesCreatorRoster.php`
- Create: `resources/views/livewire/crm/partials/roster-picker.blade.php`
- Modify: `app/Modules/CRM/Livewire/Campaigns/CampaignCreatorsPanel.php` + `resources/views/livewire/crm/campaign-creators.blade.php`
- Test: `tests/Feature/Crm/BrandRestrictionGuardBulkTest.php`, `tests/Feature/Crm/CampaignRosterPickerTest.php` (new)

**Interfaces:**
- Consumes: `BrandPreference` (jsonb `restricted_brands`, HasMany per creator, nullable arrays), `Creator::platformAccounts`, `Platform::tryFrom`, `RelationshipStatus::Blocklisted`, `CreatorWriter::createCreator`.
- Produces:

```php
// BrandRestrictionGuard — additions
/** @return list<int> creator ids whose restricted lists match the brand name (guard-identical matching) */
public function restrictedCreatorIdsForName(array $creatorIds, string $brandName): array;
/** @return list<int> */
public function restrictedCreatorIds(array $creatorIds, Brand $brand): array; // delegates with $brand->name
```

```php
trait ManagesCreatorRoster
{
    public bool $showPicker = false;
    public string $rosterSearch = '';
    public string $rosterPlatform = '';
    /** @var list<string> */
    public array $selectedCreatorIds = [];
    public bool $showNewCreatorForm = false;
    public string $new_creator_name = '';
    public string $new_creator_language = '';

    abstract protected function rosterOwner(): \Illuminate\Database\Eloquent\Model;
    abstract protected function rosterBrand(): Brand;
    abstract protected function rosterRelation(): \Illuminate\Database\Eloquent\Relations\BelongsToMany;
    abstract protected function rosterAuditEvent(): string; // 'campaign_creator.attached' | 'seeding_campaign_creator.attached'

    public function openPicker(): void;    // authorize('update', owner), reset state, show
    public function closePicker(): void;
    public function attachSelected(BrandRestrictionGuard $guard, AuditLogger $audit): void;
    public function createAndAttachCreator(CreatorWriter $writer, BrandRestrictionGuard $guard, AuditLogger $audit): void;
    protected function rosterCandidates(): \Illuminate\Support\Collection; // ≤51 rows, ->with('platformAccounts')
    protected function pickerViewData(BrandRestrictionGuard $guard): array; // ['candidates','restrictedIds','blocklistedIds','attached']
}
```

**Guard bulk matching (must be byte-identical semantics to `assertNotRestricted`, lines 22-31):** one `BrandPreference::query()->whereIn('creator_id', $creatorIds)->get(['creator_id','restricted_brands'])` (tenant scope automatic), needle `mb_strtolower(trim($brandName))`, a creator is restricted iff ANY entry of ANY of its rows satisfies `mb_strtolower(trim($entry)) === $needle`. Matching stays in PHP (never SQL `lower()` — unicode divergence). Return unique ints.

**`attachSelected` contract:** `authorize('update', owner)`; ids = unique ints of `selectedCreatorIds`, must be non-empty (`ValidationException` on `selectedCreatorIds` otherwise); load `Creator::whereIn('id', $ids)->get()` — if count mismatch → `ValidationException` (`'Some picked creators no longer exist.'`); `$restrictedIds = $guard->restrictedCreatorIds($ids, $this->rosterBrand())`; allowed = creators not in restricted — for each allowed, still run `$guard->assertNotRestricted()` inside try/catch, demoting any late violation into the skipped list (enforcement stays single-sourced); ONE `$this->rosterRelation()->syncWithoutDetaching($allowedIds)`; audit `rosterAuditEvent()` per id in `$result['attached']` with `['creator_id' => $id]`; refresh owner; reset selection; close picker; notify success `'Added N creators to the roster.'` + when skipped: `' Skipped M with brand restrictions: NameA, NameB…'` (list ≤3 names then `'and K more'`). Zero allowed + some skipped → notify `type: 'error'` with the skipped explanation.

**`rosterCandidates`:** `Creator::query()->whereNotIn('id', <attached ids>)->with('platformAccounts')` + search (`display_name ilike %term%` OR `whereHas('platformAccounts', handle ilike)` — copy `CreatorsIndex::creatorsQuery()` lines 84-91) + platform filter (`Platform::tryFrom` + `whereHas`) `->orderBy('display_name')->limit(51)->get()`. Blade shows 50 + a “Showing the first 50 — refine your search” hint when 51 arrive.

**`createAndAttachCreator`:** authorize `create, Creator::class` AND `update, owner`; validate `new_creator_name` required|string|max:255, `new_creator_language` nullable|string|max:10; `$writer->createCreator($name, $language ?: null)` (auto-enrolls into Monitoring — keep); audit `'creator.created'`; attach via relation `syncWithoutDetaching` + audit attach; reset the mini-form; notify `'Creator created and added to the roster.'`.

**Picker UI (shared partial, parameters: `$candidates`, `$restrictedIds`, `$blocklistedIds`, `$brandName`, `$selectedCount = count($selectedCreatorIds)`):** rendered inside `@if ($showPicker) <x-ui.modal :title="'Add creators'" close-action="closePicker" max-width="2xl">`. Toolbar: search input `wire:model.live.debounce.300ms="rosterSearch"` (placeholder `Search by name or handle…`) + platform `<x-form.select wire:model.live="rosterPlatform">` (All platforms / `Platform::cases()` labels). Rows (`divide-y` list, each `wire:key="picker-{{ $creator->id }}"`): `<label class="flex items-start gap-3 px-2 py-2.5">` with `<input type="checkbox" wire:model.live="selectedCreatorIds" value="{{ $creator->id }}" class="mt-0.5 h-5 w-5 cursor-pointer rounded-md border-gray-300 text-brand-500 focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900">`, name (`font-medium text-gray-800 dark:text-white/90`), platform line (`text-xs text-gray-500 dark:text-gray-400`): each account `{{ $account->platform->label() }} {{ $account->follower_count?->amount !== null ? \Illuminate\Support\Number::abbreviate((int) $account->follower_count->amount) : '' }}`; when restricted: `<p class="text-xs font-medium text-warning-500">On their no-go list for {{ $brandName }} — will be skipped.</p>`; when blocklisted: `<p class="text-xs text-gray-400">Marked ‘do not contact or book’.</p>`. Below the list: `+ New creator` toggle → mini-form (name + language inputs + `Add creator` button `wire:click="createAndAttachCreator"`). Footer: `{{ $selectedCount }} selected` + outline Cancel + primary `wire:click="attachSelected"` labeled `Add selected`. Empty candidates: `<x-states.empty title="No creators match" >Try another search, or create one below.</x-states.empty>`.

**CampaignCreatorsPanel conversion:** `use ManagesCreatorRoster;` hooks: owner `$this->campaign`, brand `$this->campaign->brand`, relation `$this->campaign->creators()`, event `'campaign_creator.attached'`. **Delete** the old `$attach_creator_id` property + `attach()` method + the single-select form in the blade; replace with an `Add creators` primary sm button (`wire:click="openPicker"`, inside the existing `@can('update', $campaign)`), keep the attached list + detach flow EXACTLY as-is (campaign detach stays unguarded — asymmetry with seeding is intentional). `render()` merges `$this->pickerViewData($guard)` (resolve guard via `app(BrandRestrictionGuard::class)` in render or method-inject via `boot`; simplest: `app()` call inside `pickerViewData`).

- [ ] **Step 1: Guard bulk tests** (`BrandRestrictionGuardBulkTest`): creator with `restricted_brands: ['  nike ']` matches brand name `'NIKE'` (trim+case-insensitive); union across two BrandPreference rows; null `restricted_brands` handled; creators without preferences excluded; ids not queried aren’t returned. Write, run, FAIL.
- [ ] **Step 2: Implement the guard methods; tests PASS.**
- [ ] **Step 3: Picker tests** (`CampaignRosterPickerTest`) — key cases:

```php
public function test_attach_selected_adds_allowed_and_skips_restricted_with_named_notice(): void
{
    $this->actingAsCrmStaff();
    $campaign = Campaign::factory()->create();
    $ok = Creator::factory()->create(['display_name' => 'Ariane Förster']);
    $blocked = Creator::factory()->create(['display_name' => 'Cordula Blank']);
    BrandPreference::factory()->create([
        'creator_id' => $blocked->id,
        'restricted_brands' => [$campaign->brand->name],
    ]);

    Livewire::test(CampaignCreatorsPanel::class, ['campaign' => $campaign])
        ->call('openPicker')
        ->set('selectedCreatorIds', [(string) $ok->id, (string) $blocked->id])
        ->call('attachSelected')
        ->assertDispatched('notify', type: 'success');

    $this->assertTrue($campaign->creators()->whereKey($ok->id)->exists());
    $this->assertFalse($campaign->creators()->whereKey($blocked->id)->exists());
}
```

plus: restricted flag visible in picker markup (`assertSee('On their no-go list')` after opening with a restricted candidate), search narrows candidates, platform filter works, `attachSelected` with empty selection errors, crm.view-only user `->call('openPicker')->assertForbidden()`, `createAndAttachCreator` creates + attaches + `MonitoredSubject` exists for the new creator (assert `\App\Modules\Monitoring\Models\MonitoredSubject::query()->where('creator_id', $id)->exists()` — verify the model’s actual FQCN in `app/Modules/Monitoring/Models/`), foreign-tenant creator id in `selectedCreatorIds` → validation error (create via `withTenant`). Also UPDATE `tests/Feature/Crm/CampaignCreatorsPanelTest.php` (or equivalent existing file): drop single-select attach tests, keep/port detach tests.
- [ ] **Step 4: Run — expect FAIL; implement** trait + partial + panel conversion; run — expect PASS.
- [ ] **Step 5: Regression + lint + format + commit**

```bash
XDEBUG_MODE=off php artisan test --filter=Crm && XDEBUG_MODE=off php artisan test --filter=CrmCopyLintTest
vendor/bin/pint --dirty
git add app/Modules/CRM/Services/BrandRestrictionGuard.php app/Modules/CRM/Livewire/Concerns/ManagesCreatorRoster.php resources/views/livewire/crm/partials/roster-picker.blade.php app/Modules/CRM/Livewire/Campaigns/CampaignCreatorsPanel.php resources/views/livewire/crm/campaign-creators.blade.php tests/Feature/Crm/BrandRestrictionGuardBulkTest.php tests/Feature/Crm/CampaignRosterPickerTest.php tests/Feature/Crm/CampaignCreatorsPanelTest.php
git commit -m "feat(crm): searchable multi-select roster picker on campaigns — restrictions flagged before you submit"
```

---

### Task 7: Roster picker on seeding runs + “Copy campaign roster” (F04 UX half)

**Files:**
- Modify: `app/Modules/CRM/Livewire/Seeding/SeedingCreatorsPanel.php` + `resources/views/livewire/crm/seeding-creators.blade.php`
- Test: `tests/Feature/Crm/SeedingRosterPickerTest.php` (new)

**Interfaces:**
- Consumes: `ManagesCreatorRoster` + partial from Task 6 unchanged; `SeedingCampaign::campaign()` (nullable), `Campaign::creators()`.
- Produces: `SeedingCreatorsPanel::copyCampaignRoster(BrandRestrictionGuard $guard, AuditLogger $audit): void`.

**Conversion:** same as Task 6 (owner `$this->seedingCampaign`, brand `$this->seedingCampaign->brand`, relation `creators()`, event `'seeding_campaign_creator.attached'`). **Keep the shipment-guarded `detach()` untouched.**

**`copyCampaignRoster` contract:** `authorize('update', $this->seedingCampaign)`; return early (notify error `'This run has no parent campaign.'`) when `campaign_id === null`; source ids = `$this->seedingCampaign->campaign->creators()->pluck('creators.id')`; already = intersect with attached; candidates = source minus attached; restricted = guard bulk check vs run’s brand; allowed = candidates minus restricted; one `syncWithoutDetaching`; audit per `$result['attached']`; refresh; notify `'Copied the campaign roster: N added'` + `', M already on this run'` + `', K skipped (brand restrictions)'` (only non-zero parts, joined naturally, final period).

**Blade:** next to the `Add creators` button, when `@can('update', $seedingCampaign)` AND `$seedingCampaign->campaign_id !== null` AND parent roster is non-empty: `<x-ui.button variant="outline" size="sm" wire:click="copyCampaignRoster">Copy campaign roster ({{ $parentRosterCount }})</x-ui.button>` (`$parentRosterCount` from `render()`: `$this->seedingCampaign->campaign?->creators()->count() ?? 0`).

- [ ] **Step 1: Write failing tests** — conversion happy-path (attach multi with restriction skip, mirroring Task 6), plus:

```php
public function test_copy_campaign_roster_adds_allowed_skips_restricted_and_counts_already_attached(): void
{
    $this->actingAsCrmStaff();
    $campaign = Campaign::factory()->create();
    $run = SeedingCampaign::factory()->forCampaign($campaign)->create(['brand_id' => $campaign->brand_id]);

    [$a, $b, $c] = Creator::factory()->count(3)->create();
    $campaign->creators()->syncWithoutDetaching([$a->id, $b->id, $c->id]);
    $run->creators()->syncWithoutDetaching([$a->id]); // already on the run
    BrandPreference::factory()->create(['creator_id' => $b->id, 'restricted_brands' => [$campaign->brand->name]]);

    Livewire::test(SeedingCreatorsPanel::class, ['seedingCampaign' => $run->fresh()])
        ->call('copyCampaignRoster')
        ->assertDispatched('notify', type: 'success');

    $this->assertTrue($run->creators()->whereKey($c->id)->exists());   // added
    $this->assertFalse($run->creators()->whereKey($b->id)->exists());  // restricted skipped
    $this->assertSame(2, $run->creators()->count());                   // a + c
}

public function test_copy_button_absent_on_standalone_runs(): void
{
    $this->actingAsCrmStaff();
    $run = SeedingCampaign::factory()->create(); // campaign_id null

    Livewire::test(SeedingCreatorsPanel::class, ['seedingCampaign' => $run])
        ->assertDontSee('Copy campaign roster');
}
```

(+ detach-with-shipments still blocked — port/keep the existing test; crm.view-only `copyCampaignRoster` forbidden.)
- [ ] **Step 2: Run FAIL → implement → PASS.** Update the existing `SeedingCreatorsPanelTest` file for the removed single-select attach.
- [ ] **Step 3: Regression + lint + format + commit**

```bash
XDEBUG_MODE=off php artisan test --filter=Crm && XDEBUG_MODE=off php artisan test --filter=CrmCopyLintTest
vendor/bin/pint --dirty
git add app/Modules/CRM/Livewire/Seeding/SeedingCreatorsPanel.php resources/views/livewire/crm/seeding-creators.blade.php tests/Feature/Crm/SeedingRosterPickerTest.php tests/Feature/Crm/SeedingCreatorsPanelTest.php
git commit -m "feat(crm): seeding roster picker with one-click copy of the campaign roster"
```

---

### Task 8: “New seeding run” on campaign detail — `SeedingRunCreatePanel` (F11)

**Files:**
- Create: `app/Modules/CRM/Livewire/Seeding/SeedingRunCreatePanel.php`
- Create: `resources/views/livewire/crm/seeding-run-create.blade.php`
- Modify: `app/Modules/CRM/CrmServiceProvider.php` (register `crm.seeding-run-create`)
- Modify: `resources/views/crm/campaign-detail.blade.php` (Seeding runs tab header ~99-101 + empty state ~102-108)
- Test: `tests/Feature/Crm/SeedingRunCreatePanelTest.php` (new)

**Interfaces:**
- Consumes: `WithInlineCreate` (types `['product']`, `inlineBrandContextId()` = `$this->campaign->brand_id`), `SeedingType`, `SeedingCampaignStatus::Draft`, `TenantRule`.
- Produces: Livewire alias `crm.seeding-run-create`, mounted `['campaign' => $campaign]`.

**Component (complete):**

```php
<?php

namespace App\Modules\CRM\Livewire\Seeding;

use App\Modules\CRM\Livewire\Concerns\WithInlineCreate;
use App\Modules\CRM\Models\Campaign;
use App\Modules\CRM\Models\Product;
use App\Modules\CRM\Models\SeedingCampaign;
use App\Shared\Audit\AuditLogger;
use App\Shared\Enums\SeedingCampaignStatus;
use App\Shared\Enums\SeedingType;
use App\Shared\Tenancy\TenantRule;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Validation\Rule;
use Livewire\Component;

class SeedingRunCreatePanel extends Component
{
    use AuthorizesRequests;
    use WithInlineCreate;

    public Campaign $campaign;

    public bool $showForm = false;

    public string $run_name = '';

    public string $run_type = '';

    public string $run_product_id = '';

    public function mount(Campaign $campaign): void
    {
        $this->authorize('view', $campaign);
        $this->campaign = $campaign;
    }

    public function create(): void
    {
        $this->authorize('create', SeedingCampaign::class);
        $this->reset('run_name', 'run_type', 'run_product_id');
        $this->resetValidation();
        $this->showForm = true;
    }

    public function cancelForm(): void
    {
        if ($this->inlineCreate !== null) {
            $this->cancelInlineCreate();

            return;
        }

        $this->showForm = false;
    }

    public function save(AuditLogger $audit): void
    {
        $this->authorize('create', SeedingCampaign::class);

        $validated = $this->validate([
            'run_name' => ['required', 'string', 'max:255'],
            'run_type' => ['required', Rule::in(array_column(SeedingType::cases(), 'value'))],
            'run_product_id' => ['nullable', 'integer', TenantRule::exists('products', 'id')],
        ]);

        if ($validated['run_product_id'] !== null && $validated['run_product_id'] !== '') {
            $product = Product::query()->findOrFail((int) $validated['run_product_id']);

            if ($product->brand_id !== $this->campaign->brand_id) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'run_product_id' => 'This product belongs to another brand.',
                ]);
            }
        }

        $run = SeedingCampaign::create([
            'name' => $validated['run_name'],
            'seeding_type' => $validated['run_type'],
            'brand_id' => $this->campaign->brand_id,   // server-side — brand coherence by construction
            'campaign_id' => $this->campaign->id,       // server-side
            'product_id' => $validated['run_product_id'] !== '' && $validated['run_product_id'] !== null
                ? (int) $validated['run_product_id'] : null,
            'status' => SeedingCampaignStatus::Draft,
        ]);

        $audit->record('seeding_campaign.created', $run, ['name' => $run->name, 'campaign_id' => $this->campaign->id]);

        $this->redirect(route('crm.seeding.show', $run));
    }

    protected function inlineCreateTypes(): array
    {
        return ['product'];
    }

    protected function inlineBrandContextId(): ?int
    {
        return $this->campaign->brand_id;
    }

    protected function inlineCreated(string $type, int $id): void
    {
        if ($type === 'product') {
            $this->run_product_id = (string) $id;
        }
    }

    protected function validationAttributes(): array
    {
        return [
            'run_name' => 'name',
            'run_type' => 'seeding type',
            'run_product_id' => 'product',
            ...$this->inlineValidationAttributes(),
        ];
    }

    public function render(): View
    {
        return view('livewire.crm.seeding-run-create', [
            'products' => $this->campaign->brand->products()->orderBy('name')->get(),
            'typeDescriptions' => collect(SeedingType::cases())
                ->mapWithKeys(fn ($t) => [$t->value => $t->description()])->all(),
        ]);
    }
}
```

**Blade (`livewire/crm/seeding-run-create.blade.php`):** root `<div>` containing `@can('create', \App\Modules\CRM\Models\SeedingCampaign::class)` a primary sm button `+ New seeding run` (`wire:click="create"`), then `@if ($showForm)` an `x-ui.modal` titled `New seeding run` (`close-action="cancelForm"`): context line `<p class="text-sm text-gray-500 dark:text-gray-400">For {{ $campaign->name }} — {{ $campaign->brand->name }}. It starts as a draft.</p>`, Name input (`run_name`), Seeding type select (`run_type`, options `SeedingType::cases()` labels, with the standard Alpine description wrapper fed by `$typeDescriptions`), Product select (`run_product_id`, options `$products`, placeholder `No product yet`) + `+ New product` inline button, footer Cancel/`Create seeding run`. Then `<x-crm.inline-create :type="$inlineCreate" />`.

**Campaign-detail wiring:** in the Seeding runs tab card header (make it `flex items-center justify-between`) drop in `@livewire('crm.seeding-run-create', ['campaign' => $campaign])`; in the empty state replace the `Go to Seeding runs →` action link with body text `Seeding runs under this campaign send its brand’s products to creators. Create the first one right here.` (button already sits in the header; keep the action slot empty or repeat the hint). Register the alias in `CrmServiceProvider::boot()`.

- [ ] **Step 1: Failing tests** — create-from-campaign happy path:

```php
public function test_creates_a_draft_run_under_the_campaigns_brand_and_redirects(): void
{
    $this->actingAsCrmStaff();
    $campaign = Campaign::factory()->create();

    Livewire::test(SeedingRunCreatePanel::class, ['campaign' => $campaign])
        ->call('create')
        ->set('run_name', 'Frühling Gifting')
        ->set('run_type', SeedingType::Gifting->value)
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('crm.seeding.show', SeedingCampaign::query()->where('name', 'Frühling Gifting')->firstOrFail()));

    $run = SeedingCampaign::query()->where('name', 'Frühling Gifting')->firstOrFail();
    $this->assertSame($campaign->brand_id, $run->brand_id);
    $this->assertSame($campaign->id, $run->campaign_id);
    $this->assertSame(SeedingCampaignStatus::Draft, $run->status);
}
```

plus: foreign-brand product rejected (`'This product belongs to another brand.'`), crm.view-only user `create()`/`save()` forbidden, page test `$this->get('/crm/campaigns/'.$campaign->id)->assertSeeLivewire(SeedingRunCreatePanel::class)`, inline product lands under the campaign’s brand.
- [ ] **Step 2: Run FAIL → implement (component, blade, registration, detail-blade wiring) → PASS.**
- [ ] **Step 3: Regression + lint + format + commit**

```bash
XDEBUG_MODE=off php artisan test --filter=Crm && XDEBUG_MODE=off php artisan test --filter=CrmCopyLintTest
vendor/bin/pint --dirty
git add app/Modules/CRM/Livewire/Seeding/SeedingRunCreatePanel.php resources/views/livewire/crm/seeding-run-create.blade.php app/Modules/CRM/CrmServiceProvider.php resources/views/crm/campaign-detail.blade.php tests/Feature/Crm/SeedingRunCreatePanelTest.php
git commit -m "feat(crm): start a seeding run straight from its campaign — brand and campaign prefilled"
```

---

### Task 9: CSV creator import through `CreatorWriter` (F10)

**Files:**
- Modify: `app/Modules/CRM/Services/CreatorWriter.php` (optional provenance-surface param)
- Create: `app/Modules/CRM/Livewire/Creators/CreatorCsvImport.php`
- Create: `resources/views/livewire/crm/creator-csv-import.blade.php`
- Modify: `app/Modules/CRM/CrmServiceProvider.php` (register `crm.creator-csv-import`)
- Modify: `resources/views/crm/creators.blade.php` (embed the import component after the index)
- Modify: `app/Modules/CRM/Livewire/Creators/CreatorsIndex.php` + `resources/views/livewire/crm/creators-index.blade.php` (toolbar `Import CSV` button dispatching an event; `#[On('creators-imported')]` refresh hook)
- Test: `tests/Feature/Crm/CreatorCsvImportTest.php` (new)

**Interfaces:**
- Consumes: `CreatorWriter::createCreator(string, ?string, ?RelationshipStatus): Creator` (transaction includes Monitoring auto-enroll — this is WHY import goes through the writer), `CreatorWriter::addManualPlatformAccount(...)`, `PlatformAccountConflict`, `Platform` enum (INSTAGRAM/TIKTOK/YOUTUBE), Livewire `WithFileUploads` (convention source: `DocumentsPanel`).
- Produces:

```php
// CreatorWriter — signature change (backward compatible)
public function addManualPlatformAccount(Creator $creator, Platform $platform, string $handle, ?string $bio = null, array $externalLinks = [], string $surface = self::MANUAL_ENTRY_SURFACE): PlatformAccount;
// new constant
public const CSV_IMPORT_SURFACE = 'crm-csv-import-v1';   // code constant, never rendered — lint only scans blades
```

Livewire events (first `#[On]` usage in the module — this establishes the convention): `CreatorsIndex` blade button dispatches `$dispatch('open-csv-import')`; `CreatorCsvImport` has `#[On('open-csv-import')] public function open(): void` (authorizes `create, Creator::class`); after a successful import `CreatorCsvImport` dispatches `creators-imported`; `CreatorsIndex` gets `#[On('creators-imported')] public function refreshAfterImport(): void {}` (empty body — forces re-render).

**CSV contract (document in the modal copy):**
- Header row required, case-insensitive, order-free. Recognized columns: `name` (required), `language`, `instagram`, `tiktok`, `youtube`. Unknown columns ignored. Missing `name` column → file-level error `The file needs a ‘name’ column.`
- Max 200 data rows (`That’s more than 200 rows — split the file and import it in batches.`). Fully-empty lines skipped. UTF-8 BOM stripped from the first cell.
- Handles normalized: `trim` then strip ONE leading `@`. Language trimmed.
- Per-row verdicts computed at preview AND re-checked at import: missing/too-long name (>255) → `skip`; language > 10 chars → `skip`; handle > 255 → `skip`; duplicate `(platform, handle)` within the file (first row wins, later rows skip); handle already used in this tenant (ONE batched `PlatformAccount::query()->where(...)->orWhere(...)` existence pass over all handles, tenant scope automatic) → `skip` with `@x already belongs to another creator`.
- Import loop: rows with verdict `ready` only; per row `DB::transaction(function () { $creator = $writer->createCreator($name, $language ?: null); $audit->record('creator.created', $creator, ['display_name' => $name, 'source' => 'csv-import']); foreach ($handles as $platform => $handle) { $account = $writer->addManualPlatformAccount($creator, $platform, $handle, surface: CreatorWriter::CSV_IMPORT_SURFACE); $audit->record('platform_account.added', $account, ['platform' => $platform->value, 'handle' => $handle]); } })` catching `PlatformAccountConflict` AND `QueryException` per row (race on the tenant-handle unique escapes as raw QueryException — scout-verified) → whole row rolled back, recorded as a row error, loop continues.
- Result state: `created` count + list of skipped/failed rows with reasons; `notify` success `Imported N creators.` when N > 0; dispatch `creators-imported`.

**Component state machine:** `public bool $open = false;` `public $upload = null;` (`WithFileUploads`), `public array $rows = [];` (each: `['line' => int, 'name' => string, 'language' => string, 'handles' => array<string,string>, 'verdict' => 'ready'|'skip', 'reason' => ?string]`), `public ?array $result = null;` Actions: `open()`, `close()`, `updatedUpload()` → validate `['upload' => ['required','file','max:1024','mimes:csv,txt']]` then parse into `$rows` (parse errors → `addError('upload', …)`); `import(CreatorWriter $writer, AuditLogger $audit)` → authorize + run the loop → `$result`; `reset()` back to upload step. Parsing: `$handle = fopen($this->upload->getRealPath(), 'rb')` + `fgetcsv` (native — no new dependency).

**Blade:** `@if($open)` modal `max-width="2xl"` titled `Import creators from CSV`. Upload step: file input (`wire:model="upload"`, convention from `documents-panel.blade.php:78-91` incl. `wire:target` loading states) + copy: `Columns: name (required), language, instagram, tiktok, youtube. One creator per row — handles without the @.` + `Every imported creator is monitored automatically.` Preview step (when `$rows` non-empty and `$result === null`): table `Name / Language / Handles / Status` (status: `Ready` in success text or the reason in warning text), summary line `N of M rows will be imported.`, footer `Choose another file` (reset) + primary `Import N creators` (`wire:click="import"`, disabled when N = 0). Result step: success line + skipped-row list; footer `Done` (close).

- [ ] **Step 1: Failing tests** — core cases:

```php
public function test_preview_flags_bad_rows_and_import_creates_the_good_ones(): void
{
    $this->actingAsCrmStaff();
    Storage::fake('local');

    $csv = "name,language,instagram\nAnna Alpha,de,@anna.alpha\n,de,orphan\nBella Beta,de,anna.alpha\n";
    $file = UploadedFile::fake()->createWithContent('creators.csv', $csv);

    $component = Livewire::test(CreatorCsvImport::class)
        ->call('open')
        ->set('upload', $file);

    $component->assertSet('rows.0.verdict', 'ready')
        ->assertSet('rows.1.verdict', 'skip')      // missing name
        ->assertSet('rows.2.verdict', 'skip');     // duplicate handle within file

    $component->call('import')->assertHasNoErrors();

    $anna = Creator::query()->where('display_name', 'Anna Alpha')->firstOrFail();
    $this->assertSame('anna.alpha', $anna->platformAccounts()->firstOrFail()->handle); // @ stripped
    $this->assertNull(Creator::query()->where('display_name', 'Bella Beta')->first()?->platformAccounts()->first());
    $this->assertSame(1, Creator::query()->count());
}

public function test_imported_creators_are_enrolled_into_monitoring(): void
{
    $this->actingAsCrmStaff();
    $csv = "name\nMona Monitor\n";
    Livewire::test(CreatorCsvImport::class)
        ->call('open')
        ->set('upload', UploadedFile::fake()->createWithContent('c.csv', $csv))
        ->call('import');

    $creator = Creator::query()->where('display_name', 'Mona Monitor')->firstOrFail();
    $this->assertTrue(
        \App\Modules\Monitoring\Models\MonitoredSubject::query()->where('creator_id', $creator->id)->exists()
    ); // verify FQCN against app/Modules/Monitoring/Models before finalizing
}

public function test_existing_tenant_handle_skips_the_row_but_other_rows_import(): void
{
    $this->actingAsCrmStaff();
    $existing = Creator::factory()->create();
    PlatformAccount::factory()->forCreator($existing)->onPlatform(Platform::Instagram)->create(['handle' => 'taken.handle']);

    $csv = "name,instagram\nNew Nora,taken.handle\nFree Frida,free.handle\n";
    Livewire::test(CreatorCsvImport::class)
        ->call('open')
        ->set('upload', UploadedFile::fake()->createWithContent('c.csv', $csv))
        ->call('import');

    $this->assertNull(Creator::query()->where('display_name', 'New Nora')->first());
    $this->assertNotNull(Creator::query()->where('display_name', 'Free Frida')->first());
}
```

plus: >200 rows rejected; missing name column rejected; crm.view-only user `open()` forbidden; provenance of an imported account carries `sourceVersion = 'crm-csv-import-v1'` (assert on the stored `provenance` value object); `creators-imported` dispatched.
- [ ] **Step 2: Run FAIL → implement writer param + component + blades + registration + index button/hook → PASS.**
- [ ] **Step 3: Regression + lint + format + commit**

```bash
XDEBUG_MODE=off php artisan test --filter=Crm && XDEBUG_MODE=off php artisan test --filter=CrmCopyLintTest
vendor/bin/pint --dirty
git add app/Modules/CRM/Services/CreatorWriter.php app/Modules/CRM/Livewire/Creators/CreatorCsvImport.php resources/views/livewire/crm/creator-csv-import.blade.php app/Modules/CRM/CrmServiceProvider.php resources/views/crm/creators.blade.php app/Modules/CRM/Livewire/Creators/CreatorsIndex.php resources/views/livewire/crm/creators-index.blade.php tests/Feature/Crm/CreatorCsvImportTest.php
git commit -m "feat(crm): import creators from CSV — preview first, monitoring enrollment kept, conflicts skip row by row"
```

---

### Task 10: Optional campaign wizard at `/crm/campaigns/new` (F01/F02 guided path)

**Files:**
- Modify: `app/Modules/CRM/routes.php` (add `campaigns.create` BEFORE `campaigns.show`)
- Create: `resources/views/crm/campaign-wizard.blade.php` (page)
- Create: `app/Modules/CRM/Livewire/Campaigns/CampaignWizard.php`
- Create: `resources/views/livewire/crm/campaign-wizard.blade.php`
- Modify: `app/Modules/CRM/CrmServiceProvider.php` (register `crm.campaign-wizard`)
- Modify: `resources/views/livewire/crm/campaigns-index.blade.php` (secondary toolbar link `Guided setup` → the wizard, next to the existing `New campaign` modal button)
- Test: `tests/Feature/Crm/CampaignWizardTest.php` (new)

**Interfaces:**
- Consumes: Task 6’s `BrandRestrictionGuard::restrictedCreatorIdsForName()` (works for BOTH existing and not-yet-created brands — flags key off the brand NAME string), Draft-forcing conventions from Tasks 1-2, standard per-entity validation rules + audit events.
- Produces: route `crm.campaigns.create` (`GET /crm/campaigns/new`), alias `crm.campaign-wizard`.

**Route (order matters — literal before wildcard):**

```php
Route::view('/campaigns/new', 'crm.campaign-wizard')->name('campaigns.create');
Route::get('/campaigns/{campaign}', fn (Campaign $campaign) => …)->name('campaigns.show'); // existing, stays after
```

**Wizard state (all Livewire props):** `public int $step = 1;` (1 Client & brand, 2 Campaign, 3 Seeding run, 4 Creators, 5 Review; plus a `public bool $finished = false` Done screen).
- 1: `client_mode` (`'existing'|'new'`, default `'existing'` when clients exist else `'new'`), `wizard_client_id`, `new_client_name`, `new_client_country`; `brand_mode`, `wizard_brand_id`, `new_brand_name`. Rule: choosing `client_mode = 'new'` forces `brand_mode = 'new'` (a fresh client has no brands) — enforce in `updatedClientMode()`.
- 2: `campaign_name`, `campaign_start_at`, `campaign_end_at` (datetime-local strings).
- 3: `with_seeding` bool, `run_name`, `run_type`, `run_product_id` (options only when `brand_mode === 'existing'`; hidden otherwise).
- 4: `creator_search`, `selected_creator_ids` (list<string>).
- Results: `?int $createdCampaignId`, `?int $createdRunId`, `array $skippedCreators = []` (names).

**Step validation (validated in `next()` for the current step, re-validated inside `finish()`):**
- 1: `client_mode` in existing|new; existing → `wizard_client_id` required + `TenantRule::exists('clients','id')`; new → `new_client_name` required|string|max:255, `new_client_country` nullable|in Country::values() (uppercased first). Same for brand; existing brand additionally must belong to the chosen client (query check → `ValidationException` on `wizard_brand_id`).
- 2: `campaign_name` required|string|max:255; dates nullable|date, end `after_or_equal:campaign_start_at`.
- 3 (only when `with_seeding`): `run_name` required|string|max:255; `run_type` required|in SeedingType values; `run_product_id` nullable + `TenantRule::exists('products','id')` + belongs to the chosen existing brand.
- 4: each id integer + `TenantRule::exists('creators','id')` (validate as `'selected_creator_ids.*'`).

**Actions:** `next()` / `back()`; `createNow(AuditLogger $audit)` (visible from steps 2-4: validates steps 1-2 only, finishes with campaign only); `finish(AuditLogger $audit)` (from Review). `finish()`/`createNow()` share one private `commit(AuditLogger $audit, bool $withExtras): void`:

```php
$this->authorize('create', Campaign::class);
// + authorize('create', Client::class/Brand::class/SeedingCampaign::class) for each part actually being created

DB::transaction(function () use (...) {
    $client = $this->client_mode === 'new'
        ? tap(Client::create([...]), fn ($c) => $audit->record('client.created', $c, ['name' => $c->name]))
        : Client::query()->findOrFail((int) $this->wizard_client_id);

    $brand = $this->brand_mode === 'new'
        ? tap(Brand::create(['client_id' => $client->id, 'name' => $this->new_brand_name]),
              fn ($b) => $audit->record('brand.created', $b, ['name' => $b->name]))
        : Brand::query()->where('client_id', $client->id)->findOrFail((int) $this->wizard_brand_id);

    $campaign = Campaign::create([
        'brand_id' => $brand->id, 'name' => $this->campaign_name,
        'status' => CampaignStatus::Draft,
        'start_at' => $this->campaign_start_at ?: null, 'end_at' => $this->campaign_end_at ?: null,
    ]);
    $audit->record('campaign.created', $campaign, ['name' => $campaign->name]);

    $run = null;
    if ($withExtras && $this->with_seeding) {
        $run = SeedingCampaign::create([
            'name' => $this->run_name, 'seeding_type' => $this->run_type,
            'brand_id' => $brand->id, 'campaign_id' => $campaign->id,
            'product_id' => $this->run_product_id !== '' ? (int) $this->run_product_id : null,
            'status' => SeedingCampaignStatus::Draft,
        ]);
        $audit->record('seeding_campaign.created', $run, ['name' => $run->name, 'campaign_id' => $campaign->id]);
    }

    if ($withExtras && $this->selected_creator_ids !== []) {
        $ids = array_values(array_unique(array_map('intval', $this->selected_creator_ids)));
        $restricted = $guard->restrictedCreatorIds($ids, $brand);            // Brand exists by now
        $allowed = array_values(array_diff($ids, $restricted));
        $this->skippedCreators = Creator::query()->whereIn('id', $restricted)->pluck('display_name')->all();

        if ($allowed !== []) {
            $attached = $campaign->creators()->syncWithoutDetaching($allowed);
            foreach ($attached['attached'] as $id) {
                $audit->record('campaign_creator.attached', $campaign, ['creator_id' => $id]);
            }
            if ($run !== null) {
                $runAttached = $run->creators()->syncWithoutDetaching($allowed);
                foreach ($runAttached['attached'] as $id) {
                    $audit->record('seeding_campaign_creator.attached', $run, ['creator_id' => $id]);
                }
            }
        }
    }

    $this->createdCampaignId = $campaign->id;
    $this->createdRunId = $run?->id;
});
$this->finished = true;
```

(Note: `$campaign->creators()` on a freshly created model — `tenant_id` is stamped by `BelongsToTenant` on create, so `withPivotValue` works; `$guard` method-injected on `finish()`/`createNow()` alongside `$audit`.)

**render() data:** `clients` (ordered), `brands` (of chosen client or empty), `products` (of chosen existing brand or empty), `candidates` (step 4: Creator search ilike name/handle, `->with('platformAccounts')`, limit 51), `restrictedIds` (via `restrictedCreatorIdsForName(candidateIds, $brandName)` where `$brandName` = existing brand’s name or `new_brand_name`), `typeDescriptions`.

**Blade:** progress rail (5 items, `1 · Client & brand` … `5 · Review` — NEVER the word "Step" + number), one card per step (`@if ($step === n)`), step 4 reuses the roster-picker ROW markup (checkbox + name + platforms + restriction warning — inline here, not the modal partial), Review shows a definition list of everything chosen + `skipped will be reported` note, footer per step: `Back` (outline, steps 2+), `Continue` (primary), `Skip the rest — create the campaign now` (text link, steps 2-4, `wire:click="createNow"`), Review: `Create campaign` primary `wire:click="finish"`. Done screen (`@if ($finished)`): success card `Campaign created` + links `route('crm.campaigns.show', $createdCampaignId)` (`Open the campaign →`), run link when set, and when `$skippedCreators !== []` a warning list `Skipped (their no-go list includes this brand): …`. Page blade: standard skeleton, breadcrumbs `Dashboard / CRM / Campaigns / New campaign`, title `New campaign`.

- [ ] **Step 1: Failing tests** — key cases:

```php
public function test_full_wizard_creates_client_brand_campaign_run_and_rosters_minus_restricted(): void
{
    $this->actingAsCrmStaff();
    $ok = Creator::factory()->create(['display_name' => 'Greta Good']);
    $blocked = Creator::factory()->create(['display_name' => 'Nora NoGo']);
    BrandPreference::factory()->create(['creator_id' => $blocked->id, 'restricted_brands' => ['Atelier Nord']]);

    Livewire::test(CampaignWizard::class)
        ->set('client_mode', 'new')->set('new_client_name', 'Brückner GmbH')
        ->set('brand_mode', 'new')->set('new_brand_name', 'Atelier Nord')
        ->call('next')
        ->set('campaign_name', 'Creator Week')->call('next')
        ->set('with_seeding', true)->set('run_name', 'Welle Eins')
        ->set('run_type', SeedingType::Gifting->value)->call('next')
        ->set('selected_creator_ids', [(string) $ok->id, (string) $blocked->id])->call('next')
        ->call('finish')
        ->assertSet('finished', true)
        ->assertSee('Nora NoGo');

    $campaign = Campaign::query()->where('name', 'Creator Week')->firstOrFail();
    $this->assertSame(CampaignStatus::Draft, $campaign->status);
    $this->assertSame('Atelier Nord', $campaign->brand->name);
    $this->assertSame('Brückner GmbH', $campaign->brand->client->name);
    $this->assertTrue($campaign->creators()->whereKey($ok->id)->exists());
    $this->assertFalse($campaign->creators()->whereKey($blocked->id)->exists());

    $run = SeedingCampaign::query()->where('name', 'Welle Eins')->firstOrFail();
    $this->assertSame($campaign->id, $run->campaign_id);
    $this->assertTrue($run->creators()->whereKey($ok->id)->exists());
}

public function test_create_now_from_the_campaign_step_makes_only_the_campaign(): void
{
    $this->actingAsCrmStaff();
    $client = Client::factory()->create();
    $brand = Brand::factory()->create(['client_id' => $client->id]);

    Livewire::test(CampaignWizard::class)
        ->set('wizard_client_id', (string) $client->id)
        ->set('wizard_brand_id', (string) $brand->id)
        ->call('next')
        ->set('campaign_name', 'Quick One')
        ->call('createNow')
        ->assertSet('finished', true);

    $this->assertNotNull(Campaign::query()->where('name', 'Quick One')->first());
    $this->assertSame(0, SeedingCampaign::query()->count());
}

public function test_the_literal_new_segment_wins_over_the_campaign_wildcard(): void
{
    $this->actingAsCrmStaff();
    $this->get('/crm/campaigns/new')->assertOk()->assertSeeLivewire(CampaignWizard::class);
}
```

plus: brand of another client rejected; step-1 validation blocks `next()`; ClientViewer 403 on the route; crm.view-only user forbidden on `finish` (mount can authorize `create, Campaign::class` — then the whole page 403s for view-only users, which is correct for a create-only surface: assert that); a restricted candidate shows the warning on step 4 (`assertSee('no-go')` with a matching new-brand name).
- [ ] **Step 2: Run FAIL → implement route (BEFORE the wildcard!), component, blades, registration, campaigns-index link → PASS.**
- [ ] **Step 3: Regression + lint + format + commit**

```bash
XDEBUG_MODE=off php artisan test --filter=Crm && XDEBUG_MODE=off php artisan test --filter=CrmCopyLintTest
vendor/bin/pint --dirty
git add app/Modules/CRM/routes.php resources/views/crm/campaign-wizard.blade.php app/Modules/CRM/Livewire/Campaigns/CampaignWizard.php resources/views/livewire/crm/campaign-wizard.blade.php app/Modules/CRM/CrmServiceProvider.php resources/views/livewire/crm/campaigns-index.blade.php tests/Feature/Crm/CampaignWizardTest.php
git commit -m "feat(crm): guided campaign wizard — client to roster in one flow, skippable at every step"
```

---

### Task 11: New `/crm` Overview — checklist, needs-attention, active work, quick actions (F02)

**Files:**
- Create: `app/Modules/CRM/Livewire/Overview/CrmOverview.php`
- Create: `resources/views/livewire/crm/overview.blade.php`
- Modify: `resources/views/crm/index.blade.php` (replace the 8-card hub with the page skeleton + `@livewire('crm.overview')`)
- Modify: `app/Modules/CRM/CrmServiceProvider.php` (register `crm.overview`)
- Modify (quick-action auto-open, one `mount()` line each): `app/Modules/CRM/Livewire/Clients/ClientsIndex.php`, `Brands/BrandsIndex.php`, `Creators/CreatorsIndex.php`, `Seeding/SeedingCampaignsIndex.php`
- Test: `tests/Feature/Crm/CrmOverviewTest.php` (new)

**Interfaces:**
- Consumes: tenant-scoped `Model::count()` (BelongsToTenant), `TasksIndex::openStatuses()` (via `PresentsTaskStatus`), enums for status filtering, route `crm.campaigns.create` (Task 10).
- Produces: alias `crm.overview`. Route name `crm.index` at `/crm` MUST stay (every CRM breadcrumb targets it) — keep `Route::view('/', 'crm.index')` untouched.

**Page blade (`crm/index.blade.php`) replacement:**

```blade
<x-layouts.app title="CRM">
    <x-page-header title="CRM" :breadcrumbs="['Dashboard' => route('dashboard'), 'CRM' => null]">
        <x-slot:actions>
            @can('create', \App\Modules\CRM\Models\Campaign::class)
                <a href="{{ route('crm.campaigns.create') }}">
                    <x-ui.button size="sm">New campaign</x-ui.button>
                </a>
            @endcan
        </x-slot:actions>
    </x-page-header>

    @livewire('crm.overview')
</x-layouts.app>
```

**Component `render()` data (all reads, mount authorizes `PermissionsCatalog::CRM_VIEW`):**

```php
$counts = [
    'clients' => Client::query()->count(),
    'brands' => Brand::query()->count(),
    'creators' => Creator::query()->count(),
    'campaigns' => Campaign::query()->count(),
    'runs' => SeedingCampaign::query()->count(),
];

$checklist = [
    ['done' => $counts['clients'] > 0, 'label' => 'Create your first client', 'hint' => 'The company you work for.', 'url' => route('crm.clients.index').'?create=1'],
    ['done' => $counts['brands'] > 0, 'label' => 'Add a brand', 'hint' => 'Brands belong to a client and own campaigns and products.', 'url' => route('crm.brands.index').'?create=1'],
    ['done' => $counts['creators'] > 0, 'label' => 'Add creators', 'hint' => 'One by one or from a CSV file — new creators are monitored automatically.', 'url' => route('crm.creators.index').'?create=1'],
    ['done' => $counts['campaigns'] > 0, 'label' => 'Create your first campaign', 'hint' => 'Plan and measure work for one brand.', 'url' => route('crm.campaigns.create')],
    ['done' => $counts['runs'] > 0, 'label' => 'Launch a seeding run', 'hint' => 'Send products to creators and track what they post.', 'url' => route('crm.seeding.index').'?create=1'],
];
$setupComplete = collect($checklist)->every(fn ($s) => $s['done']);

$openStatuses = TasksIndex::openStatuses();
$attention = [
    'overdueTasks' => Task::query()->whereIn('status', $openStatuses)->whereNotNull('due_at')->where('due_at', '<', now())->count(),
    'emptyRuns' => SeedingCampaign::query()->whereNotIn('status', [SeedingCampaignStatus::Completed, SeedingCampaignStatus::Cancelled])->whereDoesntHave('creators')->count(),
    'awaitedShipments' => Shipment::query()->whereIn('status', [ShipmentStatus::Shipped, ShipmentStatus::InTransit])->whereNotNull('shipped_at')->where('shipped_at', '<', now()->subDays(7))->whereNull('delivered_at')->count(),
];

$activeCampaigns = Campaign::query()->whereIn('status', [CampaignStatus::Planned, CampaignStatus::Active, CampaignStatus::Paused])
    ->withCount(['creators', 'seedingCampaigns'])->latest('updated_at')->limit(5)->get();
$activeRuns = SeedingCampaign::query()->whereIn('status', [SeedingCampaignStatus::Planned, SeedingCampaignStatus::Active, SeedingCampaignStatus::Shipping])
    ->withCount(['creators', 'shipments'])->latest('updated_at')->limit(5)->get();
```

**Overview blade layout:**
- **Get set up card** (when `! $setupComplete`): title `Get set up`, checklist markup copied from the campaign-detail pattern (✓/○ circles, done rows line-through); each OPEN step’s label is an `<a href>` to its url + `<span class="text-gray-400">— {{ hint }}</span>`. When `$setupComplete`: a slim success pill instead — `<x-ui.badge color="success">✓ You’re all set up</x-ui.badge>` inside a one-line card.
- **Two-column grid** (`grid grid-cols-1 gap-6 lg:grid-cols-2`): **Needs attention** card — rows only for non-zero counts, each a link: `{{ $n }} tasks are overdue` → `route('crm.tasks.index')`; `{{ $n }} seeding runs have no creators yet` → `route('crm.seeding.index')`; `{{ $n }} shipments have been on the road for more than a week` → `route('crm.seeding.index')`. All-zero → `<x-states.empty title="Nothing needs your attention right now">Check back after your next campaign moves.</x-states.empty>`. **Active work** card — campaign rows (name link → detail, status badge via `->label()`, `{{ $c->seeding_campaigns_count }} runs`) then run rows (name link, status badge, `{{ $r->shipments_count }} shipments · {{ $r->creators_count }} creators`); empty → `<x-states.empty title="No active campaigns or seeding runs yet">Everything you set live shows up here.</x-states.empty>`.
- **Quick actions row:** `@can('create', Model)`-gated outline sm buttons as links: `New client` (`crm.clients.index?create=1`), `New brand` (`crm.brands.index?create=1`), `New creator` (`crm.creators.index?create=1`), `New seeding run` (`crm.seeding.index?create=1`).
- Keep the `@can('users.manage')` footer note about Admin → Users (port it from the old hub, lines 98-102).

**Quick-action auto-open:** append to `mount()` of the four index components (create it where no mount exists — ClientsIndex/BrandsIndex/CreatorsIndex/SeedingCampaignsIndex all have mount with authorize already; verify per file):

```php
if (request()->boolean('create') && auth()->user()->can('create', Client::class)) {
    $this->create();
}
```

(each with its own model class; the `can()` guard keeps crm.view-only visitors on a working page instead of a 403).

- [ ] **Step 1: Failing tests** — key cases:

```php
public function test_empty_tenant_sees_the_full_checklist_with_first_step_open(): void
{
    $this->actingAsCrmStaff();
    $this->get('/crm')->assertOk()->assertSeeLivewire(CrmOverview::class)
        ->assertSee('Get set up')->assertSee('Create your first client');
}

public function test_checklist_collapses_to_a_pill_when_everything_exists(): void
{
    $this->actingAsCrmStaff();
    $campaign = Campaign::factory()->create();               // → client+brand via nested factories
    Creator::factory()->create();
    SeedingCampaign::factory()->forCampaign($campaign)->create(['brand_id' => $campaign->brand_id]);

    Livewire::test(CrmOverview::class)
        ->assertSee('You’re all set up')
        ->assertDontSee('Create your first client');
}

public function test_needs_attention_counts_overdue_tasks_empty_runs_and_stale_shipments(): void
{
    $this->actingAsCrmStaff();
    Task::factory()->create(['due_at' => now()->subDay()]);                                  // overdue (status Open default)
    $run = SeedingCampaign::factory()->create();                                             // Draft, no creators
    $creator = Creator::factory()->create();
    $run2 = SeedingCampaign::factory()->create();
    $run2->creators()->syncWithoutDetaching([$creator->id]);
    Shipment::factory()->create([
        'seeding_campaign_id' => $run2->id, 'creator_id' => $creator->id,
        'status' => ShipmentStatus::Shipped, 'shipped_at' => now()->subDays(10), 'delivered_at' => null,
    ]);

    Livewire::test(CrmOverview::class)
        ->assertSee('1 tasks are overdue')      // implement pluralization: use Str::plural — assert '1 task is overdue'
        ->assertSee('seeding runs have no creators yet')
        ->assertSee('on the road for more than a week');
}

public function test_client_viewer_gets_403(): void
{
    $this->seedRoles();
    $this->actingAs($this->makeUser(RoleName::ClientViewer));
    $this->get('/crm')->assertForbidden();
}

public function test_quick_action_query_param_opens_the_create_modal(): void
{
    $this->actingAsCrmStaff();
    $this->get('/crm/clients?create=1')->assertOk()->assertSee('New client');
    // and for a crm.view-only user the page still renders without the modal:
    $viewer = \App\Models\User::factory()->create();
    $viewer->givePermissionTo(PermissionsCatalog::CRM_VIEW);
    $this->actingAs($viewer);
    $this->get('/crm/clients?create=1')->assertOk();
}
```

**Pluralization note:** write the rows with proper singular/plural (`Str::plural('task', $n)` / hand-branched copy) — adjust the assertions to the final copy; never ship `1 tasks`.
- [ ] **Step 2: Run FAIL → implement component + blades + registration + four mount() hooks → PASS.** Also delete the old hub card grid; grep the old hub copy (`Results & Reporting`) to confirm it’s gone from the page.
- [ ] **Step 3: Regression + lint + format + commit**

```bash
XDEBUG_MODE=off php artisan test --filter=Crm && XDEBUG_MODE=off php artisan test --filter=CrmCopyLintTest
vendor/bin/pint --dirty
git add app/Modules/CRM/Livewire/Overview/CrmOverview.php resources/views/livewire/crm/overview.blade.php resources/views/crm/index.blade.php app/Modules/CRM/CrmServiceProvider.php app/Modules/CRM/Livewire/Clients/ClientsIndex.php app/Modules/CRM/Livewire/Brands/BrandsIndex.php app/Modules/CRM/Livewire/Creators/CreatorsIndex.php app/Modules/CRM/Livewire/Seeding/SeedingCampaignsIndex.php tests/Feature/Crm/CrmOverviewTest.php
git commit -m "feat(crm): the CRM home becomes an overview — setup checklist, needs-attention queue, active work, quick actions"
```

---

### Task 12: ClientViewer decision enforcement (see Decision Record)

**Files:**
- Modify: `app/Modules/CRM/Livewire/Users/UsersIndex.php` (role validation line ~152, render roles line ~334)
- Modify: `resources/views/layouts/sidebar.blade.php` (one comment + role-aware logo href, line ~109)
- Modify: `resources/views/layouts/header.blade.php` (role-aware mobile logo href, line ~45)
- Test: `tests/Feature/Crm/UsersRoleOfferingTest.php` (new); update `tests/Feature/Crm/UsersCrudTest.php` (line ~219 self-demotion test intent)

**Interfaces:**
- Consumes: `RoleName::staff()` (excludes ClientViewer), `User::isClientViewer()`, precedent comment at `TeamInvitationsPanel.php:44-46`.
- Produces: admin Users form can no longer create/demote-to ClientViewer; existing ClientViewer users remain editable (their current role stays selectable for them only).

**UsersIndex rules:**

```php
// ADR-0016: no client accounts are created on any path — staff roles only, matching
// the invitation panel. An existing CLIENT_VIEWER user stays editable (their current
// role remains valid for them), but nobody new can be pointed at the empty shell.
$allowedRoles = array_column(RoleName::staff(), 'value');
if ($this->editingUserId !== null) {
    $current = User::query()->findOrFail($this->editingUserId)->roleName()?->value;
    if ($current !== null && ! in_array($current, $allowedRoles, true)) {
        $allowedRoles[] = $current;
    }
}
// validation: 'role' => ['required', Rule::in($allowedRoles)]
```

`render()`: `'roles' => RoleName::staff()` + when editing a user whose current role isn’t staff, append it (so the select shows it) — build the same `$allowedRoles` list into enum cases for the dropdown. (Check the actual accessor for a user’s role in `UsersIndex` — it already reads roles for the table; reuse that. Verify `roleName()` exists on `User`; scouts confirmed `isClientViewer()` at `User.php:79-82` uses `roleName()`.)

**Logos:** both hrefs become `{{ auth()->user()?->isClientViewer() ? route('reports.index') : route('dashboard') }}`.

**Sidebar comment** (top of the `@php` block): `{{-- ClientViewer intentionally sees no menu: its login redirect lands on /reports, its only page (ADR-0016). --}}` — Blade comment, never rendered, lint-safe (and sidebar.blade.php is outside the linted dirs anyway).

- [ ] **Step 1: Failing tests**

```php
public function test_role_dropdown_offers_staff_roles_only_when_creating(): void
{
    $this->actingAsAdmin(); // seedRoles + makeUser(RoleName::Admin) — check the existing UsersCrudTest helper name
    Livewire::test(UsersIndex::class)
        ->call('create')
        ->assertDontSee(RoleName::ClientViewer->label());
}

public function test_creating_a_client_viewer_is_rejected(): void
{
    $this->actingAsAdmin();
    Livewire::test(UsersIndex::class)
        ->call('create')
        ->set('user_name', 'Sneaky Shell')            // match the component's actual property names
        ->set('user_email', 'shell@example.test')
        ->set('role', RoleName::ClientViewer->value)
        ->call('save')
        ->assertHasErrors(['role']);
}

public function test_an_existing_client_viewer_stays_editable_without_a_role_change(): void
{
    $this->actingAsAdmin();
    $viewer = $this->makeUser(RoleName::ClientViewer);

    Livewire::test(UsersIndex::class)
        ->call('edit', $viewer->id)
        ->set('user_name', 'Renamed Viewer')
        ->call('save')
        ->assertHasNoErrors();

    $this->assertTrue($viewer->fresh()->isClientViewer());
}

public function test_logos_point_a_client_viewer_at_reports(): void
{
    $this->seedRoles();
    $this->actingAs($this->makeUser(RoleName::ClientViewer));
    $this->get('/reports')->assertOk()->assertSee(route('reports.index'), false)
        ->assertDontSee('href="'.route('dashboard').'"', false);
}
```

(Adapt property names — `user_name`/`user_email`/`role` — to the real `UsersIndex` props before finalizing; read the class first. Update `UsersCrudTest` ~219: self-demotion-to-ClientViewer now fails on the `Rule::in` — keep the test but assert the new reason, and keep a separate test that self-demotion to another staff role is still guarded as before.)
- [ ] **Step 2: Run FAIL → implement → PASS.** Run the whole Users + Authorization + Authentication filters: `XDEBUG_MODE=off php artisan test --filter=Users && XDEBUG_MODE=off php artisan test --filter=Auth`
- [ ] **Step 3: Regression + format + commit**

```bash
XDEBUG_MODE=off php artisan test --filter=Crm
vendor/bin/pint --dirty
git add app/Modules/CRM/Livewire/Users/UsersIndex.php resources/views/layouts/sidebar.blade.php resources/views/layouts/header.blade.php tests/Feature/Crm/UsersRoleOfferingTest.php tests/Feature/Crm/UsersCrudTest.php
git commit -m "fix(crm): client-viewer stays a dormant shell — admin users form offers staff roles only, logos stop pointing viewers at a 403"
```

---

### Task 13: Final sweep — lint, full suite, progress ledger, review handoff

**Files:**
- Modify: `.superpowers/sdd/progress.md` (Stage C section — per-task lines should already exist; final summary line)
- Create: `reviews/REVIEW-crm-stage-c-2026-07-17.md`
- Possibly modify: any file pint or the lint sweep flags.

- [ ] **Step 1: Full-suite gate.** `XDEBUG_MODE=off php artisan test` — expect **0 failures** and a total comfortably above the 1035 baseline; record the exact number.
- [ ] **Step 2: Copy-lint double-check** on every blade Stage C touched: `XDEBUG_MODE=off php artisan test --filter=CrmCopyLintTest`. Verify the lint actually scans new SUBDIRECTORY blades (`resources/views/livewire/crm/partials/roster-picker.blade.php`) — read `CrmCopyLintTest`’s file-globbing; if it is not recursive, extend it to be recursive IN THE SAME COMMIT as this sweep and re-run.
- [ ] **Step 3: Spec acceptance self-check** (fix anything that fails before committing):
  - Zero-to-run without leaving a flow: wizard covers client→brand→campaign→run→creators; inline creates cover the modal paths. ✔/✘
  - Checklist reflects and links each step; disappears when complete. ✔/✘
  - 30-creator roster buildable in under a minute (picker: search + multi-check + one Add). ✔/✘
  - Campaign create asks no Status/Spend; shipment form progressive; seeding form ordered Brand-first with dependent resets. ✔/✘
  - `/crm` keeps route name `crm.index` (breadcrumbs all still work — click through Clients & Brands / Campaigns / Seedings pages). ✔/✘
- [ ] **Step 4: Write `reviews/REVIEW-crm-stage-c-2026-07-17.md`** following the Stage B handoff shape: branch + commit range + suite number, what shipped (one line per task), checkbox list for a fresh adversarial pass — at minimum: roster-picker restriction-flag vs guard parity (PHP matching duplication), bulk attach tenant stamping on pivots, wizard single-transaction integrity + authorize coverage, CSV import row isolation + handle normalization + tenant-scoped conflict checks + Monitoring enrollment volume note, inline-create authorize matrix (every type × every host), quick-action `?create=1` authorize guard, UsersIndex role restriction (existing ClientViewer edit path), Overview query costs, the two STILL-OPEN Stage B deferrals that did NOT ride along (tab a11y arrow-keys/aria-controls; Clients & Brands brand-name search; detail-page stale tab counts — Overview subsumed only the hub) and the pending monitoring-settings review coordination note (sidebar edited again in Task 12 — reviewer should pin to main `1475d25`/`ea3e994`).
- [ ] **Step 5: Update `.superpowers/sdd/progress.md`** Stage C section: final suite count, decision record pointer, handoff file path.
- [ ] **Step 6: Commit**

```bash
vendor/bin/pint --dirty
git add .superpowers/sdd/progress.md reviews/REVIEW-crm-stage-c-2026-07-17.md
git commit -m "docs(review): stage C adversarial-review handoff + progress ledger"
```

---

## Self-review (spec §Stage C → tasks)

| Spec bullet (line 318-331) | Task |
|---|---|
| New `/crm` Overview (checklist, needs-attention, active work, quick actions), old hub dies | 11 |
| Inline create inside every parent select, one reusable component | 4, 5 (+ 8 product, + 6 creator-in-picker) |
| Roster picker: searchable, multi-select, platform/follower context, restricted flagged pre-submit, copy campaign roster | 6, 7 |
| New-seeding-run button on campaign detail, prefilled; field order; product filtered to brand; `updatedSeedingBrandId` | 8 (button/prefill), 2 (form fixes) |
| Campaign wizard, thin orchestration, skippable | 10 |
| CSV creator import via `CreatorWriter` (+ remove intake promise — ALREADY DONE Stage A, verify only) | 9 |
| Create/edit splits: campaign create drops Spend (and Status per §2.5 Draft-not-user-picked); shipment progressive | 1, 3 (+ 2 extends the same split to seeding create — deliberate, consistent with §2.5/§2.6 “only name + variant asked”) |
| ClientViewer zero-nav decision (Stage B handoff line 16) | Decision Record + 12 |

**Type-consistency check:** `WithInlineCreate` property names (`inline_*`, `inlineCreate`) are hardcoded in `x-crm.inline-create` — Tasks 4/5/8 all use the trait unmodified. `ManagesCreatorRoster` names (`selectedCreatorIds`, `rosterSearch`, `showPicker`) are hardcoded in the picker partial — Tasks 6/7 share it; the wizard (10) reuses only the ROW markup, not the partial. `restrictedCreatorIdsForName(array, string): array` is consumed by Tasks 6, 7, 10. Draft forcing appears in Tasks 1, 2, 8, 10 — always server-side, never from props.

**Known intentional non-goals:** no automation/status suggestions (Stage D), no restriction-alias matching (Stage D), no BLOCKLISTED enforcement (Stage D — picker only shows an informational note), no tab a11y fix, no Monitoring UI edits, no migrations, no global search.



