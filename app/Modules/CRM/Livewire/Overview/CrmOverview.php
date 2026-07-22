<?php

namespace App\Modules\CRM\Livewire\Overview;

use App\Modules\CRM\Livewire\Tasks\TasksIndex;
use App\Modules\CRM\Models\Brand;
use App\Modules\CRM\Models\Campaign;
use App\Modules\CRM\Models\Client;
use App\Modules\CRM\Models\Creator;
use App\Modules\CRM\Models\SeedingCampaign;
use App\Modules\CRM\Models\Shipment;
use App\Modules\CRM\Models\Task;
use App\Shared\Authorization\PermissionsCatalog;
use App\Shared\Enums\CampaignStatus;
use App\Shared\Enums\SeedingCampaignStatus;
use App\Shared\Enums\ShipmentStatus;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * The `/crm` home (F02, Stage C) — an operational Overview. No mutators of
 * its own: a setup checklist (shown only until every step exists, then it
 * simply disappears), a clickable headline strip (clients, brands, creators,
 * active campaigns, active runs), a needs-attention queue (overdue tasks,
 * creator-less seeding runs, shipments stuck in transit for over a week), the
 * five most recently touched active campaigns/seeding runs (runs carry a
 * delivery-progress read), and quick-action links into the four index
 * components' create flows (see the `?create=1` mount() hook on each).
 *
 * Every query is tenant-scoped automatically (BelongsToTenant on every
 * model read here) — no explicit tenant filtering needed.
 */
class CrmOverview extends Component
{
    public function mount(): void
    {
        $this->authorize(PermissionsCatalog::CRM_VIEW);
    }

    public function render(): View
    {
        $counts = [
            'clients' => Client::query()->count(),
            'brands' => Brand::query()->count(),
            'creators' => Creator::query()->count(),
            'campaigns' => Campaign::query()->count(),
            'runs' => SeedingCampaign::query()->count(),
        ];

        $checklist = [
            [
                'done' => $counts['clients'] > 0,
                'label' => 'Create your first client',
                'hint' => 'The company you work for.',
                'url' => route('crm.clients.index').'?create=1',
                'can' => true,
            ],
            [
                'done' => $counts['brands'] > 0,
                'label' => 'Add a brand',
                'hint' => 'Brands belong to a client and own campaigns and products.',
                'url' => route('crm.brands.index').'?create=1',
                'can' => true,
            ],
            [
                'done' => $counts['creators'] > 0,
                'label' => 'Add creators',
                'hint' => 'One by one or from a CSV file — new creators are monitored automatically.',
                'url' => route('crm.creators.index').'?create=1',
                'can' => true,
            ],
            [
                'done' => $counts['campaigns'] > 0,
                'label' => 'Create your first campaign',
                'hint' => 'Plan and measure work for one brand.',
                'url' => route('crm.campaigns.create'),
                // The wizard's mount() authorizes create at the route level
                // (a hard 403, not a graceful degrade like the ?create=1
                // rows above) — only linkify it when the viewer can actually
                // pass that gate.
                'can' => auth()->user()->can('create', Campaign::class),
            ],
            [
                'done' => $counts['runs'] > 0,
                'label' => 'Launch a seeding run',
                'hint' => 'Send products to creators and track what they post.',
                'url' => route('crm.seeding.index').'?create=1',
                'can' => true,
            ],
        ];
        $setupComplete = collect($checklist)->every(fn ($s) => $s['done']);

        // "Active" = the same in-progress status sets the lists below use, so
        // each headline count agrees with its list. Clickable tiles orient the
        // operator and jump straight into the relevant index.
        $activeCampaignStatuses = [CampaignStatus::Planned, CampaignStatus::Active, CampaignStatus::Paused];
        $activeRunStatuses = [SeedingCampaignStatus::Planned, SeedingCampaignStatus::Active, SeedingCampaignStatus::Shipping];

        $kpis = [
            ['label' => 'Clients', 'value' => $counts['clients'], 'url' => route('crm.clients.index')],
            ['label' => 'Brands', 'value' => $counts['brands'], 'url' => route('crm.brands.index')],
            ['label' => 'Creators', 'value' => $counts['creators'], 'url' => route('crm.creators.index')],
            ['label' => 'Active campaigns', 'value' => Campaign::query()->whereIn('status', $activeCampaignStatuses)->count(), 'url' => route('crm.campaigns.index')],
            ['label' => 'Active runs', 'value' => SeedingCampaign::query()->whereIn('status', $activeRunStatuses)->count(), 'url' => route('crm.seeding.index')],
        ];

        // Needs-attention queue — the ACTUAL records (not just counts) so the
        // operator sees WHICH ones and jumps straight to them. Capped at 6; the
        // blade shows the first 5 and a "see all" link when a group is longer.
        $openStatuses = TasksIndex::openStatuses();

        $overdueTasks = Task::query()
            ->whereIn('status', $openStatuses)
            ->whereNotNull('due_at')->where('due_at', '<', now())
            ->orderBy('due_at')->limit(6)->get();

        $emptyRuns = SeedingCampaign::query()
            ->whereNotIn('status', [SeedingCampaignStatus::Completed, SeedingCampaignStatus::Cancelled])
            ->whereDoesntHave('creators')
            ->latest('updated_at')->limit(6)->get();

        $awaitedShipments = Shipment::query()
            ->whereIn('status', [ShipmentStatus::Shipped, ShipmentStatus::InTransit])
            ->whereNotNull('shipped_at')->where('shipped_at', '<', now()->subDays(7))
            ->whereNull('delivered_at')
            ->with(['creator', 'seedingCampaign'])
            ->orderBy('shipped_at')->limit(6)->get();

        $activeCampaigns = Campaign::query()
            ->whereIn('status', $activeCampaignStatuses)
            ->withCount(['creators', 'seedingCampaigns'])->latest('updated_at')->limit(5)->get();
        $activeRuns = SeedingCampaign::query()
            ->whereIn('status', $activeRunStatuses)
            ->withCount([
                'creators', 'shipments',
                // Progress read (mirrors the seeding-detail route): delivered vs
                // total, plus how many parcels' posts have landed.
                'shipments as delivered_count' => fn ($q) => $q->where('status', ShipmentStatus::Delivered),
                'shipments as posted_count' => fn ($q) => $q->where('posted', true),
            ])
            ->latest('updated_at')->limit(5)->get();

        return view('livewire.crm.overview', [
            'checklist' => $checklist,
            'setupComplete' => $setupComplete,
            'kpis' => $kpis,
            'overdueTasks' => $overdueTasks,
            'emptyRuns' => $emptyRuns,
            'awaitedShipments' => $awaitedShipments,
            'activeCampaigns' => $activeCampaigns,
            'activeRuns' => $activeRuns,
        ]);
    }
}
