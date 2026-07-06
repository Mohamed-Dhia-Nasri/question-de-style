<?php

namespace Tests\Feature;

use App\Models\User;
use App\Shared\Authorization\PermissionsCatalog;
use App\Shared\Enums\RoleName;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_exactly_the_six_canonical_roles_are_seeded(): void
    {
        $this->seedRoles();

        $this->assertEqualsCanonicalizing(
            array_column(RoleName::cases(), 'value'),
            Role::pluck('name')->all(),
        );
    }

    public function test_client_viewer_holds_only_the_approved_reports_permission(): void
    {
        $this->seedRoles();

        $permissions = Role::findByName(RoleName::ClientViewer->value)
            ->permissions
            ->pluck('name')
            ->all();

        $this->assertSame([PermissionsCatalog::REPORTS_VIEW_APPROVED], $permissions);
    }

    public function test_seeder_is_idempotent(): void
    {
        $this->seedRoles();
        $this->seedRoles();

        $this->assertSame(count(RoleName::cases()), Role::count());
    }

    public function test_staff_can_access_internal_areas_but_not_user_administration(): void
    {
        $this->seedRoles();
        $analyst = $this->makeUser(RoleName::Analyst);

        foreach (['/dashboard', '/monitoring', '/discovery', '/crm', '/reports'] as $uri) {
            $this->actingAs($analyst)->get($uri)->assertOk();
        }

        $this->actingAs($analyst)->get('/admin/users')->assertForbidden();
    }

    public function test_admin_can_access_every_area(): void
    {
        $this->seedRoles();
        $admin = $this->makeUser(RoleName::Admin);

        foreach (['/dashboard', '/monitoring', '/discovery', '/crm', '/reports', '/admin/users'] as $uri) {
            $this->actingAs($admin)->get($uri)->assertOk();
        }
    }

    public function test_client_viewer_is_confined_to_the_reports_area(): void
    {
        $this->seedRoles();
        $viewer = $this->makeUser(RoleName::ClientViewer);

        $this->actingAs($viewer)->get('/reports')->assertOk();

        foreach (['/dashboard', '/monitoring', '/discovery', '/crm', '/admin/users'] as $uri) {
            $this->actingAs($viewer)->get($uri)->assertForbidden();
        }
    }

    public function test_only_admin_may_write_users_via_policy(): void
    {
        $this->seedRoles();

        $admin = $this->makeUser(RoleName::Admin);
        $analyst = $this->makeUser(RoleName::Analyst);
        $target = $this->makeUser(RoleName::CampaignManager);

        $this->assertTrue(Gate::forUser($admin)->allows('create', User::class));
        $this->assertTrue(Gate::forUser($admin)->allows('update', $target));
        $this->assertTrue(Gate::forUser($admin)->allows('delete', $target));

        $this->assertFalse(Gate::forUser($analyst)->allows('create', User::class));
        $this->assertFalse(Gate::forUser($analyst)->allows('update', $target));
        $this->assertFalse(Gate::forUser($analyst)->allows('delete', $target));
    }

    public function test_admins_cannot_delete_their_own_account(): void
    {
        $this->seedRoles();
        $admin = $this->makeUser(RoleName::Admin);

        $this->assertFalse(Gate::forUser($admin)->allows('delete', $admin));
    }

    public function test_every_user_holds_exactly_one_role(): void
    {
        $this->seedRoles();
        $user = $this->makeUser(RoleName::Analyst);

        // Re-assignment replaces the role rather than accumulating (ENT-User:
        // exactly one role).
        $user->syncRoles([RoleName::Admin->value]);

        $this->assertCount(1, $user->fresh()->roles);
        $this->assertSame(RoleName::Admin, $user->fresh()->roleName());
    }
}
