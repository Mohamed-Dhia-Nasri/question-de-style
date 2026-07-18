<?php

namespace App\Modules\CRM\Livewire\Campaigns;

use App\Modules\CRM\Models\Campaign;
use App\Shared\Audit\AuditLogger;
use App\Shared\Enums\CampaignStatus;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

/**
 * CRM UX Stage D (item 6b): a gentle one-click next-step prompt on the
 * campaign Overview tab. The campaign detail page is a route closure with no
 * page-owning Livewire component, so this small component carries the one
 * write affordance the Overview needs.
 *
 * At most one suggestion shows at a time (computed in priority order). It is
 * a nudge, never an automation — there is no scheduler and nothing
 * auto-transitions; a person clicks the button and confirms. The action
 * re-authorizes crm.manage and re-checks the trigger still holds before it
 * writes, so a suggestion that went stale between render and click is a
 * silent no-op instead of a surprise transition.
 */
class CampaignStatusActions extends Component
{
    use AuthorizesRequests;

    public Campaign $campaign;

    public function mount(Campaign $campaign): void
    {
        $this->authorize('view', $campaign);
        $this->campaign = $campaign;
    }

    /**
     * The single next-step nudge, or null when none applies. Priority order:
     * roster set → Planned, start date reached → Active, end date passed →
     * Completed. Every date branch guards its nullable date first.
     *
     * @return array{label: string, cta: string, next: CampaignStatus}|null
     */
    public function suggestion(): ?array
    {
        $status = $this->campaign->status;

        if ($status === CampaignStatus::Draft && $this->campaign->creators()->count() > 0) {
            return [
                'label' => 'The roster is set — mark this campaign as Planned?',
                'cta' => 'Mark as Planned',
                'next' => CampaignStatus::Planned,
            ];
        }

        if ($status === CampaignStatus::Planned
            && $this->campaign->start_at !== null
            && $this->campaign->start_at <= now()) {
            return [
                'label' => 'The start date has arrived — start the campaign?',
                'cta' => 'Start the campaign',
                'next' => CampaignStatus::Active,
            ];
        }

        if ($status === CampaignStatus::Active
            && $this->campaign->end_at !== null
            && $this->campaign->end_at < now()) {
            return [
                'label' => 'The end date has passed — mark the campaign as Completed?',
                'cta' => 'Mark as Completed',
                'next' => CampaignStatus::Completed,
            ];
        }

        return null;
    }

    public function applyStatus(AuditLogger $audit): void
    {
        $this->authorize('update', $this->campaign);

        // Re-check the trigger still holds. A suggestion that went stale
        // between render and click (roster emptied, status moved elsewhere)
        // must not force a transition — no-op instead.
        $suggestion = $this->suggestion();

        if ($suggestion === null) {
            return;
        }

        $previous = $this->campaign->status;
        $this->campaign->status = $suggestion['next'];
        $this->campaign->save();

        $audit->record('campaign.status_changed', $this->campaign, [
            'from' => $previous->value,
            'to' => $suggestion['next']->value,
        ]);

        $this->campaign->refresh();

        $this->dispatch('notify', type: 'success', message: 'Campaign status updated.');
    }

    public function render(): View
    {
        return view('livewire.crm.campaign-status-actions', [
            'suggestion' => $this->suggestion(),
        ]);
    }
}
