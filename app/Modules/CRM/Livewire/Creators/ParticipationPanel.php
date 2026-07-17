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
