<?php

namespace Tests\Feature\Crm;

use App\Models\User;
use App\Modules\CRM\Livewire\Tasks\TasksIndex;
use App\Modules\CRM\Livewire\Tasks\TasksPanel;
use App\Modules\CRM\Models\Creator;
use App\Modules\CRM\Models\Task;
use App\Shared\Audit\AuditLog;
use App\Shared\Enums\RoleName;
use App\Shared\Enums\TaskStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * qds:send-task-reminders (REQ-M3-011, AC-M3-017; spec D8/D9): a task
 * nearing its deadline fires a reminder EXACTLY once — the
 * reminder_sent_at stamp is the idempotency marker, task.reminder_fired
 * the audited event (assignee + due date in context). Eligibility: dated,
 * due within qds.tasks.reminder_window_hours (overdue included), status
 * still awaiting work (OPEN/IN_PROGRESS/BLOCKED), not yet stamped.
 */
class TaskRemindersCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_reminders_fire_exactly_once_and_carry_the_audit_shape(): void
    {
        config(['qds.tasks.reminder_window_hours' => 48]);
        $assignee = User::factory()->create();

        $dueSoon = Task::factory()->create([
            'status' => TaskStatus::Open,
            'assignee_user_id' => $assignee->id,
            'due_at' => now()->addDay(),
        ]);
        $overdue = Task::factory()->create([
            'status' => TaskStatus::InProgress,
            'due_at' => now()->subHours(2),
        ]);
        $blocked = Task::factory()->create([
            'status' => TaskStatus::Blocked,
            'due_at' => now()->addHours(47),
        ]);

        $this->artisan('qds:send-task-reminders')->assertExitCode(0);

        foreach ([$dueSoon, $overdue, $blocked] as $task) {
            $this->assertNotNull($task->refresh()->reminder_sent_at);
        }

        $log = AuditLog::query()
            ->where('action', 'task.reminder_fired')
            ->where('subject_id', $dueSoon->id)
            ->firstOrFail();

        $this->assertSame($assignee->id, $log->context['assignee_user_id']);
        $this->assertSame($dueSoon->refresh()->due_at->toIso8601String(), $log->context['due_at']);

        // Idempotent (D8): a second run re-fires nothing and moves no stamp.
        $stampedAt = $dueSoon->reminder_sent_at;
        $this->travel(30)->minutes();

        $this->artisan('qds:send-task-reminders')->assertExitCode(0);

        $this->assertSame(3, AuditLog::query()->where('action', 'task.reminder_fired')->count());
        $this->assertTrue($dueSoon->refresh()->reminder_sent_at->equalTo($stampedAt));
    }

    public function test_the_window_the_status_set_and_the_date_gate_eligibility(): void
    {
        config(['qds.tasks.reminder_window_hours' => 48]);

        $beyondWindow = Task::factory()->create([
            'status' => TaskStatus::Open,
            'due_at' => now()->addHours(72),
        ]);
        $done = Task::factory()->create([
            'status' => TaskStatus::Done,
            'due_at' => now()->subHour(),
        ]);
        $cancelled = Task::factory()->create([
            'status' => TaskStatus::Cancelled,
            'due_at' => now()->addHour(),
        ]);
        $undated = Task::factory()->create([
            'status' => TaskStatus::Open,
            'due_at' => null,
        ]);

        $this->artisan('qds:send-task-reminders')->assertExitCode(0);

        foreach ([$beyondWindow, $done, $cancelled, $undated] as $task) {
            $this->assertNull($task->refresh()->reminder_sent_at);
        }

        $this->assertDatabaseMissing('audit_logs', ['action' => 'task.reminder_fired']);
    }

    public function test_the_fired_reminder_is_visible_on_the_task_surfaces(): void
    {
        // GAP-3 pin: the in-app channel is only real if the fired state is
        // user-visible — the index shows the fired timestamp, the compact
        // panel a "Reminded" badge.
        $task = Task::factory()->create([
            'status' => TaskStatus::Open,
            'due_at' => now()->addHours(2),
        ]);

        $this->artisan('qds:send-task-reminders')->assertExitCode(0);
        $this->assertNotNull($task->refresh()->reminder_sent_at);

        $this->seedRoles();
        $this->actingAs($this->makeUser(RoleName::InfluencerRelationsManager));

        Livewire::test(TasksIndex::class)
            ->assertSee('Reminder fired');

        $creator = Creator::factory()->create();
        $task->update(['creator_id' => $creator->id]);

        Livewire::test(TasksPanel::class, ['creator' => $creator])
            ->assertSee('Reminded');
    }

    public function test_the_look_ahead_window_is_configurable(): void
    {
        config(['qds.tasks.reminder_window_hours' => 1]);

        $insideNarrowWindow = Task::factory()->create([
            'status' => TaskStatus::Open,
            'due_at' => now()->addMinutes(30),
        ]);
        $outsideNarrowWindow = Task::factory()->create([
            'status' => TaskStatus::Open,
            'due_at' => now()->addHours(24),
        ]);

        $this->artisan('qds:send-task-reminders')->assertExitCode(0);

        $this->assertNotNull($insideNarrowWindow->refresh()->reminder_sent_at);
        $this->assertNull($outsideNarrowWindow->refresh()->reminder_sent_at);
    }
}
