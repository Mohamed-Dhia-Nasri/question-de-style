<?php

namespace Tests\Feature\Reach;

use App\Modules\Monitoring\Livewire\Reach\ReachFormulaIndex;
use App\Shared\Enums\RoleName;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ReachFormulaIndexTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
    }

    public function test_admin_creates_a_draft_reach_configuration(): void
    {
        $admin = $this->makeUser(RoleName::Admin);
        Livewire::actingAs($admin)->test(ReachFormulaIndex::class)
            ->call('create')
            ->set('name', 'My reach')
            ->set('method', 'qds-estimated-reach')
            ->set('formulaVersion', 'reach-2026.1')
            ->set('viewWeight', '0.7')
            ->set('followerWeight', '0.1')
            ->call('save')
            ->assertHasNoErrors();
        $this->assertDatabaseHas('reach_configurations', ['name' => 'My reach', 'status' => 'DRAFT']);
    }

    public function test_invalid_weights_surface_a_form_error_and_create_no_record(): void
    {
        $admin = $this->makeUser(RoleName::Admin);
        Livewire::actingAs($admin)->test(ReachFormulaIndex::class)
            ->call('create')
            ->set('name', 'Bad')
            ->set('formulaVersion', 'reach-x')
            ->set('viewWeight', '1.0')
            ->set('followerWeight', '0.0')
            ->call('save')
            ->assertSet('formError', fn ($v) => $v !== null);
        $this->assertDatabaseMissing('reach_configurations', ['name' => 'Bad']);
    }

    public function test_non_admin_cannot_create(): void
    {
        $analyst = $this->makeUser(RoleName::Analyst);
        Livewire::actingAs($analyst)->test(ReachFormulaIndex::class)
            ->call('create')
            ->assertForbidden();
    }
}
