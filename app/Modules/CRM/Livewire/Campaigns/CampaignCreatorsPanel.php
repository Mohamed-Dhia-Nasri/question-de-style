<?php

namespace App\Modules\CRM\Livewire\Campaigns;

use App\Modules\CRM\Exceptions\BrandRestrictionViolation;
use App\Modules\CRM\Models\Campaign;
use App\Modules\CRM\Models\Creator;
use App\Modules\CRM\Services\BrandRestrictionGuard;
use App\Shared\Audit\AuditLogger;
use App\Shared\Tenancy\TenantRule;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

/**
 * Campaign creators panel — the campaign_creator pivot (ENT-Campaign
 * participating creators). AC-M3-007: attaching a creator whose
 * ENT-BrandPreference restriction list names the campaign's brand is
 * BLOCKED (hard filter, module-3 §2.3) as a caught validation error.
 */
class CampaignCreatorsPanel extends Component
{
    public Campaign $campaign;

    public string $attach_creator_id = '';

    public ?int $confirmingDetachId = null;

    public function mount(Campaign $campaign): void
    {
        $this->authorize('view', $campaign);

        $this->campaign = $campaign;
    }

    public function attach(BrandRestrictionGuard $guard, AuditLogger $audit): void
    {
        $this->authorize('update', $this->campaign);

        $validated = $this->validate([
            'attach_creator_id' => ['required', 'integer', TenantRule::exists('creators', 'id')],
        ]);

        $creator = Creator::findOrFail((int) $validated['attach_creator_id']);

        try {
            // AC-M3-007 hard filter against the campaign's brand.
            $guard->assertNotRestricted($creator, $this->campaign->brand);
        } catch (BrandRestrictionViolation $violation) {
            throw ValidationException::withMessages(['attach_creator_id' => $violation->getMessage()]);
        }

        $result = $this->campaign->creators()->syncWithoutDetaching([$creator->id]);

        if ($result['attached'] !== []) {
            $audit->record('campaign_creator.attached', $this->campaign, ['creator_id' => $creator->id]);
        }

        $this->campaign->refresh();
        $this->attach_creator_id = '';
        $this->resetValidation();
        $this->dispatch('notify', type: 'success', message: 'Creator added to the campaign.');
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

    public function render(): View
    {
        $attached = $this->campaign->creators()->orderBy('display_name')->get();

        return view('livewire.crm.campaign-creators', [
            'attached' => $attached,
            'available' => Creator::query()
                ->whereNotIn('id', $attached->pluck('id'))
                ->orderBy('display_name')
                ->get(),
        ]);
    }
}
