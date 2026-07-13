<?php

namespace Tests;

use App\Models\Tenant;
use App\Models\User;
use App\Shared\Enums\RoleName;
use App\Shared\Tenancy\TenantContext;
use App\Shared\Tenancy\TenantProvisioner;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Spatie\Permission\PermissionRegistrar;

abstract class TestCase extends BaseTestCase
{
    /**
     * The tenant every factory chain lands in by default (ADR-0019).
     * Created once per test for database-backed tests and bound into the
     * TenantContext, so inline nested factories stay tenant-coherent.
     */
    protected ?Tenant $defaultTenant = null;

    protected function setUp(): void
    {
        parent::setUp();

        if (in_array(RefreshDatabase::class, class_uses_recursive(static::class), true)) {
            $this->defaultTenant = Tenant::factory()->create(['name' => 'Default Test Tenant']);
            app(TenantContext::class)->set($this->defaultTenant);
        }
    }

    /** Seed canonical roles/permissions and clear the spatie cache. */
    protected function seedRoles(): void
    {
        $this->seed(RolePermissionSeeder::class);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    protected function makeUser(RoleName $role, array $attributes = []): User
    {
        return User::factory()->withRole($role)->create($attributes);
    }

    /** Create an additional, fully separate tenant (does NOT switch context). */
    protected function makeTenant(string $name = 'Tenant'): Tenant
    {
        return Tenant::factory()->create(['name' => $name]);
    }

    /** Switch the active tenant context (subsequent factories/queries use it). */
    protected function actingAsTenant(Tenant $tenant): Tenant
    {
        app(TenantContext::class)->set($tenant);

        return $tenant;
    }

    /** Run a callback under another tenant's context, then restore. */
    protected function withTenant(Tenant $tenant, \Closure $callback): mixed
    {
        return app(TenantContext::class)->runAs($tenant, $callback);
    }

    /**
     * Two fully provisioned, isolated tenants (owner + ADMIN role each) for
     * cross-tenant scenarios. Requires roles — seeds them if absent.
     *
     * @return array{0: Tenant, 1: Tenant}
     */
    protected function makeTenantPair(): array
    {
        $this->seedRoles();

        $provisioner = app(TenantProvisioner::class);

        $context = app(TenantContext::class);

        return $context->runAs(null, fn (): array => [
            $provisioner->create('Tenant A', [
                'display_name' => 'Owner A',
                'email' => 'owner-a@tenant-a.test',
                'password' => 'password-tenant-a',
            ]),
            $provisioner->create('Tenant B', [
                'display_name' => 'Owner B',
                'email' => 'owner-b@tenant-b.test',
                'password' => 'password-tenant-b',
            ]),
        ]);
    }
}
