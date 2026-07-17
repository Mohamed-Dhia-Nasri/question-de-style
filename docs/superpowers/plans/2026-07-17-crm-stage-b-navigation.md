# CRM Stage B — Relationship Visibility & Navigation — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make every CRM relationship navigable — client visible and linked on campaign pages, a brand detail page with tabs, an expandable Clients & Brands hierarchy, CRM sub-navigation in the sidebar, detail pages restructured into tabs with Results no longer first, a creator Participation panel, and linked results rows — fixing F05, F13, F15, F16, F19 (+F11 partially).

**Architecture:** No schema changes. New pieces: one route (`crm.brands.show`), one page blade + one Livewire read component (brand detail tabs are static lists), one shared blade component (`x-crm.context-header`), one Livewire panel (`CreatorParticipationPanel`), Alpine hash-synced tabs on the two detail wrappers (all existing Livewire panels stay mounted and untouched — tabs only show/hide), sidebar children via the already-shipped-but-unused `menu-dropdown-*` CSS utilities.

**Tech Stack:** Laravel 12, Livewire 3 (+bundled Alpine), PHPUnit 11 (NOT Pest), Tailwind (TailAdmin utilities in resources/css/app.css).

## Global Constraints

- Test command: `XDEBUG_MODE=off php artisan test` (baseline at branch HEAD `6d9396c`: **977 passed / 0 failures**; `--filter=Crm` = 280). Targeted filters per task.
- Formatting: `vendor/bin/pint --dirty` before every commit. Commit WITHOUT any Co-Authored-By trailer (repo hook rejects it).
- **PARALLEL SESSION shares this branch/worktree.** NEVER touch, stage, or commit `resources/views/livewire/monitoring/monitoring-overview.blade.php`, `tests/Feature/Monitoring/MonitoringSeedingFilterTest.php`, or `app/Platform/Enrichment/Attribution/MentionClassifier.php`. `git add` ONLY the exact files you edited — never `-A`/`.`. On a transient test-DB deadlock, retry once. NEVER run `migrate:fresh` or any DB-wiping command.
- New/changed blades under `resources/views/crm|livewire/crm|components/metric|components/states` must pass `tests/Feature/Ui/CrmCopyLintTest.php` (no spec IDs/jargon in rendered text; curly apostrophes `’` in copy).
- Copy style: plain agency English; vocabulary: "seeding run(s)", "Seeding type", "Sector", module label "CRM".
- Route conventions: new routes go in `app/Modules/CRM/routes.php` inside the existing `crm.` group (`['web','auth','can:'.PermissionsCatalog::CRM_VIEW,'subscribed']`); detail pages are closures with implicit binding (tenant scope 404s foreign IDs automatically). Livewire components registered explicitly in `CrmServiceProvider::boot()` as `crm.<kebab>`.
- Links use full page loads (NO `wire:navigate` — not an established convention; the single existing use stays as-is).
- Never rename existing code identifiers, component aliases, or route names. `crm.clients.index` and `crm.brands.index` KEEP their names and URLs.
- Test helpers: `$this->seedRoles()`, `$this->makeUser(RoleName::InfluencerRelationsManager)` (staff), `RoleName::ClientViewer` (refused), `$this->makeTenant()`/`$this->withTenant()`. Full-page pattern: `$this->get(url)->assertOk()->assertSeeLivewire(Class)`.

## Shared building blocks (defined once, used by several tasks)

**Alpine tab pattern** (used by Tasks 5, 6; brand detail in Task 2 uses the same):

```blade
<div x-data="{ tab: ['overview','creators','seeding','results','docs'].includes(window.location.hash.slice(1)) ? window.location.hash.slice(1) : 'overview' }"
    x-init="$watch('tab', value => history.replaceState(null, '', '#' + value))">
    <div class="mb-4 flex flex-wrap gap-1 border-b border-gray-200 dark:border-gray-800" role="tablist">
        <button type="button" role="tab" :aria-selected="tab === 'overview'" x-on:click="tab = 'overview'"
            :class="tab === 'overview' ? 'border-brand-500 text-brand-500 dark:text-brand-400' : 'border-transparent text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200'"
            class="-mb-px border-b-2 px-4 py-2.5 text-sm font-medium">
            Overview
        </button>
        {{-- one button per tab; counts in parens where cheap: Creators ({{ $campaign->creators_count }}) --}}
    </div>
    <div x-show="tab === 'overview'" x-cloak>…</div>
    <div x-show="tab === 'creators'" x-cloak>…</div>
    …
</div>
```
Panels stay `@livewire(...)` exactly as today, just wrapped in `x-show` divs. `x-cloak` needs the standard CSS (`[x-cloak]{display:none!important}`) — check `resources/css/app.css` for an existing `x-cloak` rule; add it there if missing.

**Context header component** (Task 1 creates it):

