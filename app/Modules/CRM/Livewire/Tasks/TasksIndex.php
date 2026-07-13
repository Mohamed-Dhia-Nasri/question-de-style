<?php

namespace App\Modules\CRM\Livewire\Tasks;

use App\Models\User;
use App\Modules\CRM\Livewire\Tasks\Concerns\PresentsTaskStatus;
use App\Modules\CRM\Models\Campaign;
use App\Modules\CRM\Models\Creator;
use App\Modules\CRM\Models\Task;
use App\Shared\Audit\AuditLogger;
use App\Shared\Enums\TaskStatus;
use App\Shared\Livewire\Concerns\WithDataTable;
use App\Shared\Tenancy\TenantRule;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Tasks index (REQ-M3-011, AC-M3-017) — deadlines and follow-ups.
 * UsersIndex reference CRUD pattern (ADR-0012): searchable/sortable/
 * paginated, modal create/edit, delete confirmation, server-side
 * authorization on every action, audit on sensitive changes (incl.
 * task.status_changed from→to — the campaigns precedent).
 *
 * Overdue / Due-soon sections + badges are the in-app reminder channel
 * (spec D9 — no email/push in the frozen stack): a task whose reminder
 * fired (reminder_sent_at, D8) is exactly the population these
 * affordances make prominent. The due-soon window mirrors the reminder
 * command's qds.tasks.reminder_window_hours.
 */
class TasksIndex extends Component
{
    use PresentsTaskStatus;
    use WithDataTable;

    // --- filters (validated server-side; tampered values fall back) ---
    #[Url(except: '')]
    public string $status = '';

    #[Url(as: 'assignee', except: '')]
    public string $assigneeFilter = '';

    /** '' | 'overdue' | 'due-soon' — the section chips filter. */
    #[Url(except: '')]
    public string $dueWindow = '';

    // --- create/edit form state ---
    public bool $showForm = false;

    public ?int $editingTaskId = null;

    public string $task_title = '';

    public string $task_status = '';

    public string $task_assignee_id = '';

    public string $task_due_at = '';

    public string $task_creator_id = '';

    public string $task_campaign_id = '';

    // --- delete confirmation state ---
    public ?int $confirmingDeleteId = null;

    public function mount(): void
    {
        $this->authorize('viewAny', Task::class);

        if ($this->sortField === '') {
            $this->sortField = 'due_at';
        }
    }

    protected function sortableColumns(): array
    {
        return ['title', 'status', 'due_at', 'created_at'];
    }

    protected function currentPageIds(): array
    {
        return $this->tasksQuery()->paginate($this->perPage())->pluck('id')->all();
    }

    /** @return Builder<Task> */
    protected function tasksQuery(): Builder
    {
        return $this->applySort(
            Task::query()
                ->with(['assignee', 'creator', 'campaign'])
                ->when($this->search !== '', function (Builder $query) {
                    $query->where('title', 'ilike', '%'.$this->search.'%');
                })
                // Closed-set filter: anything not in ENUM-TaskStatus is ignored.
                ->when(TaskStatus::tryFrom($this->status) !== null, function (Builder $query) {
                    $query->where('status', $this->status);
                })
                ->when(ctype_digit($this->assigneeFilter), function (Builder $query) {
                    $query->where('assignee_user_id', (int) $this->assigneeFilter);
                })
                ->when($this->dueWindow === 'overdue', fn (Builder $query) => $this->scopeOverdue($query))
                ->when($this->dueWindow === 'due-soon', fn (Builder $query) => $this->scopeDueSoon($query))
        );
    }

    /** @param Builder<Task> $query
     * @return Builder<Task> */
    private function scopeOverdue(Builder $query): Builder
    {
        return $query
            ->whereIn('status', self::openStatuses())
            ->where('due_at', '<', now());
    }

    /** @param Builder<Task> $query
     * @return Builder<Task> */
    private function scopeDueSoon(Builder $query): Builder
    {
        return $query
            ->whereIn('status', self::openStatuses())
            ->where('due_at', '>=', now())
            ->where('due_at', '<=', now()->addHours(self::reminderWindowHours()));
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
        $this->clearSelection();
    }

    public function updatedAssigneeFilter(): void
    {
        $this->resetPage();
        $this->clearSelection();
    }

    public function setDueWindow(string $window): void
    {
        // Closed set — the chips are the only writers, but the value is
        // URL-backed, so tampered input falls back to "all".
        $this->dueWindow = in_array($window, ['overdue', 'due-soon'], true) && $this->dueWindow !== $window
            ? $window
            : '';
        $this->resetPage();
        $this->clearSelection();
    }

    // --- create / edit -----------------------------------------------------

    public function create(): void
    {
        $this->authorize('create', Task::class);

        $this->resetForm();
        $this->task_status = TaskStatus::Open->value;
        $this->showForm = true;
    }

