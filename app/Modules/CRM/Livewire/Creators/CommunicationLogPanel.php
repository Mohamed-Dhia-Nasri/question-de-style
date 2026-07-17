<?php

namespace App\Modules\CRM\Livewire\Creators;

use App\Modules\CRM\Models\Campaign;
use App\Modules\CRM\Models\CommunicationLog;
use App\Modules\CRM\Models\Creator;
use App\Modules\CRM\Models\SeedingCampaign;
use App\Shared\Audit\AuditLogger;
use App\Shared\Enums\RelationshipStatus;
use App\Shared\Tenancy\TenantRule;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Component;

/**
 * Communication log panel — append/list against ENT-CommunicationLog
 * (REQ-M3-004, AC-M3-008): channel / direction / summary / occurredAt.
 * Append-mostly: entries can be edited (canon does not restrict operator-
 * authored logs — this is NOT a review_actions-style immutable trail), but
 * there is no delete action.
 *
 * `channel` is a canonical free string ("Email / DM / call / etc."); the
 * canonical shape names `direction` values inbound / outbound (data-model
 * note — an app-layer closed set, no glossary enum exists; same flagged
 * class as Step 1's app-layer choices).
 */
class CommunicationLogPanel extends Component
{
    public Creator $creator;

    // --- create/edit form state ---
    public bool $showForm = false;

    public ?int $editingLogId = null;

    public string $log_channel = '';

    public string $log_direction = '';

    public string $log_summary = '';

    /** datetime-local input value. */
    public string $log_occurred_at = '';

    public string $log_campaign_id = '';

    public string $log_seeding_campaign_id = '';

    /**
     * SOFT one-tap nudge (§2.5): raised after an outbound log for a creator
     * with no relationship stage yet. Never advances the stage on its own —
     * the operator taps markContacted or dismisses it.
     */
    public bool $suggestContacted = false;

    public function mount(Creator $creator): void
    {
        $this->authorize('view', $creator);

        $this->creator = $creator;
    }

    public function add(): void
    {
        $this->authorize('create', CommunicationLog::class);

        $this->resetForm();
        $this->showForm = true;
    }

    public function edit(int $logId): void
    {
        $log = $this->creator->communicationLogs()->findOrFail($logId);

        $this->authorize('update', $log);

        $this->resetForm();
        $this->editingLogId = $log->id;
        $this->log_channel = $log->channel;
        $this->log_direction = $log->direction;
        $this->log_summary = $log->summary;
        $this->log_occurred_at = $log->occurred_at->format('Y-m-d\TH:i');
        $this->log_campaign_id = $log->campaign_id !== null ? (string) $log->campaign_id : '';
        $this->log_seeding_campaign_id = $log->seeding_campaign_id !== null ? (string) $log->seeding_campaign_id : '';
        $this->showForm = true;
    }

    /** @return array<string, string> */
    protected function validationAttributes(): array
    {
        return [
            'log_channel' => 'channel',
            'log_direction' => 'direction',
            'log_summary' => 'summary',
            'log_occurred_at' => 'date and time',
            'log_campaign_id' => 'campaign',
            'log_seeding_campaign_id' => 'seeding run',
        ];
    }

    public function save(): void
    {
        $editing = $this->editingLogId !== null;
        $log = $editing ? $this->creator->communicationLogs()->findOrFail($this->editingLogId) : null;

        $this->authorize($editing ? 'update' : 'create', $log ?? CommunicationLog::class);

        $validated = $this->validate([
            'log_channel' => ['required', 'string', 'max:255'],
            'log_direction' => ['required', Rule::in(['inbound', 'outbound'])],
            'log_summary' => ['required', 'string', 'max:5000'],
            'log_occurred_at' => ['required', 'date'],
            'log_campaign_id' => ['nullable', 'integer', TenantRule::exists('campaigns', 'id')],
            'log_seeding_campaign_id' => ['nullable', 'integer', TenantRule::exists('seeding_campaigns', 'id')],
        ]);

        $attributes = [
            'channel' => $validated['log_channel'],
            'direction' => $validated['log_direction'],
            'summary' => $validated['log_summary'],
            'occurred_at' => $validated['log_occurred_at'],
            'campaign_id' => ($validated['log_campaign_id'] ?? '') !== '' ? (int) $validated['log_campaign_id'] : null,
            'seeding_campaign_id' => ($validated['log_seeding_campaign_id'] ?? '') !== '' ? (int) $validated['log_seeding_campaign_id'] : null,
        ];

        if ($editing) {
            $log->update($attributes);
        } else {
            $this->creator->communicationLogs()->create($attributes);
        }

        $this->creator->refresh();

        // Reaching out to a creator with no relationship stage yet is the
        // moment they become "Contacted". Offer the one-tap nudge; the
        // operator decides. Recomputed on every save so a non-qualifying
        // save clears a stale suggestion.
        $this->suggestContacted = $validated['log_direction'] === 'outbound'
            && in_array(
                $this->creator->relationship_status,
                [RelationshipStatus::None, RelationshipStatus::Prospect, null],
                true,
            );

        $this->showForm = false;
        $this->resetForm();
        $this->dispatch('notify', type: 'success', message: $editing ? 'Log entry updated.' : 'Log entry added.');
    }

    /**
     * Accept the nudge: advance the creator to Contacted. Authorizes and
     * audits like every other stage write (the status-change shape mirrors
     * campaign.status_changed).
     */
    public function markContacted(AuditLogger $audit): void
    {
        $this->authorize('update', $this->creator);

        // Re-check the trigger against fresh state: the creator may have
        // advanced past first contact (e.g. to Active) between the outbound
        // log and this tap. This is a forward-only first-contact promotion —
        // it must never regress a later stage, so refuse silently unless the
        // nudge still holds and the creator is still pre-contact.
        $this->creator->refresh();

        if (! $this->suggestContacted || ! in_array(
            $this->creator->relationship_status,
            [RelationshipStatus::None, RelationshipStatus::Prospect, null],
            true,
        )) {
            $this->suggestContacted = false;

            return;
        }

        $previous = $this->creator->relationship_status;
        $this->creator->relationship_status = RelationshipStatus::Contacted;
        $this->creator->save();

        $audit->record('creator.relationship_changed', $this->creator, [
            'from' => $previous?->value,
            'to' => RelationshipStatus::Contacted->value,
        ]);

        $this->creator->refresh();
        $this->suggestContacted = false;
        $this->dispatch('notify', type: 'success', message: 'Relationship set to Contacted.');
    }

    public function dismissContacted(): void
    {
        $this->suggestContacted = false;
    }

    public function cancelForm(): void
    {
        $this->showForm = false;
        $this->resetForm();
    }

    // -------------------------------------------------------------------------

    protected function resetForm(): void
    {
        $this->resetValidation();
        $this->editingLogId = null;
        $this->log_channel = '';
        $this->log_direction = '';
        $this->log_summary = '';
        $this->log_occurred_at = '';
        $this->log_campaign_id = '';
        $this->log_seeding_campaign_id = '';
    }

    public function render(): View
    {
        return view('livewire.crm.creator-communication-log', [
            'logs' => $this->creator->communicationLogs()->orderByDesc('occurred_at')->get(),
            'campaigns' => Campaign::query()->orderBy('name')->get(),
            'seedingRuns' => SeedingCampaign::query()->orderBy('name')->get(),
        ]);
    }
}
