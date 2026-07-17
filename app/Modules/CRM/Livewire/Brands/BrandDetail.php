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