```blade
{{-- resources/views/components/crm/context-header.blade.php
     The record's place in the hierarchy: Client › Brand › Campaign › Seeding run,
     each level a link except the current page. --}}
@props([
    'client' => null,          /** \App\Modules\CRM\Models\Client|null (linked to Clients & Brands) */
    'brand' => null,           /** \App\Modules\CRM\Models\Brand|null */
    'brandLink' => true,       /** false on the brand's own page */
    'campaign' => null,        /** \App\Modules\CRM\Models\Campaign|null */
    'campaignLink' => true,
    'seedingRun' => null,      /** \App\Modules\CRM\Models\SeedingCampaign|null */
    'status' => null,          /** enum with label() or null */
])

<div {{ $attributes->merge(['class' => 'rounded-2xl border border-gray-200 bg-white px-6 py-4 dark:border-gray-800 dark:bg-white/[0.03]']) }}>
    <div class="flex flex-wrap items-center gap-x-3 gap-y-2 text-sm text-gray-500 dark:text-gray-400">
        <nav aria-label="Record hierarchy" class="flex flex-wrap items-center gap-x-1.5 gap-y-2">
            @if ($client)
                <a href="{{ route('crm.clients.index') }}" class="font-medium text-brand-500 hover:text-brand-600 dark:text-brand-400">{{ $client->name }}</a>
            @endif
            @if ($brand)
                <span aria-hidden="true">›</span>
                @if ($brandLink)
                    <a href="{{ route('crm.brands.show', $brand) }}" class="font-medium text-brand-500 hover:text-brand-600 dark:text-brand-400">{{ $brand->name }}</a>
                @else
                    <span class="font-medium text-gray-800 dark:text-white/90">{{ $brand->name }}</span>
                @endif
            @endif
            @if ($campaign)
                <span aria-hidden="true">›</span>
                @if ($campaignLink)
                    <a href="{{ route('crm.campaigns.show', $campaign) }}" class="font-medium text-brand-500 hover:text-brand-600 dark:text-brand-400">{{ $campaign->name }}</a>
                @else
                    <span class="font-medium text-gray-800 dark:text-white/90">{{ $campaign->name }}</span>
                @endif
            @endif
            @if ($seedingRun)
                <span aria-hidden="true">›</span>
                <span class="font-medium text-gray-800 dark:text-white/90">{{ $seedingRun->name }}</span>
            @endif
        </nav>
        @if ($status)
            <x-ui.badge color="primary">{{ $status->label() }}</x-ui.badge>
        @endif
        {{ $slot }} {{-- extra facts: dates, seeding type, product — page-specific --}}
    </div>
</div>
```
Note `crm.brands.show` must exist before this renders with a brand — Task 1 lands the route stub together with the component (route in Task 1, full page in Task 2) to avoid a broken window.

---

### Task 1: Context header component + brands.show route + campaign/seeding header strips replaced

**Files:**
- Create: `resources/views/components/crm/context-header.blade.php` (code above, verbatim)
- Modify: `app/Modules/CRM/routes.php` (add brands.show + use App\Modules\CRM\Models\Brand)
- Modify: `resources/views/crm/campaign-detail.blade.php` (header strip → context header)
- Modify: `resources/views/crm/seeding-detail.blade.php` (header strip → context header)
- Create: `resources/views/crm/brand-detail.blade.php` (MINIMAL stub this task: layout + page-header + context header + "Products / Campaigns / Seeding runs live here" placeholder is NOT allowed — instead render the three count cards linking to index pages; Task 2 replaces the body with real tabs)
- Test: `tests/Feature/Crm/ContextHeaderTest.php` (new)

**Interfaces:**
- Produces: `<x-crm.context-header :client :brand :campaign :seeding-run :status :brand-link :campaign-link>` (Tasks 2, 5, 6 consume); route `crm.brands.show` → `/crm/brands/{brand}` (Tasks 2, 3, 8 consume).

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Crm;

use App\Modules\CRM\Models\Brand;
use App\Modules\CRM\Models\Campaign;
use App\Modules\CRM\Models\Client;
use App\Modules\CRM\Models\SeedingCampaign;
use App\Shared\Enums\RoleName;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Stage B (F15/F16): every detail page shows its place in the hierarchy as links. */
class ContextHeaderTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsCrmStaff(): void
    {
        $this->seedRoles();
        $this->actingAs($this->makeUser(RoleName::InfluencerRelationsManager));
    }

    public function test_campaign_detail_shows_client_and_brand_as_links(): void
    {
        $this->actingAsCrmStaff();
        $client = Client::factory()->create(['name' => 'Brückner GmbH']);
        $brand = Brand::factory()->create(['client_id' => $client->id, 'name' => 'Atelier Nord']);
        $campaign = Campaign::factory()->create(['brand_id' => $brand->id]);

        $this->get('/crm/campaigns/'.$campaign->id)
            ->assertOk()
            ->assertSee('Brückner GmbH')
            ->assertSee(route('crm.brands.show', $brand));
    }

    public function test_seeding_detail_shows_the_full_chain_including_parent_campaign(): void
    {
        $this->actingAsCrmStaff();
        $client = Client::factory()->create(['name' => 'Brückner GmbH']);
        $brand = Brand::factory()->create(['client_id' => $client->id]);
        $campaign = Campaign::factory()->create(['brand_id' => $brand->id, 'name' => 'Creator Week']);
        $seeding = SeedingCampaign::factory()->create(['brand_id' => $brand->id, 'campaign_id' => $campaign->id]);

        $this->get('/crm/seeding/'.$seeding->id)
            ->assertOk()
            ->assertSee('Brückner GmbH')
            ->assertSee(route('crm.brands.show', $brand))
            ->assertSee(route('crm.campaigns.show', $campaign));
    }

    public function test_brand_detail_route_renders_for_staff_and_404s_foreign_tenants(): void
    {
        $this->actingAsCrmStaff();
        $brand = Brand::factory()->create(['name' => 'Atelier Nord']);

        $this->get('/crm/brands/'.$brand->id)->assertOk()->assertSee('Atelier Nord');

        $tenantB = $this->makeTenant('Tenant B');
        $foreign = $this->withTenant($tenantB, fn () => Brand::factory()->create());
        $this->get('/crm/brands/'.$foreign->id)->assertNotFound();
    }

    public function test_brand_detail_is_refused_for_client_viewers(): void
    {
        $this->seedRoles();
        $brand = Brand::factory()->create();
        $this->actingAs($this->makeUser(RoleName::ClientViewer));

        $this->get('/crm/brands/'.$brand->id)->assertForbidden();
    }
}
```

- [ ] **Step 2: Run — expect FAIL** (`XDEBUG_MODE=off php artisan test --filter=ContextHeaderTest`; brands.show route missing → RouteNotFoundException in test 1 via route() call, 404 in test 3).

- [ ] **Step 3: Add the route** in `app/Modules/CRM/routes.php` after the brands index line (`use App\Modules\CRM\Models\Brand;` at top):

```php
        Route::get('/brands/{brand}', fn (Brand $brand) => view('crm.brand-detail', [
            'brand' => $brand->load('client')->loadCount(['products', 'campaigns', 'seedingCampaigns']),
        ]))->name('brands.show');
