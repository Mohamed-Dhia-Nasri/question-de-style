<?php

namespace Tests\Feature\Crm;

use App\Modules\CRM\Livewire\Brands\BrandsIndex;
use App\Modules\CRM\Livewire\Campaigns\CampaignsIndex;
use App\Modules\CRM\Livewire\Seeding\SeedingCampaignsIndex;
use App\Shared\Enums\RoleName;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Task 4: friendly validation attribute names. Livewire's default error
 * messages fall back to the raw property name ("brand_client_id" — turned
 * into "brand client id" by Laravel's attribute humanizer) unless a
 * component defines validationAttributes(). These tests assert the CRM
 * forms speak in plain field names instead of snake_case property guts.
 */
class CrmValidationAttributesTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsCrmStaff(): void
    {
        $this->seedRoles();
        $this->actingAs($this->makeUser(RoleName::InfluencerRelationsManager));
    }

    public function test_brand_form_errors_use_field_names_not_property_names(): void
    {
        $this->actingAsCrmStaff();

        $errors = Livewire::test(BrandsIndex::class)
            ->call('create')
            ->call('save')
            ->errors();

        $flat = collect($errors->toArray())->flatten()->implode(' ');
        $this->assertStringNotContainsString('brand client id', $flat);
        $this->assertStringNotContainsString('brand name', $flat);
        $this->assertStringContainsString('client', $flat);
    }

    public function test_campaign_form_errors_use_field_names_not_property_names(): void
    {
        $this->actingAsCrmStaff();

        $flat = collect(Livewire::test(CampaignsIndex::class)
            ->call('create')->set('campaign_brand_id', '')->set('campaign_name', '')
            ->call('save')->errors()->toArray())->flatten()->implode(' ');

        $this->assertStringNotContainsString('campaign brand id', $flat);
        $this->assertStringNotContainsString('campaign name', $flat);
    }

    public function test_seeding_form_errors_use_field_names_not_property_names(): void
    {
        $this->actingAsCrmStaff();

        $flat = collect(Livewire::test(SeedingCampaignsIndex::class)
            ->call('create')->call('save')->errors()->toArray())->flatten()->implode(' ');

        $this->assertStringNotContainsString('seeding brand id', $flat);
        $this->assertStringNotContainsString('seeding brand id', $flat);
        $this->assertStringNotContainsString('seeding name', $flat);
    }
}
