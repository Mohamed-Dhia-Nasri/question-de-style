<?php

namespace App\Modules\CRM\Livewire\Tasks;

use App\Modules\CRM\Livewire\Tasks\Concerns\PresentsTaskStatus;
use App\Modules\CRM\Models\Campaign;
use App\Modules\CRM\Models\Creator;
use App\Modules\CRM\Models\SeedingCampaign;
use App\Modules\CRM\Models\Task;
use App\Shared\Audit\AuditLogger;
use App\Shared\Enums\TaskStatus;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Livewire\Component;

/**
 * Compact tasks panel (REQ-M3-011, AC-M3-017) — the anchored task list on
 * the creator profile, campaign detail, and seeding run detail (same
 * reusable-parent pattern as the documents panel): list + quick add +
 * status flip. The full CRUD surface (assignee, links, delete) lives on
 * /crm/tasks (TasksIndex).
 *
 * Overdue / Due-soon badges are the in-app reminder channel (spec D9);
 * status flips stay inside ENUM-TaskStatus and are audited from→to
 * (task.status_changed — the campaigns precedent).
 */
class TasksPanel extends Component
{
    use PresentsTaskStatus;

    public ?Creator $creator = null;

    public ?Campaign $campaign = null;

    public ?SeedingCampaign $seedingCampaign = null;

    // --- quick-add form state ---
    public string $quick_title = '';

    public string $quick_due_at = '';

    /**
     * The parent arrives as a Livewire prop (assigned to the matching public
     * property before mount) — NOT as a mount() parameter: container method
     * injection would materialize empty models for the absent nullable
     * parents and break the exactly-one check.
     */
    public function mount(): void
    {
        $parents = array_filter([$this->creator, $this->campaign, $this->seedingCampaign]);

        if (count($parents) !== 1) {
            throw new InvalidArgumentException(
                'TasksPanel must be mounted with exactly one parent (creator, campaign, or seedingCampaign).',
            );
        }

        $this->authorize('view', array_values($parents)[0]);
    }

    // --- quick add -----------------------------------------------------------

    /** @return array<string, string> */
    protected function validationAttributes(): array
    {
        return [
            'quick_title' => 'title',
            'quick_due_at' => 'due date',
        ];
    }

    public function quickAdd(AuditLogger $audit): void
    {
        $this->authorize('create', Task::class);

        $validated = $this->validate([
            'quick_title' => ['required', 'string', 'max:255'],
            'quick_due_at' => ['nullable', 'date'],
        ]);

        $task = Task::create([
            'title' => $validated['quick_title'],
            'status' => TaskStatus::Open,
            'due_at' => ($validated['quick_due_at'] ?? '') !== '' ? $validated['quick_due_at'] : null,
            'creator_id' => $this->creator?->id,
            'campaign_id' => $this->campaign?->id,
            'seeding_campaign_id' => $this->seedingCampaign?->id,
        ]);

        $audit->record('task.created', $task, [
            'title' => $task->title,
            'assignee_user_id' => $task->assignee_user_id,
        ]);

        $this->quick_title = '';
        $this->quick_due_at = '';
        $this->resetValidation();
        $this->dispatch('notify', type: 'success', message: 'Task created.');
    }

    // --- status flip -----------------------------------------------------------

    public function setStatus(AuditLogger $audit, int $taskId, string $status): void
    {
        $task = $this->tasksQuery()->findOrFail($taskId);

        $this->authorize('update', $task);

        // ENUM-TaskStatus — closed set; anything else is refused.
        $to = TaskStatus::tryFrom($status);

        if ($to === null) {
            throw ValidationException::withMessages([
                'status' => 'Pick a valid task status.',
            ]);
        }

        if ($to === $task->status) {
            return;
        }

        $from = $task->status;

        $task->update(['status' => $to]);

        // AC-M3-017 discipline: status transitions are recorded from→to.
        $audit->record('task.status_changed', $task, [
            'from' => $from->value,
            'to' => $to->value,
        ]);

        $this->dispatch('notify', type: 'success', message: 'Task status updated.');
    }

    // -------------------------------------------------------------------------

    /** @return Builder<Task> */
    private function tasksQuery(): Builder
    {
        return Task::query()
            ->when($this->creator !== null, fn (Builder $query) => $query->where('creator_id', $this->creator->id))
            ->when($this->campaign !== null, fn (Builder $query) => $query->where('campaign_id', $this->campaign->id))
            ->when($this->seedingCampaign !== null, fn (Builder $query) => $query->where('seeding_campaign_id', $this->seedingCampaign->id));
    }

    public function render(): View
    {
        return view('livewire.crm.tasks-panel', [
            // Deadline-first: dated tasks by urgency, undated last (PG
            // sorts NULLs last on ASC).
            'tasks' => $this->tasksQuery()->with('assignee')->orderBy('due_at')->orderBy('id')->get(),
            'statuses' => TaskStatus::cases(),
        ]);
    }
}
