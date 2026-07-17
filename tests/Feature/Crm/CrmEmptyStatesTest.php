<?php

namespace Tests\Feature\Crm;

use App\Modules\CRM\Livewire\Campaigns\CampaignsIndex;
use App\Modules\CRM\Livewire\Clients\ClientsIndex;
use App\Shared\Enums\CampaignStatus;
use App\Shared\Enums\RoleName;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class CrmEmptyStatesTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsCrmStaff(): void
    {
        $this->seedRoles();
        $this->actingAs($this->makeUser(RoleName::InfluencerRelationsManager));
    }

    public function test_fresh_tenant_sees_first_run_copy_with_a_cta_not_filter_blame(): void
    {
        $this->actingAsCrmStaff();

        Livewire::test(CampaignsIndex::class)
            ->assertSee('No campaigns yet')
            ->assertDontSee('match your filters');

        Livewire::test(ClientsIndex::class)
            ->assertSee('No clients yet')
            ->assertSee('New client');
    }

    public function test_filtered_empty_result_blames_the_filters_not_the_tenant(): void
    {
        $this->actingAsCrmStaff();

        Livewire::test(CampaignsIndex::class)
            ->set('statusFilter', CampaignStatus::Completed->value)
            ->assertSee('No campaigns match your filters')
            ->assertDontSee('No campaigns yet');
    }
}
