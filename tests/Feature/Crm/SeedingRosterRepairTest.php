<?php

namespace Tests\Feature\Crm;

use App\Modules\CRM\Livewire\Seeding\SeedingCreatorsPanel;
use App\Modules\CRM\Livewire\Seeding\ShipmentsPanel;
use App\Modules\CRM\Models\Brand;
use App\Modules\CRM\Models\BrandPreference;
use App\Modules\CRM\Models\Creator;
use App\Modules\CRM\Models\Product;
use App\Modules\CRM\Models\SeedingCampaign;
use App\Modules\CRM\Models\Shipment;
use App\Shared\Enums\RoleName;
use App\Shared\Enums\ShipmentStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * F03 repair: shipments and the seeded-creators roster were disconnected
 * stores — demo-seeded shipments referenced creators that were never
 * attached to the run, making those shipments un-editable (the recipient
 * guard rejected them) and invisible in the recipient dropdown.
 */
class SeedingRosterRepairTest extends TestCase
{
    use RefreshDatabase;

    private SeedingCampaign $seeding;

    private Creator $creator;

    private Product $product;

    private function actingAsCrmStaff(): void
    {
        $this->seedRoles();
        $this->actingAs($this->makeUser(RoleName::InfluencerRelationsManager));
    }

    /** A run + creator + product WITHOUT attaching the creator to the roster. */
    private function makeRunWithOrphanShipment(): Shipment
    {
        $brand = Brand::factory()->create();
        $this->seeding = SeedingCampaign::factory()->create(['brand_id' => $brand->id]);
        $this->creator = Creator::factory()->create();
        $this->product = Product::factory()->create(['brand_id' => $brand->id]);

        return Shipment::factory()->create([
            'seeding_campaign_id' => $this->seeding->id,
            'creator_id' => $this->creator->id,
            'product_id' => $this->product->id,
            'status' => ShipmentStatus::Pending,
        ]);
    }

    public function test_editing_a_legacy_shipment_with_off_roster_creator_succeeds_and_attaches_the_creator(): void
    {
        $this->actingAsCrmStaff();
        $shipment = $this->makeRunWithOrphanShipment();

        $this->assertFalse($this->seeding->creators()->whereKey($this->creator->id)->exists());

        Livewire::test(ShipmentsPanel::class, ['seedingCampaign' => $this->seeding])
            ->call('edit', $shipment->id)
            ->set('shipment_status', ShipmentStatus::Shipped->value)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertTrue($this->seeding->creators()->whereKey($this->creator->id)->exists());
        $this->assertSame(ShipmentStatus::Shipped, $shipment->fresh()->status);
    }

    public function test_auto_attach_stamps_the_tenant_on_the_pivot_row(): void
    {
        $this->actingAsCrmStaff();
        $shipment = $this->makeRunWithOrphanShipment();

        Livewire::test(ShipmentsPanel::class, ['seedingCampaign' => $this->seeding])
            ->call('edit', $shipment->id)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('seeding_campaign_creator', [
            'seeding_campaign_id' => $this->seeding->id,
            'creator_id' => $this->creator->id,
            'tenant_id' => $this->seeding->tenant_id,
        ]);
    }

    public function test_auto_attach_still_blocks_restricted_creators(): void
    {
        $this->actingAsCrmStaff();
        $shipment = $this->makeRunWithOrphanShipment();

        BrandPreference::factory()->create([
            'creator_id' => $this->creator->id,
            'restricted_brands' => [$this->seeding->brand->name],
        ]);

        Livewire::test(ShipmentsPanel::class, ['seedingCampaign' => $this->seeding])
            ->call('edit', $shipment->id)
            ->call('save')
            ->assertHasErrors(['shipment_creator_id']);

        $this->assertFalse($this->seeding->creators()->whereKey($this->creator->id)->exists());
    }

    public function test_detaching_a_creator_with_shipments_on_the_run_is_blocked(): void
    {
        $this->actingAsCrmStaff();
        $this->makeRunWithOrphanShipment();
        $this->seeding->creators()->attach($this->creator->id);

        Livewire::test(SeedingCreatorsPanel::class, ['seedingCampaign' => $this->seeding])
            ->call('confirmDetach', $this->creator->id)
            ->call('detach')
            ->assertHasErrors(['detach']);

        $this->assertTrue($this->seeding->creators()->whereKey($this->creator->id)->exists());
    }

    public function test_failed_save_leaves_no_phantom_roster_attach(): void
    {
        $this->actingAsCrmStaff();
        $shipment = $this->makeRunWithOrphanShipment();

        $foreignProduct = Product::factory()->create(); // different brand

        Livewire::test(ShipmentsPanel::class, ['seedingCampaign' => $this->seeding])
            ->call('edit', $shipment->id)
            ->set('shipment_product_id', (string) $foreignProduct->id)
            ->call('save')
            ->assertHasErrors(['shipment_product_id']);

        $this->assertFalse($this->seeding->creators()->whereKey($this->creator->id)->exists());
    }

    public function test_backfill_migration_attaches_orphan_shipment_creators_and_is_idempotent(): void
    {
        $this->actingAsCrmStaff();
        $this->makeRunWithOrphanShipment();

        $migration = require database_path('migrations/2026_07_17_100001_backfill_seeding_campaign_creator_from_shipments.php');
        $migration->up();
        $migration->up(); // idempotent — insertOrIgnore on the unique pair

        $this->assertDatabaseHas('seeding_campaign_creator', [
            'seeding_campaign_id' => $this->seeding->id,
            'creator_id' => $this->creator->id,
            'tenant_id' => $this->seeding->tenant_id,
        ]);
        $this->assertSame(1, DB::table('seeding_campaign_creator')
            ->where('seeding_campaign_id', $this->seeding->id)
            ->where('creator_id', $this->creator->id)
            ->count());
    }
}
