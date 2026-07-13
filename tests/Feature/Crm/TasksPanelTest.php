<?php

namespace Tests\Feature\Crm;

use App\Models\User;
use App\Modules\CRM\Livewire\Tasks\TasksPanel;
use App\Modules\CRM\Models\Campaign;
use App\Modules\CRM\Models\Creator;
use App\Modules\CRM\Models\Task;
use App\Shared\Audit\AuditLog;
use App\Shared\Authorization\PermissionsCatalog;
use App\Shared\Enums\RoleName;
use App\Shared\Enums\TaskStatus;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\View\ViewException;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Compact tasks panel (REQ-M3-011, AC-M3-017) on the creator profile and
 * campaign detail: quick-add anchors the task to the parent, status flips
 * stay inside ENUM-TaskStatus and are audited from→to, and every mutator
 * enforces crm.manage.
 */
class TasksPanelTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsCrmStaff(): User
    {
        $this->seedRoles();

        $staff = $this->makeUser(RoleName::CampaignManager);
        $this->actingAs($staff);

        return $staff;
    }

    public function test_client_viewers_cannot_mount_the_panel(): void
    {
        $this->seedRoles();
        $this->actingAs($this->makeUser(RoleName::ClientViewer));

        Livewire::test(TasksPanel::class, ['creator' => Creator::factory()->create()])
            ->assertForbidden();
    }

    public function test_the_panel_requires_exactly_one_parent(): void
    {
        $this->actingAsCrmStaff();

        // The InvalidArgumentException from mount surfaces wrapped by the
        // Blade layer that renders the component.
        $this->expectException(ViewException::class);
        $this->expectExceptionMessage('must be mounted with exactly one parent');

        Livewire::test(TasksPanel::class, [
            'creator' => Creator::factory()->create(),
            'campaign' => Campaign::factory()->create(),
        ]);
    }

    public function test_quick_add_anchors_an_open_task_to_each_parent_kind(): void
    {
        $this->actingAsCrmStaff();

        $parents = [
            'creator' => ['creator_id', Creator::factory()->create()],
            'campaign' => ['campaign_id', Campaign::factory()->create()],
        ];

        foreach ($parents as $parameter => [$column, $parent]) {
            Livewire::test(TasksPanel::class, [$parameter => $parent])
                ->set('quick_title', 'Follow up on the brief')
                ->set('quick_due_at', '2026-08-01T09:00')
                ->call('quickAdd')
                ->assertHasNoErrors();

            $task = Task::query()->where($column, $parent->id)->firstOrFail();
            $this->assertSame('Follow up on the brief', $task->title);
            $this->assertSame(TaskStatus::Open, $task->status);
            $this->assertTrue($task->due_at->equalTo('2026-08-01 09:00:00'));
            $this->assertDatabaseHas('audit_logs', ['action' => 'task.created', 'subject_id' => $task->id]);
        }
    }

    public function test_quick_add_requires_a_title(): void
    {
        $this->actingAsCrmStaff();

        Livewire::test(TasksPanel::class, ['creator' => Creator::factory()->create()])
            ->set('quick_title', '')
            ->call('quickAdd')
            ->assertHasErrors(['quick_title']);

        $this->assertDatabaseCount('tasks', 0);
    }

    public function test_a_status_flip_is_audited_from_to(): void
    {
        $this->actingAsCrmStaff();
        $creator = Creator::factory()->create();
        $task = Task::factory()->create(['creator_id' => $creator->id, 'status' => TaskStatus::Open]);

        Livewire::test(TasksPanel::class, ['creator' => $creator])
            ->call('setStatus', $task->id, TaskStatus::Done->value)
            ->assertHasNoErrors();

        $this->assertSame(TaskStatus::Done, $task->refresh()->status);

        $log = AuditLog::query()
            ->where('action', 'task.status_changed')
            ->where('subject_id', $task->id)
            ->firstOrFail();

        $this->assertSame('OPEN', $log->context['from']);
        $this->assertSame('DONE', $log->context['to']);
    }

    public function test_a_status_outside_the_closed_set_is_refused(): void
    {
        $this->actingAsCrmStaff();
        $creator = Creator::factory()->create();
        $task = Task::factory()->create(['creator_id' => $creator->id, 'status' => TaskStatus::Open]);

        Livewire::test(TasksPanel::class, ['creator' => $creator])
            ->call('setStatus', $task->id, 'ARCHIVED')
            ->assertHasErrors(['status']);

        $this->assertSame(TaskStatus::Open, $task->refresh()->status);
    }

    public function test_tasks_of_other_parents_are_out_of_reach(): void
    {
        $this->actingAsCrmStaff();
        $creator = Creator::factory()->create();
        $foreignTask = Task::factory()->create(['creator_id' => Creator::factory()->create()->id]);

        // The panel query is parent-scoped: a foreign task id is not found.
        $this->expectException(ModelNotFoundException::class);

        Livewire::test(TasksPanel::class, ['creator' => $creator])
            ->call('setStatus', $foreignTask->id, TaskStatus::Done->value);
    }

    public function test_mutating_actions_require_crm_manage_not_just_crm_view(): void
    {
        $this->seedRoles();

        $viewer = User::factory()->create();
        $viewer->givePermissionTo(PermissionsCatalog::CRM_VIEW);
        $this->actingAs($viewer);

        $creator = Creator::factory()->create();
        $task = Task::factory()->create(['creator_id' => $creator->id, 'status' => TaskStatus::Open]);

        Livewire::test(TasksPanel::class, ['creator' => $creator])->assertOk()
            ->set('quick_title', 'Sneaky task')
            ->call('quickAdd')->assertForbidden();
        Livewire::test(TasksPanel::class, ['creator' => $creator])
            ->call('setStatus', $task->id, TaskStatus::Done->value)->assertForbidden();

        $this->assertDatabaseCount('tasks', 1);
        $this->assertSame(TaskStatus::Open, $task->refresh()->status);
    }
}