    public function edit(int $taskId): void
    {
        $task = Task::findOrFail($taskId);

        $this->authorize('update', $task);

        $this->resetForm();
        $this->editingTaskId = $task->id;
        $this->task_title = $task->title;
        $this->task_status = $task->status->value;
        $this->task_assignee_id = $task->assignee_user_id !== null ? (string) $task->assignee_user_id : '';
        $this->task_due_at = $task->due_at?->format('Y-m-d\TH:i') ?? '';
        $this->task_creator_id = $task->creator_id !== null ? (string) $task->creator_id : '';
        $this->task_campaign_id = $task->campaign_id !== null ? (string) $task->campaign_id : '';
        $this->showForm = true;
    }

    public function save(AuditLogger $audit): void
    {
        $editing = $this->editingTaskId !== null;
        $task = $editing ? Task::findOrFail($this->editingTaskId) : null;

        $this->authorize($editing ? 'update' : 'create', $task ?? Task::class);

        $validated = $this->validate([
            'task_title' => ['required', 'string', 'max:255'],
            // ENUM-TaskStatus — closed set (glossary; the DB CHECK backs it).
            'task_status' => ['required', Rule::in(array_column(TaskStatus::cases(), 'value'))],
            'task_assignee_id' => ['nullable', 'integer', TenantRule::exists('users', 'id')],
            'task_due_at' => ['nullable', 'date'],
            'task_creator_id' => ['nullable', 'integer', TenantRule::exists('creators', 'id')],
            'task_campaign_id' => ['nullable', 'integer', TenantRule::exists('campaigns', 'id')],
        ]);

        $previousStatus = $task?->status;

        $attributes = [
            'title' => $validated['task_title'],
            'status' => TaskStatus::from($validated['task_status']),
            'assignee_user_id' => ($validated['task_assignee_id'] ?? '') !== '' ? (int) $validated['task_assignee_id'] : null,
            'due_at' => ($validated['task_due_at'] ?? '') !== '' ? $validated['task_due_at'] : null,
            'creator_id' => ($validated['task_creator_id'] ?? '') !== '' ? (int) $validated['task_creator_id'] : null,
            'campaign_id' => ($validated['task_campaign_id'] ?? '') !== '' ? (int) $validated['task_campaign_id'] : null,
        ];

        if ($editing) {
            $task->update($attributes);
        } else {
            $task = Task::create($attributes);
        }

        $audit->record($editing ? 'task.updated' : 'task.created', $task, [
            'title' => $task->title,
            'assignee_user_id' => $task->assignee_user_id,
        ]);

        // AC-M3-017 discipline: status transitions are recorded from→to.
        if ($editing && $previousStatus !== $task->status) {
            $audit->record('task.status_changed', $task, [
                'from' => $previousStatus->value,
                'to' => $task->status->value,
            ]);
        }

        $this->showForm = false;
        $this->resetForm();
        $this->dispatch('notify', type: 'success', message: $editing ? 'Task updated.' : 'Task created.');
    }

    public function cancelForm(): void
    {
        $this->showForm = false;
        $this->resetForm();
    }

    // --- delete ------------------------------------------------------------

    public function confirmDelete(int $taskId): void
    {
        $this->authorize('delete', Task::findOrFail($taskId));

        $this->confirmingDeleteId = $taskId;
    }

    public function delete(AuditLogger $audit): void
    {
        if ($this->confirmingDeleteId === null) {
            return;
        }

        $task = Task::findOrFail($this->confirmingDeleteId);

        $this->authorize('delete', $task);

        $audit->record('task.deleted', $task, ['title' => $task->title]);

        $task->delete();

        $this->confirmingDeleteId = null;
        $this->clearSelection();
        $this->clampPage();
        $this->dispatch('notify', type: 'success', message: 'Task deleted.');
    }

    public function cancelDelete(): void
    {
        $this->confirmingDeleteId = null;
    }

    /** After deletes/filter-affecting mutations, leave no out-of-range page. */
    protected function clampPage(): void
    {
        if ($this->getPage() > 1 && $this->tasksQuery()->paginate($this->perPage())->isEmpty()) {
            $this->resetPage();
        }
    }

    // -------------------------------------------------------------------------

    protected function resetForm(): void
    {
        $this->resetValidation();
        $this->editingTaskId = null;
        $this->task_title = '';
        $this->task_status = '';
        $this->task_assignee_id = '';
        $this->task_due_at = '';
        $this->task_creator_id = '';
        $this->task_campaign_id = '';
    }

    public function render(): View
    {
        return view('livewire.crm.tasks-index', [
            'tasks' => $this->tasksQuery()->paginate($this->perPage()),
            'overdueCount' => $this->scopeOverdue(Task::query())->count(),
            'dueSoonCount' => $this->scopeDueSoon(Task::query())->count(),
            'statuses' => TaskStatus::cases(),
            'users' => User::query()->orderBy('display_name')->get(),
            'creators' => Creator::query()->orderBy('display_name')->get(),
            'campaigns' => Campaign::query()->orderBy('name')->get(),
        ]);
    }
}
