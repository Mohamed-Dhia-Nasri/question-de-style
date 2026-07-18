<?php

namespace Tests\Feature\Crm;

use App\Models\User;
use App\Modules\CRM\Livewire\Tasks\TasksIndex;
use App\Modules\CRM\Models\Campaign;
use App\Modules\CRM\Models\Creator;
use App\Modules\CRM\Models\Task;
use App\Shared\Audit\AuditLog;
use App\Shared\Authorization\PermissionsCatalog;
use App\Shared\Enums\RoleName;
use App\Shared\Enums\TaskStatus;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Tasks index (REQ-M3-011, AC-M3-017): CRUD inside the ENUM-TaskStatus
 * closed set with from→to transition audits, validated status/assignee
 * filters, and the Overdue / Due-soon sections that ARE the in-app
 * reminder channel (spec D9). Mutators enforce crm.manage — including the
 * direct-property bypass path.
 */
class TasksIndexTest extends TestCase
{
    use RefreshDatabase;

    public function test_rescheduling_the_deadline_re_arms_the_one_time_reminder(): void
    {
        $this->actingAsCrmStaff();
        $task = Task::factory()->create([
            'status' => TaskStatus::Open,
            'due_at' => CarbonImmutable::parse('2026-08-01 09:00:00'),
            'reminder_sent_at' => now()->subHour(),
        ]);

        Livewire::test(TasksIndex::class)
            ->call('edit', $task->id)
            ->set('task_due_at', '2026-09-15T09:00')
            ->call('save')
            ->assertHasNoErrors();

        // Moving the deadline re-arms the per-deadline reminder stamp (M10).
        $this->assertNull($task->refresh()->reminder_sent_at);
    }

    public function test_editing_a_task_without_moving_the_deadline_keeps_the_reminder_stamp(): void
    {
        $this->actingAsCrmStaff();
        $task = Task::factory()->create([
            'status' => TaskStatus::Open,
            'due_at' => CarbonImmutable::parse('2026-08-01 09:00:00'),
            'reminder_sent_at' => now()->subHour(),
        ]);

        Livewire::test(TasksIndex::class)
            ->call('edit', $task->id)
            ->set('task_title', 'Renamed, same deadline')
            ->call('save')
            ->assertHasNoErrors();

        // A non-deadline edit must not re-fire an already-sent reminder.
        $this->assertNotNull($task->refresh()->reminder_sent_at);
    }

    private function actingAsCrmStaff(): User
    {
        $this->seedRoles();

        $staff = $this->makeUser(RoleName::CampaignManager);
        $this->actingAs($staff);

        return $staff;
    }

    public function test_client_viewers_cannot_reach_the_tasks_surface(): void
    {
        $this->seedRoles();
        $this->actingAs($this->makeUser(RoleName::ClientViewer));

        $this->get('/crm/tasks')->assertForbidden();
        Livewire::test(TasksIndex::class)->assertForbidden();
    }

    public function test_the_tasks_page_renders_for_staff(): void
    {
        $this->actingAsCrmStaff();

        $this->get('/crm/tasks')->assertOk()->assertSeeLivewire('crm.tasks-index');
    }

    public function test_a_task_is_created_with_all_fields_and_audited(): void
    {
        $this->actingAsCrmStaff();
        $assignee = User::factory()->create();
        $creator = Creator::factory()->create();
        $campaign = Campaign::factory()->create();

        Livewire::test(TasksIndex::class)
            ->call('create')
            ->set('task_title', 'Send the contract')
            ->set('task_status', TaskStatus::Open->value)
            ->set('task_assignee_id', (string) $assignee->id)
            ->set('task_due_at', '2026-08-01T09:00')
            ->set('task_creator_id', (string) $creator->id)
            ->set('task_campaign_id', (string) $campaign->id)
            ->call('save')
            ->assertHasNoErrors();

        $task = Task::query()->firstOrFail();
        $this->assertSame('Send the contract', $task->title);
        $this->assertSame(TaskStatus::Open, $task->status);
        $this->assertSame($assignee->id, $task->assignee_user_id);
        $this->assertSame($creator->id, $task->creator_id);
        $this->assertSame($campaign->id, $task->campaign_id);
        $this->assertTrue($task->due_at->equalTo('2026-08-01 09:00:00'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'task.created', 'subject_id' => $task->id]);
    }

    public function test_a_status_outside_the_closed_set_is_refused(): void
    {
        $this->actingAsCrmStaff();

        Livewire::test(TasksIndex::class)
            ->call('create')
            ->set('task_title', 'Send the contract')
            ->set('task_status', 'ARCHIVED')
            ->call('save')
            ->assertHasErrors(['task_status']);

        $this->assertDatabaseCount('tasks', 0);
    }

    public function test_status_transitions_are_audited_from_to(): void
    {
        $this->actingAsCrmStaff();
        $task = Task::factory()->create(['status' => TaskStatus::Open]);

        Livewire::test(TasksIndex::class)
            ->call('edit', $task->id)
            ->set('task_status', TaskStatus::Done->value)
            ->call('save')
            ->assertHasNoErrors();

        $log = AuditLog::query()
            ->where('action', 'task.status_changed')
            ->where('subject_id', $task->id)
            ->firstOrFail();

        $this->assertSame('OPEN', $log->context['from']);
        $this->assertSame('DONE', $log->context['to']);
    }

