<?php

namespace App\Modules\CRM\Console;

use App\Modules\CRM\Models\Task;
use App\Shared\Audit\AuditLogger;
use App\Shared\Enums\TaskStatus;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * REQ-M3-011 / AC-M3-017 — deadline reminders, fired exactly once per
 * task (spec D8/D9): the reminder_sent_at stamp is the idempotency
 * marker, task.reminder_fired the audited event, and the tasks UI's
 * Overdue/Due-soon affordances the in-app channel (no email/push exists
 * in the frozen stack — flagged: the channel is canon-silent).
 *
 * No config gate (unlike the pipeline commands): the command is
 * self-limiting — it only writes when something is due and unstamped.
 */
class SendTaskRemindersCommand extends Command
{
    protected $signature = 'qds:send-task-reminders';

    protected $description = 'Fire the one-time deadline reminder for tasks nearing their due date (AC-M3-017)';

    /**
     * Statuses still awaiting work — DONE/CANCELLED never remind. Mirrors
     * PresentsTaskStatus::openStatuses() (the UI half of the D9 channel);
     * the shared home would be ENT-Task itself, which this step does not
     * own.
     */
    private const REMINDABLE_STATUSES = [TaskStatus::Open, TaskStatus::InProgress, TaskStatus::Blocked];

    public function handle(AuditLogger $audit, TenantContext $context): int
    {
        $window = max(1, (int) config('qds.tasks.reminder_window_hours', 48));

        // Platform sweep: this scheduled command spans tenants (it fires
        // every tenant's due reminders), so the fetch runs tenant-less.
        $due = Task::query()
            ->whereNotNull('due_at')
            ->where('due_at', '<=', now()->addHours($window))
            ->whereIn('status', self::REMINDABLE_STATUSES)
            ->whereNull('reminder_sent_at')
            ->get();

        foreach ($due as $task) {
            // Stamp + audit are one atomic firing (deep-review GAP-3): a
            // stamp that commits while the audit write fails would mark the
            // task reminded with no trace the reminder ever fired. The
            // stamp is the idempotency marker (D8) — a later overlapping
            // run skips the task either way. Run under the task's own tenant
            // (ADR-0019) so the audit row is attributed to the owning tenant,
            // never NULL/platform.
            $context->runAs((int) $task->tenant_id, function () use ($task, $audit): void {
                DB::transaction(function () use ($task, $audit): void {
                    $task->update(['reminder_sent_at' => now()]);

                    $audit->record('task.reminder_fired', $task, [
                        'assignee_user_id' => $task->assignee_user_id,
                        'due_at' => $task->due_at->toIso8601String(),
                    ]);
                });
            });
        }

        $this->info(sprintf(
            'Fired %d task reminder(s) (window: next %d hour(s), incl. overdue).',
            $due->count(),
            $window,
        ));

        return self::SUCCESS;
    }
}
