<?php

namespace Tests\Feature\Crm;

use App\Modules\CRM\Livewire\Users\UsersIndex;
use App\Shared\Enums\RoleName;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Task 12 (ClientViewer decision enforcement): ADR-0016 keeps CLIENT_VIEWER a
 * dormant, deny-everything role — no client accounts are created on any
 * path. The admin Users form must offer/accept staff roles only (mirroring
 * TeamInvitationsPanel::invite), while an existing CLIENT_VIEWER user (e.g.
 * seeded before this change) stays editable without a forced promotion. The
 * two chrome logo links must not point a CLIENT_VIEWER session at a page it
 * cannot reach.
 */
class UsersRoleOfferingTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsAdmin(): void
    {
        $this->seedRoles();
        $this->actingAs($this->makeUser(RoleName::Admin));
    }

    public function test_role_dropdown_offers_staff_roles_only_when_creating(): void
    {
        $this->actingAsAdmin();

        Livewire::test(UsersIndex::class)
            ->call('create')
            ->assertDontSee(RoleName::ClientViewer->label());
    }

    public function test_creating_a_client_viewer_is_rejected(): void
    {
        $this->actingAsAdmin();

        Livewire::test(UsersIndex::class)
            ->call('create')
            ->set('display_name', 'Sneaky Shell')
            ->set('email', 'shell@example.test')
            ->set('role', RoleName::ClientViewer->value)
            ->set('password', 'a-long-secure-password')
            ->call('save')
            ->assertHasErrors(['role' => 'in']);
    }

    public function test_an_existing_client_viewer_stays_editable_without_a_role_change(): void
    {
        $this->actingAsAdmin();

        $viewer = $this->makeUser(RoleName::ClientViewer);

        Livewire::test(UsersIndex::class)
            ->call('edit', $viewer->id)
            ->set('display_name', 'Renamed Viewer')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertTrue($viewer->fresh()->isClientViewer());
    }

    public function test_logos_point_a_client_viewer_at_reports(): void
    {
        $this->seedRoles();
        $this->actingAs($this->makeUser(RoleName::ClientViewer));

        $this->get('/reports')
            ->assertOk()
            ->assertSee(route('reports.index'), false)
            ->assertDontSee('href="'.route('dashboard').'"', false);
    }
}
