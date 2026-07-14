<?php

namespace Tests\Feature\Reach;

use App\Modules\Monitoring\Livewire\Reach\ReachFormulaIndex;
use App\Modules\Monitoring\Models\ReachConfiguration;
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

    public function test_duplicate_formula_version_surfaces_a_friendly_form_error_instead_of_a_500(): void
    {
        $admin = $this->makeUser(RoleName::Admin);

        Livewire::actingAs($admin)->test(ReachFormulaIndex::class)
            ->call('create')
            ->set('name', 'First reach model')
            ->set('formulaVersion', 'reach-dup')
            ->set('viewWeight', '0.7')
            ->set('followerWeight', '0.1')
            ->call('save')
            ->assertHasNoErrors();

        Livewire::actingAs($admin)->test(ReachFormulaIndex::class)
            ->call('create')
            ->set('name', 'Second reach model, same version')
            ->set('formulaVersion', 'reach-dup')
            ->set('viewWeight', '0.7')
            ->set('followerWeight', '0.1')
            ->call('save')
            ->assertSet('formError', fn ($v) => $v !== null);

        $this->assertSame(1, ReachConfiguration::query()->where('formula_version', 'reach-dup')->count());
    }

    public function test_non_admin_cannot_create(): void
    {
        $analyst = $this->makeUser(RoleName::Analyst);
        Livewire::actingAs($analyst)->test(ReachFormulaIndex::class)
            ->call('create')
            ->assertForbidden();
    }
}