```

- [ ] **Step 4: Create the component** (code in Shared building blocks, verbatim) and `resources/views/crm/brand-detail.blade.php`:

```blade
<x-layouts.app :title="$brand->name">
    <x-page-header :title="$brand->name" :breadcrumbs="[
        'Dashboard' => route('dashboard'),
        'CRM' => route('crm.index'),
        'Clients & Brands' => route('crm.clients.index'),
        $brand->name => null,
    ]" />

    <div class="space-y-6">
        <x-crm.context-header :client="$brand->client" :brand="$brand" :brand-link="false">
            @if ($brand->sector)
                <span>Sector: <x-ui.badge color="light">{{ $brand->sector->label() }}</x-ui.badge></span>
            @endif
        </x-crm.context-header>

        <div class="grid grid-cols-1 gap-6 sm:grid-cols-3">
            <a href="{{ route('crm.products.index') }}" class="rounded-2xl border border-gray-200 bg-white p-6 hover:border-brand-300 dark:border-gray-800 dark:bg-white/[0.03]">
                <p class="text-sm text-gray-500 dark:text-gray-400">Products</p>
                <p class="mt-1 text-2xl font-semibold text-gray-800 dark:text-white/90">{{ $brand->products_count }}</p>
            </a>
            <a href="{{ route('crm.campaigns.index') }}" class="rounded-2xl border border-gray-200 bg-white p-6 hover:border-brand-300 dark:border-gray-800 dark:bg-white/[0.03]">
                <p class="text-sm text-gray-500 dark:text-gray-400">Campaigns</p>
                <p class="mt-1 text-2xl font-semibold text-gray-800 dark:text-white/90">{{ $brand->campaigns_count }}</p>
            </a>
            <a href="{{ route('crm.seeding.index') }}" class="rounded-2xl border border-gray-200 bg-white p-6 hover:border-brand-300 dark:border-gray-800 dark:bg-white/[0.03]">
                <p class="text-sm text-gray-500 dark:text-gray-400">Seeding runs</p>
                <p class="mt-1 text-2xl font-semibold text-gray-800 dark:text-white/90">{{ $brand->seeding_campaigns_count }}</p>
            </a>
        </div>
    </div>
</x-layouts.app>
```
(NOTE: `loadCount(['seedingCampaigns'])` produces `seeding_campaigns_count`. Task 2 replaces the grid with real tabs — this task must still ship a coherent page.)

- [ ] **Step 5: Replace the campaign-detail header strip** (`resources/views/crm/campaign-detail.blade.php` lines ~10-20) with:

```blade
        <x-crm.context-header :client="$campaign->brand->client" :brand="$campaign->brand" :campaign="$campaign" :campaign-link="false" :status="$campaign->status">
            <span>Dates:
                {{ $campaign->start_at?->format('d.m.Y') ?? '—' }} – {{ $campaign->end_at?->format('d.m.Y') ?? '—' }}
            </span>
        </x-crm.context-header>
```

And the seeding-detail strip (lines ~10-31) with:

```blade
        <x-crm.context-header :client="$seedingCampaign->brand->client" :brand="$seedingCampaign->brand" :campaign="$seedingCampaign->campaign" :seeding-run="$seedingCampaign" :status="$seedingCampaign->status">
            <span>Seeding type: <x-ui.badge color="light">{{ $seedingCampaign->seeding_type->label() }}</x-ui.badge></span>
            <span>Product:
                <span class="font-medium text-gray-800 dark:text-white/90">{{ $seedingCampaign->product?->name ?? '—' }}</span>
            </span>
        </x-crm.context-header>
```
(The old "Parent campaign:" span disappears — the chain shows it. Nothing else on either page changes in this task.)

- [ ] **Step 6: Run — expect PASS** (`--filter=ContextHeaderTest`), then `--filter=Crm` (280 baseline; SeedingResultsPanelTest/CampaignResultsPanelTest page assertions unaffected — they test components, not pages; if any full-page test asserted the old "Parent campaign:" text, update it and note it).
- [ ] **Step 7: Pint + commit** (`feat(crm): context header — every detail page shows its linked place in the hierarchy`, stage only your files)

---

### Task 2: Brand detail page — real tabs (Products / Campaigns / Seeding runs)

**Files:**
- Create: `app/Modules/CRM/Livewire/Brands/BrandDetail.php` + `resources/views/livewire/crm/brand-detail.blade.php` (Livewire component so tab lists paginate cheaply later; v1 renders three static lists)
- Modify: `resources/views/crm/brand-detail.blade.php` (embed the component instead of the count grid)
- Modify: `app/Modules/CRM/CrmServiceProvider.php` (register `crm.brand-detail`)
- Modify: `resources/views/livewire/crm/brands-index.blade.php` (brand name cell → link to brands.show)
- Modify: `tests/Feature/Tenancy/CrossTenantHttpTest.php` (add brand to the foreign-tenant 404 test)
- Test: `tests/Feature/Crm/BrandDetailTest.php` (new)

**Interfaces:**
- Consumes: `crm.brands.show` route + `x-crm.context-header` (Task 1); Brand relationships `products()/campaigns()/seedingCampaigns()` (exist).
- Produces: Livewire alias `crm.brand-detail`.

Component (complete):

```php
<?php