    public function test_an_edit_without_a_status_change_records_no_transition(): void
    {
        $this->actingAsCrmStaff();
        $task = Task::factory()->create(['status' => TaskStatus::Open]);

        Livewire::test(TasksIndex::class)
            ->call('edit', $task->id)
            ->set('task_title', 'Renamed follow-up')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('audit_logs', ['action' => 'task.updated', 'subject_id' => $task->id]);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'task.status_changed']);
    }

    public function test_deleting_a_task_is_audited(): void
    {
        $this->actingAsCrmStaff();
        $task = Task::factory()->create(['title' => 'Obsolete follow-up']);

        Livewire::test(TasksIndex::class)
            ->call('confirmDelete', $task->id)
            ->call('delete');

        $this->assertDatabaseMissing('tasks', ['id' => $task->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'task.deleted', 'subject_id' => $task->id]);
    }

    public function test_search_status_and_assignee_filters_narrow_the_list(): void
    {
        $this->actingAsCrmStaff();
        $assignee = User::factory()->create();

        Task::factory()->create([
            'title' => 'Chase the invoice',
            'status' => TaskStatus::InProgress,
            'assignee_user_id' => $assignee->id,
        ]);
        Task::factory()->create(['title' => 'Ship the samples', 'status' => TaskStatus::Open]);

        Livewire::test(TasksIndex::class)
            ->set('search', 'invoice')
            ->assertSee('Chase the invoice')
            ->assertDontSee('Ship the samples');

        Livewire::test(TasksIndex::class)
            ->set('status', TaskStatus::InProgress->value)
            ->assertSee('Chase the invoice')
            ->assertDontSee('Ship the samples');

        Livewire::test(TasksIndex::class)
            ->set('assigneeFilter', (string) $assignee->id)
            ->assertSee('Chase the invoice')
            ->assertDontSee('Ship the samples');

        // Tampered filter values fall back to "all" instead of reaching SQL.
        Livewire::test(TasksIndex::class)
            ->set('status', 'NOT_A_STATUS')
            ->assertSee('Chase the invoice')
            ->assertSee('Ship the samples');
    }

    public function test_overdue_and_due_soon_sections_flag_the_right_tasks(): void
    {
        $this->actingAsCrmStaff();
        config(['qds.tasks.reminder_window_hours' => 48]);

        Task::factory()->create([
            'title' => 'Overdue follow-up',
            'status' => TaskStatus::Open,
            'due_at' => now()->subDay(),
        ]);
        Task::factory()->create([
            'title' => 'Imminent follow-up',
            'status' => TaskStatus::Blocked,
            'due_at' => now()->addDay(),
        ]);
        // A DONE task past its date is neither overdue nor due-soon.
        Task::factory()->create([
            'title' => 'Finished follow-up',
            'status' => TaskStatus::Done,
            'due_at' => now()->subDay(),
        ]);
        // Dated beyond the window: not due-soon yet.
        Task::factory()->create([
            'title' => 'Distant follow-up',
            'status' => TaskStatus::Open,
            'due_at' => now()->addDays(10),
        ]);

        Livewire::test(TasksIndex::class)
            ->assertViewHas('overdueCount', 1)
            ->assertViewHas('dueSoonCount', 1)
            ->assertSee('Overdue')
            ->assertSee('Due soon');

        // The chips filter to the flagged population.
        Livewire::test(TasksIndex::class)
            ->call('setDueWindow', 'overdue')
            ->assertSee('Overdue follow-up')
            ->assertDontSee('Imminent follow-up');

        Livewire::test(TasksIndex::class)
            ->call('setDueWindow', 'due-soon')
            ->assertSee('Imminent follow-up')
            ->assertDontSee('Overdue follow-up');

        // Tampered window values fall back to "all".
        Livewire::test(TasksIndex::class)
            ->call('setDueWindow', 'bogus')
            ->assertSee('Overdue follow-up')
            ->assertSee('Imminent follow-up');
    }

    public function test_mutating_actions_require_crm_manage_not_just_crm_view(): void
    {
        $this->seedRoles();

        $viewer = User::factory()->create();
        $viewer->givePermissionTo(PermissionsCatalog::CRM_VIEW);
        $this->actingAs($viewer);

        $task = Task::factory()->create();

        Livewire::test(TasksIndex::class)->assertOk()
            ->call('create')->assertForbidden();

        // Direct-property bypass: skipping create/edit must not skip the policy.
        Livewire::test(TasksIndex::class)
            ->set('task_title', 'Sneaky task')
            ->set('task_status', TaskStatus::Open->value)
            ->call('save')->assertForbidden();
        Livewire::test(TasksIndex::class)
            ->set('editingTaskId', $task->id)
            ->set('task_title', 'Renamed')
            ->call('save')->assertForbidden();
        Livewire::test(TasksIndex::class)
            ->set('confirmingDeleteId', $task->id)
            ->call('delete')->assertForbidden();

        $this->assertDatabaseCount('tasks', 1);
        $this->assertDatabaseHas('tasks', ['id' => $task->id, 'title' => $task->title]);
    }
}
