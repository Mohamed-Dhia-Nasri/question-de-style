<?php

namespace App\Modules\CRM\Livewire\Seeding;

use App\Modules\CRM\Exceptions\BrandRestrictionViolation;
use App\Modules\CRM\Models\Creator;
use App\Modules\CRM\Models\SeedingCampaign;
use App\Modules\CRM\Services\BrandRestrictionGuard;
use App\Shared\Audit\AuditLogger;
use App\Shared\Tenancy\TenantRule;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

/**
 * Seeding creators panel — the seeding_campaign_creator pivot. Same
 * AC-M3-007 hard filter as campaigns: a creator with a brand restriction
 * against the run's brand is blocked from joining. Shipments can only be
 * created for creators attached here (spec D5).
 */
class SeedingCreatorsPanel extends Component
{
    public SeedingCampaign $seedingCampaign;

    public string $attach_creator_id = '';

    public ?int $confirmingDetachId = null;

    public function mount(SeedingCampaign $seedingCampaign): void
    {
        $this->authorize('view', $seedingCampaign);

        $this->seedingCampaign = $seedingCampaign;
    }

    public function attach(BrandRestrictionGuard $guard, AuditLogger $audit): void
    {
        $this->authorize('update', $this->seedingCampaign);

        $validated = $this->validate([
            'attach_creator_id' => ['required', 'integer', TenantRule::exists('creators', 'id')],
        ]);

        $creator = Creator::findOrFail((int) $validated['attach_creator_id']);

        try {
            // AC-M3-007 hard filter against the seeding run's brand.
            $guard->assertNotRestricted($creator, $this->seedingCampaign->brand);
        } catch (BrandRestrictionViolation $violation) {
            throw ValidationException::withMessages(['attach_creator_id' => $violation->getMessage()]);
        }

        $result = $this->seedingCampaign->creators()->syncWithoutDetaching([$creator->id]);

        if ($result['attached'] !== []) {
            $audit->record('seeding_campaign_creator.attached', $this->seedingCampaign, ['creator_id' => $creator->id]);
        }

        $this->seedingCampaign->refresh();
        $this->attach_creator_id = '';
        $this->resetValidation();
        $this->dispatch('notify', type: 'success', message: 'Creator added to the seeding run.');
    }

    public function confirmDetach(int $creatorId): void
    {
        $this->authorize('update', $this->seedingCampaign);

        $this->confirmingDetachId = $creatorId;
    }

    public function detach(AuditLogger $audit): void
    {
        if ($this->confirmingDetachId === null) {
            return;
        }

        $this->authorize('update', $this->seedingCampaign);

        $shipmentCount = $this->seedingCampaign->shipments()
            ->where('creator_id', $this->confirmingDetachId)
            ->count();

        if ($shipmentCount > 0) {
            $this->confirmingDetachId = null;

            throw ValidationException::withMessages([
                'detach' => "This creator has {$shipmentCount} shipment(s) on this run — delete those shipments first.",
            ]);
        }

        $this->seedingCampaign->creators()->detach($this->confirmingDetachId);

        $audit->record('seeding_campaign_creator.detached', $this->seedingCampaign, ['creator_id' => $this->confirmingDetachId]);

        $this->seedingCampaign->refresh();
        $this->confirmingDetachId = null;
        $this->dispatch('notify', type: 'success', message: 'Creator removed from the seeding run.');
    }

    public function cancelDetach(): void
    {
        $this->confirmingDetachId = null;
    }

    public function render(): View
    {
        $attached = $this->seedingCampaign->creators()->orderBy('display_name')->get();

        return view('livewire.crm.seeding-creators', [
            'attached' => $attached,
            'available' => Creator::query()
                ->whereNotIn('id', $attached->pluck('id'))
                ->orderBy('display_name')
                ->get(),
        ]);
    }
}