namespace App\Modules\CRM\Livewire\Brands;

use App\Modules\CRM\Models\Brand;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * Brand detail (Stage B, F15): read-only hub for one brand — its products,
 * campaigns, and seeding runs, each row linking onward. Mutations stay on
 * the index pages (crm.manage re-authorized there).
 */
class BrandDetail extends Component
{
    public Brand $brand;

    public function mount(Brand $brand): void
    {
        $this->authorize('view', $brand);

        $this->brand = $brand;
    }

    public function render(): View
    {
        return view('livewire.crm.brand-detail', [
            'products' => $this->brand->products()->orderBy('name')->get(),
            'campaigns' => $this->brand->campaigns()->withCount('creators')->orderByDesc('id')->get(),
            'seedingRuns' => $this->brand->seedingCampaigns()->withCount('shipments')->orderByDesc('id')->get(),
        ]);
    }
}
```

Blade: Alpine tab pattern (Shared building blocks) with tabs `products` / `campaigns` / `seeding` (default `products`), counts in tab labels. Each pane: a simple table (reuse `<x-table.container>`-free plain table markup like campaign-detail's seeding list). Rows: product name + product variant + unit value (with currency via `app(\App\Shared\Support\TenantCurrency::class)->code()` + `<x-metric.tier-badge>` — copy the cell from products-index); campaign name → `route('crm.campaigns.show', $campaign)` + status badge + creators count; run name → `route('crm.seeding.show', $run)` + seeding type + status badges + shipments count. Empty states per pane use `<x-states.empty>` with first-run copy + a LINK CTA to the owning index page (e.g. "Go to Products →" — the create modals live there; plain `<a>` in the action slot like campaign-detail's).

**Test (complete):**

```php
<?php

namespace Tests\Feature\Crm;

use App\Modules\CRM\Livewire\Brands\BrandDetail;
use App\Modules\CRM\Models\Brand;
use App\Modules\CRM\Models\Campaign;
use App\Modules\CRM\Models\Product;
use App\Modules\CRM\Models\SeedingCampaign;
use App\Shared\Enums\RoleName;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class BrandDetailTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsCrmStaff(): void
    {
        $this->seedRoles();
        $this->actingAs($this->makeUser(RoleName::InfluencerRelationsManager));
    }

    public function test_page_renders_the_component_with_all_three_lists(): void
    {
        $this->actingAsCrmStaff();
        $brand = Brand::factory()->create();
        $product = Product::factory()->create(['brand_id' => $brand->id, 'name' => 'Serum Eins']);
        $campaign = Campaign::factory()->create(['brand_id' => $brand->id, 'name' => 'Kampagne Eins']);
        $run = SeedingCampaign::factory()->create(['brand_id' => $brand->id, 'name' => 'Run Eins']);

        $this->get('/crm/brands/'.$brand->id)
            ->assertOk()
            ->assertSeeLivewire(BrandDetail::class)
            ->assertSee('Serum Eins')
            ->assertSee('Kampagne Eins')
            ->assertSee('Run Eins')
            ->assertSee(route('crm.campaigns.show', $campaign))
            ->assertSee(route('crm.seeding.show', $run));
    }

    public function test_component_refuses_client_viewers(): void
    {
        $this->seedRoles();
        $brand = Brand::factory()->create();
        $this->actingAs($this->makeUser(RoleName::ClientViewer));

        Livewire::test(BrandDetail::class, ['brand' => $brand])->assertForbidden();
    }

    public function test_lists_are_scoped_to_this_brand(): void
    {
        $this->actingAsCrmStaff();
        $brand = Brand::factory()->create();
        $other = Brand::factory()->create();
        Product::factory()->create(['brand_id' => $other->id, 'name' => 'Fremdes Produkt']);

        Livewire::test(BrandDetail::class, ['brand' => $brand])
            ->assertDontSee('Fremdes Produkt');
    }
}
```

Also: in `CrossTenantHttpTest::test_crm_detail_routes_404_on_a_foreign_tenant_id`, add a foreign `Brand::factory()->create()` + `$this->get("/crm/brands/{$brand->id}")->assertNotFound();` (match the file's existing style).
Brands index: the name `<td>` becomes `<a href="{{ route('crm.brands.show', $brand) }}" class="text-sm font-medium text-brand-500 hover:text-brand-600 dark:text-brand-400">{{ $brand->name }}</a>`.

- [ ] Steps: failing tests → run → implement (component + blade + registration + wrapper swap + index link + CrossTenantHttpTest line) → `--filter=BrandDetailTest`, `--filter=ContextHeaderTest`, `--filter=CrossTenantHttpTest`, `--filter=Crm` → pint → commit (`feat(crm): brand detail page — products, campaigns, seeding runs in one linked place`).

---

### Task 3: Clients & Brands hierarchy (expandable clients, linked brands)

**Files:**
- Modify: `app/Modules/CRM/Livewire/Clients/ClientsIndex.php` (eager-load brands: in `clientsQuery()` add `->with(['brands' => fn ($q) => $q->orderBy('name')])` beside the existing `->withCount('brands')`)
- Modify: `resources/views/livewire/crm/clients-index.blade.php` (each client row gets an Alpine-expandable brands sublist)
- Modify: `resources/views/crm/clients.blade.php` (page title/header → "Clients & Brands"; breadcrumb leaf "Clients & Brands")
- Test: extend `tests/Feature/Crm/ClientsCrudTest.php`

Row pattern (replace the client name cell + add a brands row): wrap each `<tr>` pair in `x-data="{ open: false }"` — Alpine on `<tbody>` fragments is brittle; instead use TWO `<tr>`s per client inside the existing `@foreach`, the second `x-show`n:

```blade
@foreach ($clients as $client)
    <tr wire:key="client-{{ $client->id }}" x-data="{ open: false }" @class(['cursor-pointer'])>
        <td class="px-5 py-4">
            <button type="button" x-on:click="open = !open" class="flex items-center gap-2 text-sm font-medium text-gray-800 dark:text-white/90">
                <svg :class="open ? 'rotate-90' : ''" class="h-4 w-4 text-gray-400 transition-transform" viewBox="0 0 24 24" fill="none"><path d="M9 6l6 6-6 6" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
                {{ $client->name }}
            </button>
        </td>
        …existing country / brands-count / created / actions cells unchanged…
    </tr>
    {{-- CAREFUL: x-data on the first <tr> does not scope to a sibling <tr>. Use this structure instead: --}}
