<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\CRM\Livewire\Users\UsersIndex;
use App\Shared\Audit\AuditLog;
use App\Shared\Enums\RoleName;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Reference-CRUD coverage: rendering, searching, sorting (incl. whitelist),
 * filtering, pagination, validation, authorization, delete confirmation,
 * bulk actions, and audit events.
 */
class UsersCrudTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsAdmin(): User
    {
        $this->seedRoles();

        $admin = $this->makeUser(RoleName::Admin, ['display_name' => 'Root Admin']);
        $this->actingAs($admin);

        return $admin;
    }

    public function test_component_renders_on_the_admin_users_page(): void
    {
        $this->actingAsAdmin();

        $this->get('/admin/users')
            ->assertOk()
            ->assertSeeLivewire(UsersIndex::class);
    }

    public function test_non_admins_cannot_mount_the_component(): void
    {
        $this->seedRoles();
        $this->actingAs($this->makeUser(RoleName::Analyst));

        Livewire::test(UsersIndex::class)->assertForbidden();
    }

    public function test_search_filters_by_name_and_email(): void
    {
        $this->actingAsAdmin();

        User::factory()->withRole(RoleName::Analyst)->create(['display_name' => 'Ada Lovelace', 'email' => 'ada@qds.test']);
        User::factory()->withRole(RoleName::Analyst)->create(['display_name' => 'Grace Hopper', 'email' => 'grace@qds.test']);

        Livewire::test(UsersIndex::class)
            ->set('search', 'lovelace')
            ->assertSee('Ada Lovelace')
            ->assertDontSee('Grace Hopper');
    }

    public function test_sorting_toggles_direction_and_ignores_unknown_columns(): void
    {
        $this->actingAsAdmin();

        $component = Livewire::test(UsersIndex::class)
            ->call('sortBy', 'email')
            ->assertSet('sortField', 'email')
            ->assertSet('sortDirection', 'asc')
            ->call('sortBy', 'email')
            ->assertSet('sortDirection', 'desc');

        // Not in the whitelist → must not become the sort column.
        $component->call('sortBy', 'password')
            ->assertSet('sortField', 'email');
    }

    public function test_tampered_query_string_sort_falls_back_safely(): void
    {
        $this->actingAsAdmin();

        Livewire::withQueryParams(['sortField' => 'password;DROP TABLE users'])
            ->test(UsersIndex::class)
            ->assertOk();
    }

    public function test_role_and_status_filters_narrow_the_result(): void
    {
        $this->actingAsAdmin();

        User::factory()->withRole(RoleName::Analyst)->create(['display_name' => 'Active Analyst']);
        User::factory()->withRole(RoleName::CampaignManager)->inactive()->create(['display_name' => 'Sleeping Manager']);

        Livewire::test(UsersIndex::class)
            ->set('roleFilter', RoleName::Analyst->value)
            ->assertSee('Active Analyst')
            ->assertDontSee('Sleeping Manager')
            ->set('roleFilter', '')
            ->set('statusFilter', 'inactive')
            ->assertSee('Sleeping Manager')
            ->assertDontSee('Active Analyst');
    }

    public function test_pagination_limits_the_page_size(): void
    {
        $this->actingAsAdmin();

        User::factory()->count(15)->withRole(RoleName::Analyst)->create();

        Livewire::test(UsersIndex::class)
            ->assertViewHas('users', fn ($users) => $users->count() === 10 && $users->total() === 16)
            ->set('perPage', 25)
            ->assertViewHas('users', fn ($users) => $users->count() === 16);
    }

    public function test_create_validates_server_side(): void
    {
        $this->actingAsAdmin();

        Livewire::test(UsersIndex::class)
            ->call('create')
            ->assertSet('showForm', true)
            ->set('display_name', '')
            ->set('email', 'not-an-email')
            ->set('role', 'SUPER_USER')
            ->set('password', 'short')
            ->call('save')
            ->assertHasErrors([
                'display_name' => 'required',
                'email' => 'email',
                'role' => 'in',
                'password' => 'min',
            ]);
    }

    public function test_create_rejects_duplicate_emails(): void
    {
        $this->actingAsAdmin();

        User::factory()->create(['email' => 'taken@qds.test']);

        Livewire::test(UsersIndex::class)
            ->call('create')
            ->set('display_name', 'New Person')
            ->set('email', 'taken@qds.test')
            ->set('role', RoleName::Analyst->value)
            ->set('password', 'a-long-secure-password')
            ->call('save')
            ->assertHasErrors(['email' => 'unique']);
    }

    public function test_admin_can_create_a_user_with_exactly_one_role_and_an_audit_event(): void
    {
        $this->actingAsAdmin();

        Livewire::test(UsersIndex::class)
            ->call('create')
            ->set('display_name', 'Neue Kollegin')
            ->set('email', 'neue@qds.test')
            ->set('role', RoleName::CampaignManager->value)
            ->set('password', 'a-long-secure-password')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('showForm', false);

        $created = User::where('email', 'neue@qds.test')->firstOrFail();

        $this->assertSame(RoleName::CampaignManager, $created->roleName());
        $this->assertCount(1, $created->roles);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'user.created',
            'subject_id' => $created->id,
        ]);
    }

    public function test_edit_updates_without_forcing_a_password_change(): void
    {
        $this->actingAsAdmin();

        $user = User::factory()->withRole(RoleName::Analyst)->create();
        $originalPassword = $user->password;

        Livewire::test(UsersIndex::class)
            ->call('edit', $user->id)
            ->assertSet('display_name', $user->display_name)
            ->set('display_name', 'Renamed Person')
            ->set('role', RoleName::AccountDirector->value)
            ->call('save')
            ->assertHasNoErrors();

        $user->refresh();

        $this->assertSame('Renamed Person', $user->display_name);
        $this->assertSame(RoleName::AccountDirector, $user->roleName());
        $this->assertSame($originalPassword, $user->password);
    }

    public function test_delete_requires_confirmation_and_records_an_audit_event(): void
    {
        $this->actingAsAdmin();

        $user = User::factory()->withRole(RoleName::Analyst)->create();

        Livewire::test(UsersIndex::class)
            ->call('confirmDelete', $user->id)
            ->assertSet('confirmingDeleteId', $user->id)
            ->call('delete');

        $this->assertDatabaseMissing('users', ['id' => $user->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'user.deleted', 'subject_id' => $user->id]);
    }

    public function test_admins_cannot_be_pointed_at_client_viewer_or_deactivate_themselves(): void
    {
        $admin = $this->actingAsAdmin();

        // CLIENT_VIEWER isn't an assignable role at all now (ADR-0016, Task
        // 12) — this fails the allow-list before the self-lockout guard
        // below ever runs. Self-demotion between staff roles is covered
        // separately in test_admins_cannot_demote_themselves_to_another_staff_role().
        Livewire::test(UsersIndex::class)
            ->call('edit', $admin->id)
            ->set('role', RoleName::ClientViewer->value)
            ->call('save')
            ->assertHasErrors(['role' => 'in']);

        Livewire::test(UsersIndex::class)
            ->call('edit', $admin->id)
            ->set('active', false)
            ->call('save')
            ->assertHasErrors(['active']);

        $admin->refresh();
        $this->assertSame(RoleName::Admin, $admin->roleName());
        $this->assertTrue($admin->active);
    }

    public function test_admins_cannot_demote_themselves_to_another_staff_role(): void
    {
        $admin = $this->actingAsAdmin();

        Livewire::test(UsersIndex::class)
            ->call('edit', $admin->id)
            ->set('role', RoleName::Analyst->value)
            ->call('save')
            ->assertHasErrors(['role']);

        $this->assertSame(RoleName::Admin, $admin->fresh()->roleName());
    }

    public function test_audit_context_preserves_the_actor_id(): void
    {
        $admin = $this->actingAsAdmin();

        $user = User::factory()->withRole(RoleName::Analyst)->create();

        Livewire::test(UsersIndex::class)
            ->call('confirmDelete', $user->id)
            ->call('delete');

        $log = AuditLog::where('action', 'user.deleted')->firstOrFail();

        $this->assertSame($admin->id, $log->context['actor_id']);
    }

    public function test_admins_cannot_delete_themselves(): void
    {
        $admin = $this->actingAsAdmin();

        Livewire::test(UsersIndex::class)
            ->call('confirmDelete', $admin->id)
            ->assertForbidden()
            ->assertSet('confirmingDeleteId', null);

        $this->assertDatabaseHas('users', ['id' => $admin->id]);
    }

    public function test_the_tenant_owner_cannot_be_deleted(): void
    {
        // H3: tenants.owner_user_id is a RESTRICT foreign key, so deleting the
        // founding owner would crash the request with a 500. A second admin
        // must be refused at the authorization layer, and the owner left intact.
        [$tenantA] = $this->makeTenantPair();
        $ownerA = $tenantA->owner;
        $adminB = $this->withTenant($tenantA, fn (): User => $this->makeUser(RoleName::Admin));

        $this->actingAsTenant($tenantA);
        $this->actingAs($adminB);

        // The policy denies (authoritative) — even though B holds users.manage
        // and A is not B.
        $this->assertTrue(Gate::forUser($adminB)->denies('delete', $ownerA));

        Livewire::test(UsersIndex::class)
            ->call('confirmDelete', $ownerA->id)
            ->assertForbidden()
            ->assertSet('confirmingDeleteId', null);

        $this->assertDatabaseHas('users', ['id' => $ownerA->id]);
    }

    public function test_the_tenant_owner_cannot_be_deactivated_via_the_edit_form(): void
    {
        // M21: the billing owner is the sole billing.manage authority and
        // EnsureUserIsActive logs out any inactive user — deactivating the
        // owner locks the whole tenant out of billing. A second admin must be
        // refused, mirroring the H3 owner-delete guard.
        [$tenantA] = $this->makeTenantPair();
        $ownerA = $tenantA->owner;
        $adminB = $this->withTenant($tenantA, fn (): User => $this->makeUser(RoleName::Admin));

        $this->actingAsTenant($tenantA);
        $this->actingAs($adminB);

        Livewire::test(UsersIndex::class)
            ->call('edit', $ownerA->id)
            ->set('active', false)
            ->call('save')
            ->assertHasErrors(['active']);

        $this->assertTrue($ownerA->fresh()->active);
    }

    public function test_the_tenant_owner_cannot_be_deactivated_via_bulk_action(): void
    {
        [$tenantA] = $this->makeTenantPair();
        $ownerA = $tenantA->owner;
        $adminB = $this->withTenant($tenantA, fn (): User => $this->makeUser(RoleName::Admin));

        $this->actingAsTenant($tenantA);
        $this->actingAs($adminB);

        Livewire::test(UsersIndex::class)
            ->set('selected', [(string) $ownerA->id])
            ->call('bulkSetActive', false);

        $this->assertTrue($ownerA->fresh()->active);
    }

    public function test_bulk_deactivation_skips_the_current_admin(): void
    {
        $admin = $this->actingAsAdmin();

        $other = User::factory()->withRole(RoleName::Analyst)->create();

        Livewire::test(UsersIndex::class)
            ->set('selected', [(string) $admin->id, (string) $other->id])
            ->call('bulkSetActive', false);

        $this->assertTrue($admin->fresh()->active);
        $this->assertFalse($other->fresh()->active);
    }
}
