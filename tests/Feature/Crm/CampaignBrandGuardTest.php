<?php

namespace Tests\Feature\Crm;

use App\Models\User;
use App\Modules\CRM\Exceptions\CampaignBrandLocked;
use App\Modules\CRM\Livewire\Campaigns\CampaignsIndex;
use App\Modules\CRM\Models\Brand;
use App\Modules\CRM\Models\Campaign;
use App\Modules\CRM\Models\SeedingCampaign;
use App\Modules\CRM\Services\CampaignWriter;
use App\Shared\Audit\AuditLogger;
use App\Shared\Enums\RoleName;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * F14 brand-coherence guard: a campaign's brand is denormalized onto every
 * seeding run under it, and the coherence rule historically lived only on the
 * seeding write path — so editing the campaign's brand silently desynced its
 * runs. CampaignWriter houses the guard: changing the brand is BLOCKED (and
 * surfaced as a validation error) while any seeding run still hangs off the
 * campaign — block-and-tell, never a silent cascade.
 */
class CampaignBrandGuardTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsCrmStaff(): User
    {
        $this->seedRoles();

        $staff = $this->makeUser(RoleName::InfluencerRelationsManager);
        $this->actingAs($staff);

        return $staff;
    }

    public function test_changing_brand_is_blocked_when_the_campaign_has_seeding_runs(): void
    {
        $this->actingAsCrmStaff();
        $campaign = Campaign::factory()->create();
        $otherBrand = Brand::factory()->create();
        SeedingCampaign::factory()->forCampaign($campaign)->create(['brand_id' => $campaign->brand_id]);

        Livewire::test(CampaignsIndex::class)
            ->call('edit', $campaign->id)
            ->set('campaign_brand_id', (string) $otherBrand->id)
            ->call('save')
            ->assertHasErrors(['campaign_brand_id']);

        $this->assertSame($campaign->brand_id, $campaign->fresh()->brand_id); // unchanged
    }

    public function test_changing_brand_is_allowed_when_no_seeding_runs_exist(): void
    {
        $this->actingAsCrmStaff();
        $campaign = Campaign::factory()->create();
        $otherBrand = Brand::factory()->create();

        Livewire::test(CampaignsIndex::class)
            ->call('edit', $campaign->id)
            ->set('campaign_brand_id', (string) $otherBrand->id)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame($otherBrand->id, $campaign->fresh()->brand_id);
    }

    public function test_editing_other_fields_with_runs_and_same_brand_succeeds(): void
    {
        $this->actingAsCrmStaff();
        $campaign = Campaign::factory()->create();
        SeedingCampaign::factory()->forCampaign($campaign)->create(['brand_id' => $campaign->brand_id]);

        Livewire::test(CampaignsIndex::class)
            ->call('edit', $campaign->id)
            ->set('campaign_name', 'Renamed With Runs')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('Renamed With Runs', $campaign->fresh()->name);
    }

    public function test_writer_service_enforces_the_guard_directly(): void
    {
        $this->actingAsCrmStaff();
        $campaign = Campaign::factory()->create();
        $otherBrand = Brand::factory()->create();
        SeedingCampaign::factory()->forCampaign($campaign)->create(['brand_id' => $campaign->brand_id]);

        $this->expectException(CampaignBrandLocked::class);
        app(CampaignWriter::class)
            ->updateCampaign($campaign, ['brand_id' => $otherBrand->id], app(AuditLogger::class));
    }
}
