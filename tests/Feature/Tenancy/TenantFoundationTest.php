<?php

namespace Tests\Feature\Tenancy;

use App\Models\Tenant;
use App\Models\User;
use App\Shared\Enums\RoleName;
use App\Shared\Tenancy\TenantContext;
use App\Shared\Tenancy\TenantProvisioner;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ADR-0019 — tenant creation, owner assignment, and the user↔tenant
 * membership model (one user belongs to exactly one tenant).
 */
class TenantFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_tenant_can_be_created(): void
    {
        $tenant = Tenant::create(['name' => 'Acme Agency']);

        $this->assertDatabaseHas('tenants', ['id' => $tenant->id, 'name' => 'Acme Agency']);
        $this->assertNull($tenant->owner_user_id);
    }

    public function test_provisioner_creates_tenant_with_admin_owner_atomically(): void
    {
        $this->seedRoles();

        $tenant = app(TenantProvisioner::class)->create('Acme Agency', [
            'display_name' => 'Ada Owner',
            'email' => 'ada@acme.test',
            'password' => 'super-secret-password',
        ]);

        $owner = $tenant->owner;

        $this->assertNotNull($owner);
        $this->assertSame($tenant->id, $owner->tenant_id);
        $this->assertSame($owner->id, $tenant->owner_user_id);
        $this->assertSame(RoleName::Admin, $owner->roleName());
        $this->assertTrue($owner->active);
    }

    public function test_every_user_belongs_to_exactly_one_tenant(): void
    {
        $user = User::factory()->create();

        $this->assertNotNull($user->tenant_id);
        $this->assertSame($this->defaultTenant->id, $user->tenant_id);
        $this->assertTrue($user->tenant->is($this->defaultTenant));
    }

    public function test_user_cannot_be_created_without_a_tenant(): void
    {
        $this->expectException(QueryException::class);

        // Bypass factory defaults and the TenantContext stamp.
        $user = User::factory()->make(['tenant_id' => null]);
        $user->tenant_id = null;

        app(TenantContext::class)->runAs(null, fn () => $user->saveQuietly());
    }

    public function test_owner_must_belong_to_the_tenant_they_own(): void
    {
        $foreignUser = User::factory()->create(); // belongs to the default test tenant

        $other = Tenant::create(['name' => 'Other Agency']);

        $this->expectException(QueryException::class);

        // tenants_owner_same_tenant_fk: (owner_user_id, id) → users (id, tenant_id)
        $other->forceFill(['owner_user_id' => $foreignUser->id])->save();
    }

    public function test_user_email_is_globally_unique_across_tenants(): void
    {
        User::factory()->create(['email' => 'same@example.test']);

        $tenantB = $this->makeTenant('Tenant B');

        $this->expectException(QueryException::class);

        $this->withTenant($tenantB, fn () => User::factory()->create(['email' => 'same@example.test']));
    }

    public function test_tenant_users_relation_lists_all_members(): void
    {
        $members = User::factory()->count(3)->create();

        $this->assertEqualsCanonicalizing(
            $members->pluck('id')->all(),
            $this->defaultTenant->users()->pluck('id')->all(),
        );
    }
}
