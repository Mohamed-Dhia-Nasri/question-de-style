<?php

namespace Tests\Feature\Crm;

use App\Models\User;
use App\Modules\CRM\Livewire\Campaigns\CampaignWizard;
use App\Modules\CRM\Models\Brand;
use App\Modules\CRM\Models\BrandPreference;
use App\Modules\CRM\Models\Campaign;
use App\Modules\CRM\Models\Client;
use App\Modules\CRM\Models\Creator;
use App\Modules\CRM\Models\SeedingCampaign;
use App\Shared\Authorization\PermissionsCatalog;
use App\Shared\Enums\CampaignStatus;
use App\Shared\Enums\RoleName;
use App\Shared\Enums\SeedingType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Optional guided campaign wizard at /crm/campaigns/new (CRM UX Stage C,
 * F01/F02): client → brand → campaign → seeding run → creators in one flow,
 * skippable at every step, one transaction at the end, Draft forced,
 * restricted creators silently skipped and reported on the Done screen.
 */
class CampaignWizardTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsCrmStaff(): User
    {
        $this->seedRoles();

        $staff = $this->makeUser(RoleName::InfluencerRelationsManager);
        $this->actingAs($staff);

        return $staff;
    }

    private function actingAsViewOnly(): User
    {
        $this->seedRoles();

        $viewer = User::factory()->create();
        $viewer->givePermissionTo(PermissionsCatalog::CRM_VIEW);
        $this->actingAs($viewer);

        return $viewer;
    }

    public function test_full_wizard_creates_client_brand_campaign_run_and_rosters_minus_restricted(): void
    {
        $this->actingAsCrmStaff();
        $ok = Creator::factory()->create(['display_name' => 'Greta Good']);
        $blocked = Creator::factory()->create(['display_name' => 'Nora NoGo']);
        BrandPreference::factory()->create(['creator_id' => $blocked->id, 'restricted_brands' => ['Atelier Nord']]);

        Livewire::test(CampaignWizard::class)
            ->set('client_mode', 'new')->set('new_client_name', 'Brückner GmbH')
            ->set('brand_mode', 'new')->set('new_brand_name', 'Atelier Nord')
            ->call('next')
            ->set('campaign_name', 'Creator Week')->call('next')
            ->set('with_seeding', true)->set('run_name', 'Welle Eins')
            ->set('run_type', SeedingType::Gifting->value)->call('next')
            ->set('selected_creator_ids', [(string) $ok->id, (string) $blocked->id])->call('next')
            ->call('finish')
            ->assertSet('finished', true)
            ->assertSee('Nora NoGo');

        $campaign = Campaign::query()->where('name', 'Creator Week')->firstOrFail();
        $this->assertSame(CampaignStatus::Draft, $campaign->status);
        $this->assertSame('Atelier Nord', $campaign->brand->name);
        $this->assertSame('Brückner GmbH', $campaign->brand->client->name);
        $this->assertTrue($campaign->creators()->whereKey($ok->id)->exists());
        $this->assertFalse($campaign->creators()->whereKey($blocked->id)->exists());

        $run = SeedingCampaign::query()->where('name', 'Welle Eins')->firstOrFail();
        $this->assertSame($campaign->id, $run->campaign_id);
        $this->assertTrue($run->creators()->whereKey($ok->id)->exists());
        $this->assertFalse($run->creators()->whereKey($blocked->id)->exists());
    }

    public function test_create_now_from_the_campaign_step_makes_only_the_campaign(): void
    {
        $this->actingAsCrmStaff();
        $client = Client::factory()->create();
        $brand = Brand::factory()->create(['client_id' => $client->id]);

        Livewire::test(CampaignWizard::class)
            ->set('wizard_client_id', (string) $client->id)
            ->set('wizard_brand_id', (string) $brand->id)
            ->call('next')
            ->set('campaign_name', 'Quick One')
            ->call('createNow')
            ->assertSet('finished', true);

        $this->assertNotNull(Campaign::query()->where('name', 'Quick One')->first());
        $this->assertSame(0, SeedingCampaign::query()->count());
    }

    public function test_the_literal_new_segment_wins_over_the_campaign_wildcard(): void
    {
        $this->actingAsCrmStaff();
        $this->get('/crm/campaigns/new')->assertOk()->assertSeeLivewire(CampaignWizard::class);
    }

    public function test_a_brand_of_another_client_is_rejected(): void
    {
        $this->actingAsCrmStaff();
        $clientA = Client::factory()->create();
        $clientB = Client::factory()->create();
        $brandB = Brand::factory()->create(['client_id' => $clientB->id]);

        Livewire::test(CampaignWizard::class)
            ->set('client_mode', 'existing')
            ->set('wizard_client_id', (string) $clientA->id)
            ->set('brand_mode', 'existing')
            ->set('wizard_brand_id', (string) $brandB->id)
            ->call('next')
            ->assertHasErrors('wizard_brand_id')
            ->assertSet('step', 1);
    }

    public function test_step_one_validation_blocks_next(): void
    {
        $this->actingAsCrmStaff();

        Livewire::test(CampaignWizard::class)
            ->set('client_mode', 'new')
            ->set('new_client_name', '')
            ->call('next')
            ->assertHasErrors('new_client_name')
            ->assertSet('step', 1);
    }

    public function test_client_viewer_is_forbidden_on_the_route(): void
    {
        $this->seedRoles();
        $this->actingAs($this->makeUser(RoleName::ClientViewer));

        $this->get('/crm/campaigns/new')->assertForbidden();
    }

    public function test_view_only_users_are_forbidden_at_mount(): void
    {
        $this->actingAsViewOnly();

        Livewire::test(CampaignWizard::class)->assertForbidden();
    }

    public function test_a_restricted_candidate_shows_the_warning_on_the_creators_step(): void
    {
        $this->actingAsCrmStaff();
        $creator = Creator::factory()->create(['display_name' => 'Nora NoGo']);
        BrandPreference::factory()->create(['creator_id' => $creator->id, 'restricted_brands' => ['Atelier Nord']]);

        Livewire::test(CampaignWizard::class)
            ->set('client_mode', 'new')->set('new_client_name', 'Brückner GmbH')
            ->set('brand_mode', 'new')->set('new_brand_name', 'Atelier Nord')
            ->call('next')
            ->set('campaign_name', 'Creator Week')->call('next')
            ->call('next')
            ->assertSet('step', 4)
            ->assertSee('no-go');
    }

    public function test_view_only_users_cannot_create_via_finish(): void
    {
        $this->actingAsViewOnly();

        // The whole surface 403s at mount for view-only users — a create-only
        // page. There is no reachable finish() for them to call.
        Livewire::test(CampaignWizard::class)->assertForbidden();

        $this->assertSame(0, Campaign::query()->count());
    }
}
