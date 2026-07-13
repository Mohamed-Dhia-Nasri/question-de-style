<?php

namespace App\Modules\CRM\Livewire\Tasks\Concerns;

use App\Modules\CRM\Models\Task;
use App\Shared\Enums\TaskStatus;
use Carbon\CarbonImmutable;

/**
 * Presentation helpers shared by the tasks surfaces. ENUM-TaskStatus has
 * no label() yet (the enum lives in Shared and is outside this step's
 * ownership) — same convention as the sibling enums' label() methods.
 */
trait PresentsTaskStatus
{
    /**
     * Statuses a deadline still matters for (AC-M3-017): DONE/CANCELLED
     * tasks neither remind nor count as overdue. Mirrors the eligibility
     * set of qds:send-task-reminders (spec D9).
     *
     * @return list<TaskStatus>
     */
    public static function openStatuses(): array
    {
        return [TaskStatus::Open, TaskStatus::InProgress, TaskStatus::Blocked];
    }

    /** Due-soon look-ahead — the reminder command's window (spec D9). */
    public static function reminderWindowHours(): int
    {
        return max(1, (int) config('qds.tasks.reminder_window_hours', 48));
    }

    /** Row affordance: 'overdue' | 'due-soon' | null (D9 in-app channel). */
    public function dueState(Task $task): ?string
    {
        if ($task->due_at === null || ! in_array($task->status, self::openStatuses(), true)) {
            return null;
        }

        if ($task->due_at->isPast()) {
            return 'overdue';
        }

        return $task->due_at->lessThanOrEqualTo(CarbonImmutable::now()->addHours(self::reminderWindowHours()))
            ? 'due-soon'
            : null;
    }

    /** Human-facing label (presentation only — ShipmentStatus convention). */
    public function statusLabel(TaskStatus $status): string
    {
        return match ($status) {
            TaskStatus::Open => 'Open',
            TaskStatus::InProgress => 'In progress',
            TaskStatus::Blocked => 'Blocked',
            TaskStatus::Done => 'Done',
            TaskStatus::Cancelled => 'Cancelled',
        };
    }

    /** Badge color per status (presentation only). */
    public function statusColor(TaskStatus $status): string
    {
        return match ($status) {
            TaskStatus::Open => 'primary',
            TaskStatus::InProgress => 'warning',
            TaskStatus::Blocked => 'error',
            TaskStatus::Done => 'success',
            TaskStatus::Cancelled => 'light',
        };
    }
}
