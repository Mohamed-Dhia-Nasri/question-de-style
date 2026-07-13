<?php

namespace Tests\Feature\Tenancy;

use App\Models\User;
use App\Modules\CRM\Livewire\Tasks\TasksIndex;
use App\Modules\CRM\Models\Campaign;
use App\Modules\CRM\Models\Creator;
use App\Modules\CRM\Models\Task;
use App\Shared\Enums\RoleName;
use App\Shared\Enums\TaskStatus;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * ADR-0019 — tenant ownership can never be forged from client input, and a
 * forged foreign-tenant foreign key is rejected as a clean validation error
 * (TenantRule::exists), not a raw composite-FK 500 nor a saved cross-tenant
 * link. The acting user is a fully-privileged ADMIN of Tenant A.
 */
class CrossTenantForgeryTest extends TestCase
{
    use RefreshDatabase;

    private User $adminA;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
        $this->adminA = $this->makeUser(RoleName::Admin);
        $this->actingAs($this->adminA);
    }

    public function test_tenant_id_is_not_mass_assignable(): void
    {
        $tenantB = $this->makeTenant('Tenant B');

        // A client-supplied tenant_id in a mass-assign array is rejected
        // outright (tenant_id is not fillable + strict mode) — it can never
        // silently override ownership.
        $this->expectException(MassAssignmentException::class);

        Creator::create([
            'display_name' => 'Forged Owner',
            'tenant_id' => $tenantB->id,
        ]);
    }

    public function test_ownership_is_always_stamped_from_the_bound_context(): void
    {
        // A legitimate create (fillable fields only) is stamped with the
        // acting tenant from TenantContext, never guessed or client-set.
        $creator = Creator::create(['display_name' => 'Legit']);

        $this->assertSame(
            (int) $this->defaultTenant->id,
            (int) $creator->fresh()->tenant_id,
        );
    }

    public function test_forged_foreign_task_fk_fails_as_validation_error_not_a_500(): void
    {
        $tenantB = $this->makeTenant('Tenant B');

        [$foreignCreator, $foreignCampaign, $foreignAssignee] = $this->withTenant($tenantB, fn () => [
            Creator::factory()->create(),
            Campaign::factory()->create(),
            $this->makeUser(RoleName::Admin),
        ]);

        Livewire::test(TasksIndex::class)
            ->call('create')
            ->set('task_title', 'Malicious')
            ->set('task_status', TaskStatus::Open->value)
            ->set('task_creator_id', $foreignCreator->id)
            ->set('task_campaign_id', $foreignCampaign->id)
            ->set('task_assignee_id', $foreignAssignee->id)
            ->call('save')
            ->assertHasErrors(['task_creator_id', 'task_campaign_id', 'task_assignee_id']);

        // Nothing was written — no cross-tenant link slipped through.
        $this->assertSame(0, Task::query()->where('title', 'Malicious')->count());
    }

    public function test_own_tenant_task_fk_still_saves(): void
    {
        // The same form with own-tenant ids succeeds — the rejection above is
        // isolation, not a broken validator.
        $creator = Creator::factory()->create();
        $campaign = Campaign::factory()->create();

        Livewire::test(TasksIndex::class)
            ->call('create')
            ->set('task_title', 'Legitimate')
            ->set('task_status', TaskStatus::Open->value)
            ->set('task_creator_id', $creator->id)
            ->set('task_campaign_id', $campaign->id)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(1, Task::query()->where('title', 'Legitimate')->count());
    }

    public function test_existence_validator_is_not_a_cross_tenant_oracle(): void
    {
        // A foreign-tenant creator id and a genuinely non-existent id must
        // BOTH fail the same way — no "exists elsewhere" signal.
        $tenantB = $this->makeTenant('Tenant B');
        $foreignCreator = $this->withTenant($tenantB, fn () => Creator::factory()->create());

        $foreignResult = Livewire::test(TasksIndex::class)
            ->call('create')
            ->set('task_title', 'A')
            ->set('task_status', TaskStatus::Open->value)
            ->set('task_creator_id', $foreignCreator->id)
            ->call('save')
            ->assertHasErrors(['task_creator_id']);

        $missingResult = Livewire::test(TasksIndex::class)
            ->call('create')
            ->set('task_title', 'B')
            ->set('task_status', TaskStatus::Open->value)
            ->set('task_creator_id', 999999)
            ->call('save')
            ->assertHasErrors(['task_creator_id']);

        $this->assertSame(
            $foreignResult->errors()->get('task_creator_id'),
            $missingResult->errors()->get('task_creator_id'),
            'Foreign-tenant and non-existent ids must produce an identical error',
        );
    }
}
