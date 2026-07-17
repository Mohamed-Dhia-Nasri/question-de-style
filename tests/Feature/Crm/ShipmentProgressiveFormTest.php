<?php

namespace Tests\Feature\Crm;

use App\Modules\CRM\Livewire\Seeding\ShipmentsPanel;
use App\Modules\CRM\Models\Creator;
use App\Modules\CRM\Models\Product;
use App\Modules\CRM\Models\SeedingCampaign;
use App\Modules\CRM\Models\Shipment;
use App\Shared\Enums\RoleName;
use App\Shared\Enums\ShipmentStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ShipmentProgressiveFormTest extends TestCase
{
    use RefreshDatabase;

    private SeedingCampaign $run;

    private function actingAsCrmStaff(): void
    {
        $this->seedRoles();
        $this->actingAs($this->makeUser(RoleName::InfluencerRelationsManager));
        $this->run = SeedingCampaign::factory()->create();
    }

    public function test_pending_shipment_form_hides_tracking_and_delivery_fields(): void
    {
        $this->actingAsCrmStaff();

        Livewire::test(ShipmentsPanel::class, ['seedingCampaign' => $this->run])
            ->call('create')
            ->assertDontSeeHtml('id="shipment_tracking_number"')
            ->assertDontSeeHtml('id="shipment_shipped_at"')
            ->assertDontSeeHtml('id="shipment_delivered_at"');
    }

    public function test_shipped_status_reveals_tracking_but_not_delivery(): void
    {
        $this->actingAsCrmStaff();

        Livewire::test(ShipmentsPanel::class, ['seedingCampaign' => $this->run])
            ->call('create')
            ->set('shipment_status', ShipmentStatus::Shipped->value)
            ->assertSeeHtml('id="shipment_tracking_number"')
            ->assertSeeHtml('id="shipment_shipped_at"')
            ->assertDontSeeHtml('id="shipment_delivered_at"');
    }

    public function test_downgrading_status_clears_hidden_values(): void
    {
        $this->actingAsCrmStaff();
        $creator = Creator::factory()->create();
        $this->run->creators()->syncWithoutDetaching([$creator->id]);
        $product = Product::factory()->create(['brand_id' => $this->run->brand_id]);

        Livewire::test(ShipmentsPanel::class, ['seedingCampaign' => $this->run->fresh()])
            ->call('create')
            ->set('shipment_creator_id', (string) $creator->id)
            ->set('shipment_product_id', (string) $product->id)
            ->set('shipment_status', ShipmentStatus::Delivered->value)
            ->set('shipment_tracking_number', 'TRK-1')
            ->set('shipment_shipped_at', '2026-07-01T10:00')
            ->set('shipment_delivered_at', '2026-07-03T10:00')
            ->set('shipment_status', ShipmentStatus::Pending->value)
            ->assertSet('shipment_tracking_number', '')
            ->assertSet('shipment_shipped_at', '')
            ->assertSet('shipment_delivered_at', '')
            ->call('save')
            ->assertHasNoErrors();

        $shipment = Shipment::query()->latest('id')->firstOrFail();
        $this->assertSame(ShipmentStatus::Pending, $shipment->status);
        $this->assertNull($shipment->tracking_number);
        $this->assertNull($shipment->shipped_at);
        $this->assertNull($shipment->delivered_at);
    }

    public function test_editing_a_delivered_shipment_shows_all_fields(): void
    {
        $this->actingAsCrmStaff();
        $shipment = Shipment::factory()->delivered()->create(['seeding_campaign_id' => $this->run->id]);

        Livewire::test(ShipmentsPanel::class, ['seedingCampaign' => $this->run->fresh()])
            ->call('edit', $shipment->id)
            ->assertSeeHtml('id="shipment_tracking_number"')
            ->assertSeeHtml('id="shipment_delivered_at"');
    }
}