@endforeach
```
**Correction (use this, not the sketch above):** wrap both rows in an `<tbody x-data="{ open: false }" wire:key="client-{{ $client->id }}">` per client (multiple tbody elements are valid HTML and keep Alpine scoping honest):

```blade
@foreach ($clients as $client)
    <tbody x-data="{ open: false }" wire:key="client-{{ $client->id }}" class="divide-y divide-gray-100 dark:divide-gray-800">
        <tr>
            <td class="px-5 py-4">
                <button type="button" x-on:click="open = !open" :aria-expanded="open" class="flex items-center gap-2 text-sm font-medium text-gray-800 dark:text-white/90">
                    <svg :class="open ? 'rotate-90' : ''" class="h-4 w-4 shrink-0 text-gray-400 transition-transform" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M9 6l6 6-6 6" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    {{ $client->name }}
                </button>
            </td>
            {{-- keep the existing country / Brands count / Created / Edit-Delete cells verbatim --}}
        </tr>
        <tr x-show="open" x-cloak>
            <td colspan="5" class="bg-gray-50 px-5 py-3 dark:bg-white/[0.02]">
                @if ($client->brands->isEmpty())
                    <p class="text-sm text-gray-500 dark:text-gray-400">No brands yet — create one on the <a href="{{ route('crm.brands.index') }}" class="font-medium text-brand-500 hover:text-brand-600 dark:text-brand-400">Brands page</a>.</p>
                @else
                    <ul class="flex flex-wrap gap-x-6 gap-y-1.5">
                        @foreach ($client->brands as $brand)
                            <li>
                                <a href="{{ route('crm.brands.show', $brand) }}" class="text-sm font-medium text-brand-500 hover:text-brand-600 dark:text-brand-400">{{ $brand->name }}</a>
                                @if ($brand->sector)
                                    <span class="text-theme-xs text-gray-400">· {{ $brand->sector->label() }}</span>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif
            </td>
        </tr>
    </tbody>
@endforeach
```
(Adjust: the table's original single `<tbody>` wrapper is removed; `colspan` = the real column count. Keep the existing wire:loading tbody classes on each tbody.)

**Tests** (add to ClientsCrudTest): `test_client_rows_list_their_brands_with_links_to_brand_detail` — create client + 2 brands, `Livewire::test(ClientsIndex::class)->assertSee('brand-a name')->assertSee(route('crm.brands.show', $brandA))`; and assert a brandless client shows the inline "No brands yet" line. Page test: `$this->get('/crm/clients')->assertOk()->assertSee('Clients & Brands')`.

- [ ] Steps: failing tests → implement → `--filter=ClientsCrudTest` + `--filter=Crm` (CrmEmptyStatesTest asserts clients empty-state strings — unchanged by this task; verify) → pint → commit (`feat(crm): clients page becomes the Clients & Brands hierarchy`).

---

### Task 4: Sidebar CRM children; Discovery + Reports leave the nav

**Files:**
- Modify: `resources/views/layouts/sidebar.blade.php`
- Test: `tests/Feature/Crm/CrmNavigationTest.php` (new)

Changes:
1. DELETE the Discovery and Reports entries from `$menuItems` (routes/pages stay; F19).
2. Give the CRM entry a `children` array:

```php
            'children' => [
                ['name' => 'Overview', 'route' => 'crm.index', 'active' => request()->routeIs('crm.index')],
                ['name' => 'Clients & Brands', 'route' => 'crm.clients.index', 'active' => request()->routeIs('crm.clients.*') || request()->routeIs('crm.brands.*')],
                ['name' => 'Products', 'route' => 'crm.products.index', 'active' => request()->routeIs('crm.products.*')],
                ['name' => 'Creators', 'route' => 'crm.creators.index', 'active' => request()->routeIs('crm.creators.*')],
                ['name' => 'Campaigns', 'route' => 'crm.campaigns.index', 'active' => request()->routeIs('crm.campaigns.*')],
                ['name' => 'Seeding runs', 'route' => 'crm.seeding.index', 'active' => request()->routeIs('crm.seeding.*')],
                ['name' => 'Results', 'route' => 'crm.results', 'active' => request()->routeIs('crm.results')],
                ['name' => 'Tasks', 'route' => 'crm.tasks.index', 'active' => request()->routeIs('crm.tasks.*')],
            ],
```
3. In the menu loop, after the parent `<a>` (the `<li>` for each item), render children when present AND the parent is active, using the existing unused utilities (`menu-dropdown-item`, `menu-dropdown-item-active`, `menu-dropdown-item-inactive` — resources/css/app.css:230-240), gated by the same expand state as labels:

```blade
@if (($item['children'] ?? []) !== [] && $item['active'])
    <ul class="mt-1 flex flex-col gap-0.5 pl-11"
        x-show="$store.sidebar.isExpanded || $store.sidebar.isHovered || $store.sidebar.isMobileOpen">
        @foreach ($item['children'] as $child)
            <li>
                <a href="{{ route($child['route']) }}"
                    class="menu-dropdown-item {{ $child['active'] ? 'menu-dropdown-item-active' : 'menu-dropdown-item-inactive' }}">
                    {{ $child['name'] }}
                </a>
            </li>
        @endforeach
    </ul>
