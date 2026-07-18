<?php

namespace Tests\Feature\Crm;

use App\Models\User;
use App\Modules\CRM\Livewire\Seeding\SeedingRunCreatePanel;
use App\Modules\CRM\Models\Campaign;
use App\Modules\CRM\Models\Product;
use App\Modules\CRM\Models\SeedingCampaign;
use App\Shared\Authorization\PermissionsCatalog;
use App\Shared\Enums\RoleName;
use App\Shared\Enums\SeedingCampaignStatus;
use App\Shared\Enums\SeedingType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * "+ New seeding run" on the campaign detail page (CRM UX Stage C, F11):
 * brand and campaign ids come from the mounted $campaign — never user
 * input — so a run created here is coherent with its campaign's brand by
 * construction.
 */
class SeedingRunCreatePanelTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsCrmStaff(): User
    {
        $this->seedRoles();

        $staff = $this->makeUser(RoleName::InfluencerRelationsManager);
        $this->actingAs($staff);

        return $staff;
    }

    public function test_creates_a_draft_run_under_the_campaigns_brand_and_redirects(): void
    {
        $this->actingAsCrmStaff();
        $campaign = Campaign::factory()->create();

        Livewire::test(SeedingRunCreatePanel::class, ['campaign' => $campaign])
            ->call('create')
            ->set('run_name', 'Frühling Gifting')
            ->set('run_type', SeedingType::Gifting->value)
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect(route('crm.campaigns.show', $campaign).'#seeding');

        $run = SeedingCampaign::query()->where('name', 'Frühling Gifting')->firstOrFail();
        $this->assertSame($campaign->brand_id, $run->brand_id);
        $this->assertSame($campaign->id, $run->campaign_id);
        $this->assertSame(SeedingCampaignStatus::Draft, $run->status);
    }

    public function test_a_product_from_another_brand_is_rejected(): void
    {
        $this->actingAsCrmStaff();
        $campaign = Campaign::factory()->create();
        $foreignProduct = Product::factory()->create();

        Livewire::test(SeedingRunCreatePanel::class, ['campaign' => $campaign])
            ->call('create')
            ->set('run_name', 'Sommer Kampagne')
            ->set('run_type', SeedingType::Gifting->value)
            ->set('run_product_id', (string) $foreignProduct->id)
            ->call('save')
            ->assertHasErrors(['run_product_id']);

        $this->assertDatabaseMissing('seeding_campaigns', ['name' => 'Sommer Kampagne']);
    }

    public function test_view_only_users_cannot_create_or_save(): void
    {
        $this->seedRoles();

        $viewer = User::factory()->create();
        $viewer->givePermissionTo(PermissionsCatalog::CRM_VIEW);
        $this->actingAs($viewer);

        $campaign = Campaign::factory()->create();

        Livewire::test(SeedingRunCreatePanel::class, ['campaign' => $campaign])
            ->call('create')->assertForbidden();

        Livewire::test(SeedingRunCreatePanel::class, ['campaign' => $campaign])
            ->set('run_name', 'Should Not Save')
            ->set('run_type', SeedingType::Gifting->value)
            ->call('save')->assertForbidden();

        $this->assertDatabaseMissing('seeding_campaigns', ['name' => 'Should Not Save']);
    }

    public function test_campaign_detail_page_shows_the_panel(): void
    {
        $this->actingAsCrmStaff();
        $campaign = Campaign::factory()->create();

        $this->get('/crm/campaigns/'.$campaign->id)
            ->assertOk()
            ->assertSeeLivewire(SeedingRunCreatePanel::class);
    }

    public function test_inline_product_lands_under_the_campaigns_brand(): void
    {
        $this->actingAsCrmStaff();
        $campaign = Campaign::factory()->create();

        Livewire::test(SeedingRunCreatePanel::class, ['campaign' => $campaign])
            ->call('create')
            ->call('openInlineCreate', 'product')
            ->set('inline_product_name', 'Gift Box')
            ->call('saveInlineCreate')
            ->assertHasNoErrors()
            ->assertSet('run_product_id', (string) Product::query()->where('name', 'Gift Box')->firstOrFail()->id);

        $this->assertSame($campaign->brand_id, Product::query()->where('name', 'Gift Box')->firstOrFail()->brand_id);
    }
}
