<?php

namespace App\Modules\CRM\Livewire\Campaigns;

use App\Modules\CRM\Livewire\Concerns\ManagesCreatorRoster;
use App\Modules\CRM\Models\Brand;
use App\Modules\CRM\Models\Campaign;
use App\Modules\CRM\Models\Creator;
use App\Modules\CRM\Services\BrandRestrictionGuard;
use App\Shared\Audit\AuditLogger;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Livewire\Component;

/**
 * Campaign creators panel — the campaign_creator pivot (ENT-Campaign
 * participating creators). Adding creators is the shared multi-select roster
 * picker (ManagesCreatorRoster): AC-M3-007 restrictions against the
 * campaign's brand are flagged in the picker and skipped at save, and the
 * per-creator hard filter still enforces at attach time. Detach stays
 * unguarded here — the asymmetry with seeding (where shipments block detach)
 * is intentional.
 */
class CampaignCreatorsPanel extends Component
{
    use ManagesCreatorRoster;

    public Campaign $campaign;

    public ?int $confirmingDetachId = null;

    public function mount(Campaign $campaign): void
    {
        $this->authorize('view', $campaign);

        $this->campaign = $campaign;
    }

    protected function rosterOwner(): Model
    {
        return $this->campaign;
    }

    protected function rosterBrand(): Brand
    {
        return $this->campaign->brand;
    }

    /** @return BelongsToMany<Creator, Campaign> */
    protected function rosterRelation(): BelongsToMany
    {
        return $this->campaign->creators();
    }

    protected function rosterAuditEvent(): string
    {
        return 'campaign_creator.attached';
    }

    public function confirmDetach(int $creatorId): void
    {
        $this->authorize('update', $this->campaign);

        $this->confirmingDetachId = $creatorId;
    }

    public function detach(AuditLogger $audit): void
    {
        if ($this->confirmingDetachId === null) {
            return;
        }

        $this->authorize('update', $this->campaign);

        $this->campaign->creators()->detach($this->confirmingDetachId);

        $audit->record('campaign_creator.detached', $this->campaign, ['creator_id' => $this->confirmingDetachId]);

        $this->campaign->refresh();
        $this->confirmingDetachId = null;
        $this->dispatch('notify', type: 'success', message: 'Creator removed from the campaign.');
    }

    public function cancelDetach(): void
    {
        $this->confirmingDetachId = null;
    }

    public function render(BrandRestrictionGuard $guard): View
    {
        return view('livewire.crm.campaign-creators', $this->pickerViewData($guard));
    }
}
