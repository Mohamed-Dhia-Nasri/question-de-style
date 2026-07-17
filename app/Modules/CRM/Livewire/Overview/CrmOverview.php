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
 * The `/crm` home (F02, Stage C) — an operational Overview replacing the
 * old 8-card hub. Four reads only, no mutators of its own: a setup
 * checklist (collapses to a success pill once every step exists), a
 * needs-attention queue (overdue tasks, creator-less seeding runs, shipments
 * stuck in transit for over a week), the five most recently touched active
 * campaigns/seeding runs, and quick-action links into the four index
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

        $openStatuses = TasksIndex::openStatuses();
        $attention = [
            'overdueTasks' => Task::query()->whereIn('status', $openStatuses)
                ->whereNotNull('due_at')->where('due_at', '<', now())->count(),
            'emptyRuns' => SeedingCampaign::query()
                ->whereNotIn('status', [SeedingCampaignStatus::Completed, SeedingCampaignStatus::Cancelled])
                ->whereDoesntHave('creators')->count(),
            'awaitedShipments' => Shipment::query()
                ->whereIn('status', [ShipmentStatus::Shipped, ShipmentStatus::InTransit])
                ->whereNotNull('shipped_at')->where('shipped_at', '<', now()->subDays(7))
                ->whereNull('delivered_at')->count(),
        ];

        $activeCampaigns = Campaign::query()
            ->whereIn('status', [CampaignStatus::Planned, CampaignStatus::Active, CampaignStatus::Paused])
            ->withCount(['creators', 'seedingCampaigns'])->latest('updated_at')->limit(5)->get();
        $activeRuns = SeedingCampaign::query()
            ->whereIn('status', [SeedingCampaignStatus::Planned, SeedingCampaignStatus::Active, SeedingCampaignStatus::Shipping])
            ->withCount(['creators', 'shipments'])->latest('updated_at')->limit(5)->get();

        return view('livewire.crm.overview', [
            'checklist' => $checklist,
            'setupComplete' => $setupComplete,
            'attention' => $attention,
            'activeCampaigns' => $activeCampaigns,
            'activeRuns' => $activeRuns,
        ]);
    }
}
