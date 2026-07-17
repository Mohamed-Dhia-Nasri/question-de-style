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

/**
 * Task 11 (§2.5, item 6c): the shipment form offers a SOFT one-tap nudge to
 * lift the status when a shipped/delivered date is entered ahead of it. The
 * nudge never fires automatically — the operator taps it, then submits the
 * form through the ordinary save(). The Stage C progressive-form visibility
 * (showsTrackingFields/showsDeliveryFields) stays intact.
 */
class ShipmentStatusHintTest extends TestCase
{
    use RefreshDatabase;

    private SeedingCampaign $run;

    private function actingAsCrmStaff(): void
    {
        $this->seedRoles();
        $this->actingAs($this->makeUser(RoleName::InfluencerRelationsManager));
        $this->run = SeedingCampaign::factory()->create();
    }

    public function test_a_shipped_date_on_a_pending_shipment_surfaces_a_shipped_hint(): void
    {
        $this->actingAsCrmStaff();

        $component = Livewire::test(ShipmentsPanel::class, ['seedingCampaign' => $this->run])
            ->call('create')                        // status = PENDING
            ->set('shipment_shipped_at', '2026-07-01T10:00');

        $this->assertSame(
            ['status' => ShipmentStatus::Shipped->value, 'label' => 'Shipped'],
            $component->instance()->statusHint(),
        );

        $component->assertSee('Set status to Shipped?');
    }

    public function test_a_delivered_date_below_delivered_surfaces_a_delivered_hint(): void
    {
        $this->actingAsCrmStaff();

        // Status is set to a below-Delivered stage FIRST, then the delivered
        // date is entered — so the progressive form's status hook does not
        // clear it. The date now sits ahead of the status.
        $component = Livewire::test(ShipmentsPanel::class, ['seedingCampaign' => $this->run])
            ->call('create')
            ->set('shipment_status', ShipmentStatus::InTransit->value)
            ->set('shipment_delivered_at', '2026-07-03T10:00');

        $this->assertSame(
            ['status' => ShipmentStatus::Delivered->value, 'label' => 'Delivered'],
            $component->instance()->statusHint(),
        );

        $component->assertSee('Set status to Delivered?');
    }

    public function test_accepting_the_hint_sets_the_status_without_saving(): void
    {
        $this->actingAsCrmStaff();

        Livewire::test(ShipmentsPanel::class, ['seedingCampaign' => $this->run])
            ->call('create')
            ->set('shipment_shipped_at', '2026-07-01T10:00')
            ->call('acceptStatusHint', ShipmentStatus::Shipped->value)
            ->assertSet('shipment_status', ShipmentStatus::Shipped->value)
            // The form stays open — accepting only mutates the form prop.
            ->assertSet('showForm', true);

        // Nothing persisted: the operator still submits via save().
        $this->assertSame(0, Shipment::query()->count());
    }

    public function test_accepting_the_hint_then_saving_persists_the_status(): void
    {
        $this->actingAsCrmStaff();

        $creator = Creator::factory()->create();
        $this->run->creators()->syncWithoutDetaching([$creator->id]);
        $product = Product::factory()->create(['brand_id' => $this->run->brand_id]);

        Livewire::test(ShipmentsPanel::class, ['seedingCampaign' => $this->run->fresh()])
            ->call('create')
            ->set('shipment_creator_id', (string) $creator->id)
            ->set('shipment_product_id', (string) $product->id)
            ->set('shipment_shipped_at', '2026-07-01T10:00')
            ->call('acceptStatusHint', ShipmentStatus::Shipped->value)
            ->call('save')
            ->assertHasNoErrors();

        $shipment = Shipment::query()->latest('id')->firstOrFail();
        $this->assertSame(ShipmentStatus::Shipped, $shipment->status);
        $this->assertNotNull($shipment->shipped_at);
    }

    public function test_accepting_an_invalid_status_is_ignored(): void
    {
        $this->actingAsCrmStaff();

        Livewire::test(ShipmentsPanel::class, ['seedingCampaign' => $this->run])
            ->call('create')                        // status = PENDING
            ->call('acceptStatusHint', 'NONSENSE')
            ->assertSet('shipment_status', ShipmentStatus::Pending->value);
    }

    public function test_no_hint_when_status_already_shipped(): void
    {
        $this->actingAsCrmStaff();

        $component = Livewire::test(ShipmentsPanel::class, ['seedingCampaign' => $this->run])
            ->call('create')
            ->set('shipment_status', ShipmentStatus::Shipped->value)
            ->set('shipment_shipped_at', '2026-07-01T10:00');

        $this->assertNull($component->instance()->statusHint());
        $component->assertDontSee('Set status to Shipped?');
    }

    public function test_no_hint_when_dates_are_empty(): void
    {
        $this->actingAsCrmStaff();

        $component = Livewire::test(ShipmentsPanel::class, ['seedingCampaign' => $this->run])
            ->call('create');                       // PENDING, no dates

        $this->assertNull($component->instance()->statusHint());
    }
}
