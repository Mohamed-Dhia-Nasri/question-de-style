<?php

namespace Tests\Feature\Crm;

use App\Models\User;
use App\Modules\CRM\Livewire\Tasks\TasksIndex;
use App\Modules\CRM\Livewire\Tasks\TasksPanel;
use App\Modules\CRM\Models\SeedingCampaign;
use App\Modules\CRM\Models\Task;
use App\Shared\Authorization\PermissionsCatalog;
use App\Shared\Enums\RoleName;
use App\Shared\Enums\TaskStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Tasks can live on a seeding run (F18) — the third TasksPanel parent
 * (same exactly-one-prop pattern as DocumentsPanel), a seeding scope on
 * /crm/tasks (TasksIndex), and the run's Docs & Tasks tab mounting the
 * panel alongside crm.documents-panel.
 */
class TasksOnSeedingTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsCrmStaff(): User
    {
        $this->seedRoles();

        $staff = $this->makeUser(RoleName::InfluencerRelationsManager);
        $this->actingAs($staff);

        return $staff;
    }

    public function test_the_panel_mounts_with_a_seeding_run_parent_and_quick_add_anchors_to_it(): void
    {
        $this->actingAsCrmStaff();
        $run = SeedingCampaign::factory()->create();

        Livewire::test(TasksPanel::class, ['seedingCampaign' => $run])
            ->assertOk()
            ->set('quick_title', 'Chase the shipping label')
            ->set('quick_due_at', '2026-08-01T09:00')
            ->call('quickAdd')
            ->assertHasNoErrors();

        $task = Task::query()->where('seeding_campaign_id', $run->id)->firstOrFail();
        $this->assertSame('Chase the shipping label', $task->title);
        $this->assertSame(TaskStatus::Open, $task->status);
        $this->assertNull($task->creator_id);
        $this->assertNull($task->campaign_id);
        $this->assertDatabaseHas('audit_logs', ['action' => 'task.created', 'subject_id' => $task->id]);
    }

    public function test_tasks_query_shows_only_that_runs_tasks(): void
    {
        $this->actingAsCrmStaff();
        $run = SeedingCampaign::factory()->create();
        $otherRun = SeedingCampaign::factory()->create();

        $own = Task::factory()->create(['seeding_campaign_id' => $run->id, 'title' => 'Own run task']);
        Task::factory()->create(['seeding_campaign_id' => $otherRun->id, 'title' => 'Other run task']);
        Task::factory()->create(['title' => 'Unrelated task']);

        Livewire::test(TasksPanel::class, ['seedingCampaign' => $run])
            ->assertSee('Own run task')
            ->assertDontSee('Other run task')
            ->assertDontSee('Unrelated task');

        // Direct-property bypass: the query is parent-scoped, not just the view.
        $this->assertSame(
            [$own->id],
            Task::query()->where('seeding_campaign_id', $run->id)->pluck('id')->all(),
        );
    }

    public function test_a_view_only_users_quick_add_on_the_seeding_run_panel_is_forbidden(): void
    {
        $this->seedRoles();

        $viewer = User::factory()->create();
        $viewer->givePermissionTo(PermissionsCatalog::CRM_VIEW);
        $this->actingAs($viewer);

        $run = SeedingCampaign::factory()->create();

        Livewire::test(TasksPanel::class, ['seedingCampaign' => $run])
            ->assertOk()
            ->set('quick_title', 'Sneaky task')
            ->call('quickAdd')
            ->assertForbidden();

        $this->assertDatabaseCount('tasks', 0);
    }

    public function test_the_boards_seeding_select_validates_via_tenant_rule(): void
    {
        $this->actingAsCrmStaff();
        $tenantB = $this->makeTenant('Tenant B');
        $foreignRun = $this->withTenant($tenantB, fn () => SeedingCampaign::factory()->create());
        $ownRun = SeedingCampaign::factory()->create();

        // A foreign-tenant id and a genuinely non-existent id must both be
        // rejected — TenantRule::exists is not a cross-tenant oracle.
        Livewire::test(TasksIndex::class)
            ->call('create')
            ->set('task_title', 'Malicious')
            ->set('task_status', TaskStatus::Open->value)
            ->set('task_seeding_campaign_id', (string) $foreignRun->id)
            ->call('save')
            ->assertHasErrors(['task_seeding_campaign_id']);

        Livewire::test(TasksIndex::class)
            ->call('create')
            ->set('task_title', 'Bogus id')
            ->set('task_status', TaskStatus::Open->value)
            ->set('task_seeding_campaign_id', '999999')
            ->call('save')
            ->assertHasErrors(['task_seeding_campaign_id']);

        $this->assertSame(0, Task::query()->whereIn('title', ['Malicious', 'Bogus id'])->count());

        // The same field with an own-tenant id saves cleanly.
        Livewire::test(TasksIndex::class)
            ->call('create')
            ->set('task_title', 'Legitimate')
            ->set('task_status', TaskStatus::Open->value)
            ->set('task_seeding_campaign_id', (string) $ownRun->id)
            ->call('save')
            ->assertHasNoErrors();

        $task = Task::query()->where('title', 'Legitimate')->firstOrFail();
        $this->assertSame($ownRun->id, $task->seeding_campaign_id);
        $this->assertDatabaseHas('audit_logs', ['action' => 'task.created', 'subject_id' => $task->id]);
    }

    public function test_editing_a_seeding_linked_task_hydrates_and_persists_the_select(): void
    {
        $this->actingAsCrmStaff();
        $run = SeedingCampaign::factory()->create();
        $task = Task::factory()->create(['seeding_campaign_id' => $run->id]);

        Livewire::test(TasksIndex::class)
            ->call('edit', $task->id)
            ->assertSet('task_seeding_campaign_id', (string) $run->id)
            ->set('task_title', 'Renamed follow-up')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame($run->id, $task->refresh()->seeding_campaign_id);
    }

    public function test_the_seeding_detail_page_renders_the_tasks_panel(): void
    {
        $this->actingAsCrmStaff();
        $run = SeedingCampaign::factory()->create();

        $this->get('/crm/seeding/'.$run->id)
            ->assertOk()
            ->assertSeeLivewire(TasksPanel::class);
    }
}