@endif
```
(Match the exact loop structure/indentation in the file; the parent `@can` already wraps the whole `<li>`.)

**Test (complete):**

```php
<?php

namespace Tests\Feature\Crm;

use App\Shared\Enums\RoleName;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Stage B (F16/F19): CRM has sub-navigation; stub areas leave the nav. */
class CrmNavigationTest extends TestCase
{
    use RefreshDatabase;

    public function test_crm_pages_show_the_crm_sub_navigation(): void
    {
        $this->seedRoles();
        $this->actingAs($this->makeUser(RoleName::InfluencerRelationsManager));

        $response = $this->get('/crm/campaigns');
        $response->assertOk()
            ->assertSee('Clients &amp; Brands', false)
            ->assertSee('Seeding runs')
            ->assertSee(route('crm.tasks.index'));
    }

    public function test_non_crm_pages_do_not_show_the_crm_children(): void
    {
        $this->seedRoles();
        $this->actingAs($this->makeUser(RoleName::InfluencerRelationsManager));

        $this->get('/dashboard')->assertOk()->assertDontSee(route('crm.tasks.index'));
    }

    public function test_discovery_and_reports_left_the_sidebar_but_stay_routable(): void
    {
        $this->seedRoles();
        $this->actingAs($this->makeUser(RoleName::Admin));

        $this->get('/dashboard')->assertOk()->assertDontSee('>Discovery<', false);
        $this->get('/discovery')->assertOk();
        $this->get('/reports')->assertOk();
    }
}
```
(Verify the Discovery assertion string against actual rendered markup — the label sits inside a span; adjust to a robust assertion like `assertDontSee(route('discovery.index'))` if the sidebar renders the URL only for nav items.)

- [ ] Steps: failing test → implement → `--filter=CrmNavigationTest` + `--filter=Crm` + `--filter=HomeDashboardTest` → pint → commit (`feat(crm): sidebar sub-navigation for CRM; Discovery and Reports leave the nav`).

---

### Task 5: Campaign detail — tabs, Overview with setup guide, Results demoted

**Files:**
- Modify: `app/Modules/CRM/routes.php` (campaigns.show closure: `view('crm.campaign-detail', ['campaign' => $campaign->load('brand.client')->loadCount(['creators', 'seedingCampaigns'])])`)
- Modify: `resources/views/crm/campaign-detail.blade.php` (tab structure)
- Test: `tests/Feature/Crm/CampaignDetailPageTest.php` (new)

Tab set: `overview` (default) | `creators` | `seeding` | `results` | `docs`. Tab labels with counts: `Creators ({{ $campaign->creators_count }})`, `Seeding runs ({{ $campaign->seeding_campaigns_count }})`. Existing panels move INSIDE panes unchanged: creators pane = `@livewire('crm.campaign-creators', ...)`; seeding pane = the existing seeding-runs card (from Task 1 state); results pane = `@livewire('crm.campaign-results', ...)`; docs pane = documents-panel + tasks-panel.

Overview pane content (new markup, no new component):

```blade
<div x-show="tab === 'overview'" x-cloak class="space-y-6">
    @php
        $setupSteps = [
            ['done' => $campaign->start_at !== null && $campaign->end_at !== null, 'label' => 'Set the campaign dates', 'hint' => 'Edit the campaign on the Campaigns page.'],
            ['done' => $campaign->creators_count > 0, 'label' => 'Add participating creators', 'go' => 'creators'],
            ['done' => $campaign->seeding_campaigns_count > 0, 'label' => 'Create a seeding run (optional)', 'go' => 'seeding'],
        ];
        $openSteps = collect($setupSteps)->where('done', false);
    @endphp

    @if (in_array($campaign->status, [\App\Shared\Enums\CampaignStatus::Draft, \App\Shared\Enums\CampaignStatus::Planned], true) && $openSteps->isNotEmpty())
        <div class="rounded-2xl border border-gray-200 bg-white p-6 dark:border-gray-800 dark:bg-white/[0.03]">
            <h3 class="text-base font-semibold text-gray-800 dark:text-white/90">Finish setting up</h3>
            <ul class="mt-3 space-y-2">
                @foreach ($setupSteps as $step)
                    <li class="flex items-center gap-2.5 text-sm {{ $step['done'] ? 'text-gray-400 line-through' : 'text-gray-700 dark:text-gray-300' }}">
                        <span class="flex h-5 w-5 items-center justify-center rounded-full {{ $step['done'] ? 'bg-success-50 text-success-600 dark:bg-success-500/10' : 'bg-gray-100 text-gray-400 dark:bg-white/5' }}">
                            @if ($step['done'])✓@else○@endif
                        </span>
                        @if (! $step['done'] && isset($step['go']))
                            <button type="button" x-on:click="tab = '{{ $step['go'] }}'" class="font-medium text-brand-500 hover:text-brand-600 dark:text-brand-400">{{ $step['label'] }} →</button>
                        @else
                            <span>{{ $step['label'] }}@if (! $step['done'] && isset($step['hint'])) <span class="text-gray-400">— {{ $step['hint'] }}</span>@endif</span>
                        @endif
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="grid grid-cols-1 gap-6 sm:grid-cols-3">
        <div class="rounded-2xl border border-gray-200 bg-white p-6 dark:border-gray-800 dark:bg-white/[0.03]">
            <p class="text-sm text-gray-500 dark:text-gray-400">Creators</p>
            <p class="mt-1 text-2xl font-semibold text-gray-800 dark:text-white/90">{{ $campaign->creators_count }}</p>
        </div>
        <div class="rounded-2xl border border-gray-200 bg-white p-6 dark:border-gray-800 dark:bg-white/[0.03]">
            <p class="text-sm text-gray-500 dark:text-gray-400">Seeding runs</p>
            <p class="mt-1 text-2xl font-semibold text-gray-800 dark:text-white/90">{{ $campaign->seeding_campaigns_count }}</p>
        </div>
        <div class="rounded-2xl border border-gray-200 bg-white p-6 dark:border-gray-800 dark:bg-white/[0.03]">
            <p class="text-sm text-gray-500 dark:text-gray-400">Status</p>
            <p class="mt-1"><x-ui.badge color="primary">{{ $campaign->status->label() }}</x-ui.badge></p>
            <p class="mt-1 text-theme-xs text-gray-500 dark:text-gray-400">{{ $campaign->status->description() }}</p>
        </div>
    </div>
