<?php

namespace App\Modules\CRM\Livewire\Seeding;

use App\Modules\CRM\Models\SeedingCampaign;
use App\Shared\Audit\AuditLogger;
use App\Shared\Enums\SeedingCampaignStatus;
use App\Shared\Enums\ShipmentStatus;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

/**
 * CRM UX Stage D (item 6b): the seeding-run twin of CampaignStatusActions.
 * The seeding detail page is a route closure, so this small component owns
 * the one write affordance the Overview needs.
 *
 * The single nudge: once every recorded shipment has been delivered, offer to
 * close the run. It is a nudge, never an automation — a person clicks and
 * confirms. The action re-authorizes crm.manage and re-checks that all
 * shipments are still delivered before it writes, so a stale suggestion is a
 * silent no-op.
 */
class SeedingStatusActions extends Component
{
    use AuthorizesRequests;

    public SeedingCampaign $seedingCampaign;

    public function mount(SeedingCampaign $seedingCampaign): void
    {
        $this->authorize('view', $seedingCampaign);
        $this->seedingCampaign = $seedingCampaign;
    }

    /**
     * Offer to close the run only when it is not already closed and every one
     * of its recorded shipments has been delivered. A run with no shipments,
     * or with any shipment still in flight, gets no nudge.
     *
     * @return array{label: string, cta: string, next: SeedingCampaignStatus}|null
     */
    public function suggestion(): ?array
    {
        if ($this->seedingCampaign->status === SeedingCampaignStatus::Completed) {
            return null;
        }

        $total = $this->seedingCampaign->shipments()->count();

        if ($total === 0) {
            return null;
        }

        $delivered = $this->seedingCampaign->shipments()
            ->where('status', ShipmentStatus::Delivered)
            ->count();

        if ($delivered !== $total) {
            return null;
        }

        return [
            'label' => 'Every shipment has been delivered — mark this seeding run as Completed?',
            'cta' => 'Mark as Completed',
            'next' => SeedingCampaignStatus::Completed,
        ];
    }

    public function applyStatus(AuditLogger $audit): void
    {
        $this->authorize('update', $this->seedingCampaign);

        $suggestion = $this->suggestion();

        if ($suggestion === null) {
            return;
        }

        $previous = $this->seedingCampaign->status;
        $this->seedingCampaign->status = $suggestion['next'];
        $this->seedingCampaign->save();

        $audit->record('seeding_campaign.status_changed', $this->seedingCampaign, [
            'from' => $previous->value,
            'to' => $suggestion['next']->value,
        ]);

        $this->seedingCampaign->refresh();

        $this->dispatch('notify', type: 'success', message: 'Seeding run status updated.');
    }

    public function render(): View
    {
        return view('livewire.crm.seeding-status-actions', [
            'suggestion' => $this->suggestion(),
        ]);
    }
}
