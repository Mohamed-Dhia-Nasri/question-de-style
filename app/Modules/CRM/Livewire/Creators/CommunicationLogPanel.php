<?php

namespace App\Modules\CRM\Livewire\Creators;

use App\Modules\CRM\Models\Campaign;
use App\Modules\CRM\Models\CommunicationLog;
use App\Modules\CRM\Models\Creator;
use App\Modules\CRM\Models\SeedingCampaign;
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
        $this->showForm = false;
        $this->resetForm();
        $this->dispatch('notify', type: 'success', message: $editing ? 'Log entry updated.' : 'Log entry added.');
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