</div>
```

**Test (complete):**

```php
<?php

namespace Tests\Feature\Crm;

use App\Modules\CRM\Livewire\Campaigns\CampaignCreatorsPanel;
use App\Modules\CRM\Livewire\Results\CampaignResultsPanel;
use App\Modules\CRM\Models\Campaign;
use App\Shared\Enums\CampaignStatus;
use App\Shared\Enums\RoleName;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CampaignDetailPageTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsCrmStaff(): void
    {
        $this->seedRoles();
        $this->actingAs($this->makeUser(RoleName::InfluencerRelationsManager));
    }

    public function test_detail_page_has_tabs_and_still_mounts_all_panels(): void
    {
        $this->actingAsCrmStaff();
        $campaign = Campaign::factory()->create();

        $this->get('/crm/campaigns/'.$campaign->id)
            ->assertOk()
            ->assertSee('Overview')
            ->assertSeeLivewire(CampaignCreatorsPanel::class)
            ->assertSeeLivewire(CampaignResultsPanel::class);
    }

    public function test_draft_campaign_shows_the_setup_guide(): void
    {
        $this->actingAsCrmStaff();
        $campaign = Campaign::factory()->create(['status' => CampaignStatus::Draft, 'start_at' => null, 'end_at' => null]);

        $this->get('/crm/campaigns/'.$campaign->id)
            ->assertOk()
            ->assertSee('Finish setting up')
            ->assertSee('Set the campaign dates');
    }

    public function test_active_campaign_does_not_show_the_setup_guide(): void
    {
        $this->actingAsCrmStaff();
        $campaign = Campaign::factory()->create(['status' => CampaignStatus::Active]);

        $this->get('/crm/campaigns/'.$campaign->id)
            ->assertOk()
            ->assertDontSee('Finish setting up');
    }
}
```

- [ ] Steps: failing test → implement (route closure + blade restructure; context header from Task 1 stays above the tabs) → `--filter=CampaignDetailPageTest` + `--filter=Crm` → pint → commit (`feat(crm): campaign detail in tabs — setup guide first, results when you ask for them`).

---

### Task 6: Seeding detail — same tab treatment

**Files:**
- Modify: `app/Modules/CRM/routes.php` (seeding.show closure: `->load(['brand.client', 'campaign', 'product'])->loadCount(['creators', 'shipments'])`)
- Modify: `resources/views/crm/seeding-detail.blade.php`
- Test: `tests/Feature/Crm/SeedingDetailPageTest.php` (new — mirror CampaignDetailPageTest: tabs render, all four panels still mount (SeedingCreatorsPanel, ShipmentsPanel, SeedingResultsPanel, DocumentsPanel), Draft run shows a setup guide)

Tabs: `overview` (default) | `creators` (`Creators ({{ $seedingCampaign->creators_count }})`) | `shipments` (`Shipments ({{ $seedingCampaign->shipments_count }})`) | `results` | `docs`. Overview pane: setup guide for Draft/Planned (`$setupSteps`: product chosen (`product_id !== null`, hint 'Edit the run on the Seeding runs page.'), creators added (count, go 'creators'), first shipment recorded (count, go 'shipments')) + count cards (Creators / Shipments / Status+description) — same markup shapes as Task 5.

- [ ] Steps: failing test → implement → `--filter=SeedingDetailPageTest` + `--filter=Crm` → pint → commit (`feat(crm): seeding run detail in tabs with setup guide`).

---

### Task 7: Creator Participation panel + display-first identity + Monitoring cross-links

**Files:**
- Create: `app/Modules/CRM/Livewire/Creators/ParticipationPanel.php` + `resources/views/livewire/crm/creator-participation.blade.php`
- Modify: `app/Modules/CRM/CrmServiceProvider.php` (register `crm.creator-participation`)
- Modify: `resources/views/crm/creator-profile.blade.php` (mount the panel directly under the identity card)
- Modify: `app/Modules/CRM/Livewire/Creators/CreatorProfile.php` + `resources/views/livewire/crm/creator-profile.blade.php` (display-first: `public bool $editing = false;`, read view with an Edit button `@can('update', $creator)`, form shown only when `$editing`; `save()` sets `$this->editing = false` after success; add `edit()`/`cancelEdit()` methods with `$this->authorize('update', $this->creator)` in `edit()`)
- Modify: monitoring cross-link, BOTH directions:
  - CRM profile: in the identity card header area add `@can('monitoring.view')<a href="{{ route('monitoring.creators.show', $creator) }}" class="text-sm font-medium text-brand-500 hover:text-brand-600 dark:text-brand-400">View monitoring →</a>@endcan`
  - Monitoring: `resources/views/livewire/monitoring/creator-detail.blade.php` — next to the creator heading add `@can('crm.view')<a href="{{ route('crm.creators.show', $creator) }}" class="...">View CRM profile →</a>@endcan` (this file is NOT owned by the parallel session — verify with `git status` before editing; if it appears dirty, STOP and report BLOCKED).
- Test: `tests/Feature/Crm/ParticipationPanelTest.php` (new) + extend `tests/Feature/Crm/CreatorProfileTest.php`

ParticipationPanel (complete):

```php
<?php

