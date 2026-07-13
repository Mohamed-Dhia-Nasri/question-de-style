<?php

namespace Tests\Feature\Tenancy;

use App\Models\User;
use App\Modules\CRM\Models\Brand;
use App\Modules\CRM\Models\Campaign;
use App\Modules\CRM\Models\Client;
use App\Modules\CRM\Models\Creator;
use App\Modules\CRM\Models\DocumentAttachment;
use App\Modules\CRM\Models\Product;
use App\Modules\CRM\Models\Task;
use App\Modules\Monitoring\Models\Story;
use App\Platform\Export\Models\ExportJob;
use App\Shared\Enums\RoleName;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

/**
 * ADR-0019 hard enforcement — the TenantIsolationGate (Gate::before) denies
 * EVERY ability against a model owned by another tenant, ahead of any
 * permission check. The acting user here is a fully-privileged ADMIN of
 * Tenant A: role alone is never enough to reach Tenant B.
 *
 * This is the central mechanism that makes all 30 permission-only policies
 * tenant-aware without editing each one; the tests exercise a representative
 * spread of tenant-owned models across the CRM, Monitoring, and Export
 * surfaces.
 */
class CrossTenantAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private User $adminA;

    protected function setUp(): void
    {
        parent::setUp();

        // The default tenant (bound in TestCase::setUp) IS Tenant A; the
        // admin is created in it and acts under its context.
        $this->seedRoles();
        $this->adminA = $this->makeUser(RoleName::Admin);
        $this->actingAs($this->adminA);
    }

    /**
     * One representative tenant-owned model per surface, created in the
     * currently-bound tenant.
     *
     * @return array<string, Model>
     */
    private function ownedModels(): array
    {
        return [
            'client' => Client::factory()->create(),
            'brand' => Brand::factory()->create(),
            'creator' => Creator::factory()->create(),
            'campaign' => Campaign::factory()->create(),
            'product' => Product::factory()->create(),
            'task' => Task::factory()->create(),
            'document' => DocumentAttachment::factory()->create(),
            'story' => Story::factory()->create(),
            'export-job' => ExportJob::factory()->create(),
        ];
    }

    public function test_admin_is_denied_every_ability_on_a_foreign_tenant_model(): void
    {
        $tenantB = $this->makeTenant('Tenant B');
        $foreign = $this->withTenant($tenantB, fn () => $this->ownedModels());

        foreach ($foreign as $label => $model) {
            foreach (['view', 'update', 'delete', 'download'] as $ability) {
                $this->assertTrue(
                    Gate::forUser($this->adminA)->denies($ability, $model),
                    "Tenant A admin must be DENIED [{$ability}] on a Tenant B [{$label}]",
                );
            }
        }
    }

    public function test_same_tenant_model_is_not_blocked_by_the_backstop(): void
    {
        // Created in the default (acting) tenant A — the backstop must fall
        // through to the normal policy, which grants an ADMIN.
        foreach ($this->ownedModels() as $label => $model) {
            $this->assertTrue(
                Gate::forUser($this->adminA)->allows('view', $model),
                "Tenant A admin must retain [view] on its OWN [{$label}]",
            );
        }
    }

    public function test_backstop_denies_even_when_the_model_is_loaded_outside_a_bound_context(): void
    {
        // Simulate a model that reached a policy without the query scope
        // catching it (raw load / unbound context): the backstop still
        // compares tenant ids and denies.
        $tenantB = $this->makeTenant('Tenant B');
        $foreignCreator = $this->withTenant($tenantB, fn () => Creator::factory()->create());

        // Re-fetch WITHOUT the tenant scope to mimic an unscoped load.
        $unscoped = Creator::withoutGlobalScopes()->findOrFail($foreignCreator->id);

        $this->assertTrue(Gate::forUser($this->adminA)->denies('view', $unscoped));
        $this->assertTrue(Gate::forUser($this->adminA)->denies('update', $unscoped));
    }

    public function test_class_level_abilities_are_untouched_by_the_backstop(): void
    {
        // viewAny/create carry no model instance, so the backstop defers and
        // the permission policy decides — an ADMIN is allowed.
        $this->assertTrue(Gate::forUser($this->adminA)->allows('viewAny', Creator::class));
        $this->assertTrue(Gate::forUser($this->adminA)->allows('create', Campaign::class));
    }
}
