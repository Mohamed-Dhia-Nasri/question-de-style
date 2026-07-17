<?php

namespace App\Modules\CRM\Livewire\Seeding;

use App\Modules\CRM\Livewire\Concerns\ManagesCreatorRoster;
use App\Modules\CRM\Models\Brand;
use App\Modules\CRM\Models\Creator;
use App\Modules\CRM\Models\SeedingCampaign;
use App\Modules\CRM\Services\BrandRestrictionGuard;
use App\Shared\Audit\AuditLogger;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

/**
 * Seeding creators panel — the seeding_campaign_creator pivot. Adding
 * creators is the shared multi-select roster picker (ManagesCreatorRoster),
 * the same AC-M3-007 hard filter as campaigns applied against the run's
 * brand, plus a one-click "copy the parent campaign's roster" shortcut for
 * runs spawned from a campaign. Detach stays shipment-guarded here — a
 * creator with shipments on the run can't be removed until those shipments
 * are gone (the asymmetry with campaigns is intentional; shipments can only
 * be created for creators attached here, spec D5).
 */
class SeedingCreatorsPanel extends Component
{
    use ManagesCreatorRoster;

    public SeedingCampaign $seedingCampaign;

    public ?int $confirmingDetachId = null;

    public function mount(SeedingCampaign $seedingCampaign): void
    {
        $this->authorize('view', $seedingCampaign);

        $this->seedingCampaign = $seedingCampaign;
    }

    protected function rosterOwner(): Model
    {
        return $this->seedingCampaign;
    }

    protected function rosterBrand(): Brand
    {
        return $this->seedingCampaign->brand;
    }

    /** @return BelongsToMany<Creator, SeedingCampaign> */
    protected function rosterRelation(): BelongsToMany
    {
        return $this->seedingCampaign->creators();
    }

    protected function rosterAuditEvent(): string
    {
        return 'seeding_campaign_creator.attached';
    }

    /**
     * One-click shortcut for runs spawned from a campaign: attach every
     * creator on the parent campaign's roster that isn't already on this
     * run, skipping any the run's brand restricts. A single
     * syncWithoutDetaching keeps the write atomic; the bulk restriction
     * check is the same non-throwing companion the picker uses.
     */
    public function copyCampaignRoster(BrandRestrictionGuard $guard, AuditLogger $audit): void
    {
        $this->authorize('update', $this->seedingCampaign);

        if ($this->seedingCampaign->campaign_id === null) {
            $this->dispatch('notify', type: 'error', message: 'This run has no parent campaign.');

            return;
        }

        $sourceIds = $this->seedingCampaign->campaign->creators()
            ->pluck('creators.id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $attachedIds = $this->seedingCampaign->creators()
            ->pluck('creators.id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $alreadyCount = count(array_intersect($sourceIds, $attachedIds));
        $candidateIds = array_values(array_diff($sourceIds, $attachedIds));

        $restrictedIds = $guard->restrictedCreatorIds($candidateIds, $this->seedingCampaign->brand);
        $blocklistedIds = $guard->blocklistedCreatorIds($candidateIds);
        $excludedIds = array_values(array_unique(array_merge($restrictedIds, $blocklistedIds)));
        $allowedIds = array_values(array_diff($candidateIds, $excludedIds));

        if ($allowedIds !== []) {
            $result = $this->seedingCampaign->creators()->syncWithoutDetaching($allowedIds);

            foreach ($result['attached'] as $attachedId) {
                $audit->record('seeding_campaign_creator.attached', $this->seedingCampaign, ['creator_id' => (int) $attachedId]);
            }
        }

        $this->seedingCampaign->refresh();

        $parts = [];

        if ($allowedIds !== []) {
            $parts[] = count($allowedIds).' added';
        }

        if ($alreadyCount > 0) {
            $parts[] = $alreadyCount.' already on this run';
        }

        // Report each skip reason once. A creator that is both restricted and
        // blocklisted counts only under "do not contact" so the parts never
        // double-count a single creator.
        $restrictedOnlyCount = count(array_diff($restrictedIds, $blocklistedIds));

        if ($restrictedOnlyCount > 0) {
            $parts[] = $restrictedOnlyCount.' skipped (brand restrictions)';
        }

        if ($blocklistedIds !== []) {
            $parts[] = count($blocklistedIds).' skipped (marked do not contact)';
        }

        $summary = $parts === [] ? 'nothing to copy' : implode(', ', $parts);

        $this->dispatch('notify', type: 'success', message: 'Copied the campaign roster: '.$summary.'.');
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

    public function render(BrandRestrictionGuard $guard): View
    {
        return view('livewire.crm.seeding-creators', array_merge(
            $this->pickerViewData($guard),
            ['parentRosterCount' => $this->seedingCampaign->campaign?->creators()->count() ?? 0],
        ));
    }
}