namespace App\Modules\CRM\Livewire\Creators;

use App\Modules\CRM\Models\Creator;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * Participation panel (Stage B, F05): what this creator is involved in —
 * campaigns, seeding runs, and shipments — each row linking onward.
 * Read-only; reads need crm.view (mount authorizes view on the creator).
 */
class ParticipationPanel extends Component
{
    public Creator $creator;

    public function mount(Creator $creator): void
    {
        $this->authorize('view', $creator);

        $this->creator = $creator;
    }

    public function render(): View
    {
        return view('livewire.crm.creator-participation', [
            'campaigns' => $this->creator->campaigns()->with('brand')->orderByDesc('id')->get(),
            'seedingRuns' => $this->creator->seedingCampaigns()->with('brand')->orderByDesc('id')->get(),
            'shipments' => $this->creator->shipments()->with(['product', 'seedingCampaign'])->orderByDesc('id')->get(),
        ]);
    }
}
```

Blade: one card, three sections (Campaigns / Seeding runs / Shipments) using the profile's existing panel-card markup convention (header h3 + subtitle + list). Campaign rows: name → `route('crm.campaigns.show', $campaign)` + brand name + status badge. Run rows: name → `route('crm.seeding.show', $run)` + seeding-type + status badges. Shipment rows: product name + status badge (`$shipment->status->label()`) + run name → run link; cap shipments list at the latest 10 with a "…and N more on the seeding run pages." line when more. Empty state (all three empty): `<x-states.empty title="Not involved in anything yet">This creator isn’t on any campaign or seeding run. Add them from a campaign or seeding run page.</x-states.empty>`.

Panel test: staff sees campaign/run/shipment names + links (attach creator to campaign + run + create shipment via factories; use `$campaign->creators()->attach($creator->id)` — tenant pivot stamping exists); ClientViewer refused; a foreign creator's rows never leak (create second creator with own participation, assertDontSee).
CreatorProfileTest additions: `test_identity_is_display_first_and_edit_toggles_the_form` (initial render shows display name as TEXT and an Edit button, NOT a submit "Save identity" button; `Livewire::test(CreatorProfile::class, ['creator' => $creator])->call('edit')->assertSet('editing', true)`; after `->call('save')` editing is false) and `test_profile_page_mounts_the_participation_panel` (assertSeeLivewire(ParticipationPanel::class)). CHECK existing methods in CreatorProfileTest asserting the always-on form (e.g. the identity-card render test) and update them to the display-first reality.

- [ ] Steps: failing tests → implement → `--filter=ParticipationPanelTest` + `--filter=CreatorProfileTest` + `--filter=Crm` → pint → commit (`feat(crm): creator participation panel, display-first identity, monitoring cross-links`).

---

### Task 8: Results rows become links

**Files:**
- Modify: `resources/views/livewire/crm/seeding-results-dashboard.blade.php` (both tables: product name cell → `<a href="{{ route('crm.products.index', ['q' => $productNames[$row->product_id] ?? '']) }}" class="font-medium text-brand-500 hover:text-brand-600 dark:text-brand-400">…</a>`; keep the `#id` fallback plain when the name is unknown)
- Modify: `resources/views/livewire/crm/campaign-results.blade.php` (per-run rows: run NAME cell → `route('crm.seeding.show', $run)` link — the blade iterates loaded `$campaign->seedingCampaigns` models, so the model is at hand)
- Modify: `resources/views/livewire/crm/seeding-results.blade.php` (per-creator rows: creator name → `route('crm.creators.show', $row->creator_id)`; per-shipment rows: same for the creator cell. The rollup rows carry `creator_id`; names come from the existing display-side lookup — check the blade for the exact variable, e.g. `$creatorNames[$row->creator_id]`, and wrap that expression)
- Test: extend `tests/Feature/Crm/SeedingResultsPanelTest.php` + `tests/Feature/Crm/CampaignResultsPanelTest.php` with link assertions (`assertSee(route('crm.creators.show', $creator->id))` etc. in the existing rollup-seeded test methods — piggyback on tests that already build rollup rows rather than building new fixtures)

- [ ] Steps: read the three blades first (exact cell markup); failing link assertions → implement → `--filter=SeedingResultsPanelTest` + `--filter=CampaignResultsPanelTest` + `--filter=SeedingResultsDashboardTest` + `--filter=Crm` → pint → commit (`feat(crm): results rows link back to their records`).

---

### Task 9: Full verification sweep

- [ ] `XDEBUG_MODE=off php artisan test --filter=CrmCopyLintTest` — new blades are jargon-free (fix blades, not the test).
- [ ] FULL suite `XDEBUG_MODE=off php artisan test` — report exact totals (baseline 977 + new tests; parallel session may have added more — count only that nothing FAILS).
- [ ] `vendor/bin/pint --dirty` clean; commit anything outstanding (`test(crm): stage B verification sweep` only if files changed).

---

## Self-review checklist

1. Spec coverage: F05 (T7), F13 (T5, T6), F15 (T1, T2, T3, T8), F16 (T4, T7 cross-links), F19 (T4), F11-partial (campaign seeding tab keeps the Go-to link; full create-from-campaign is Stage C).
2. Acceptance: campaign page shows its client (T1); creator page lists campaigns (T7); one-click reach from any record to related records (T1-T3, T8).
3. No route renames; `crm.clients.index`/`crm.brands.index` intact; all new writes none (Stage B is read-only surfaces + nav).
4. Parallel-session files untouched.
