<?php

namespace Tests\Feature\Crm;

use App\Modules\CRM\Models\Brand;
use App\Modules\CRM\Models\Creator;
use App\Modules\CRM\Models\Product;
use App\Modules\CRM\Models\SeedingCampaign;
use App\Modules\CRM\Models\Shipment;
use App\Shared\Enums\RoleName;
use App\Shared\Enums\ShipmentStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Item 6a (Stage D task 9): a read-only progress strip on the seeding-run
 * detail page. Counts are computed live from the Shipment table via
 * loadCount on the route closure — never from rollups, which lag. There is
 * no page-owning Livewire component here (seeding.show is a route
 * closure), so the strip is plain Blade with no wire:click.
 */
class SeedingProgressStripTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsCrmStaff(): void
    {
        $this->seedRoles();
        $this->actingAs($this->makeUser(RoleName::InfluencerRelationsManager));
    }

    public function test_the_strip_shows_live_counts_from_mixed_shipments(): void
    {
        $this->actingAsCrmStaff();

        $brand = Brand::factory()->create();
        $run = SeedingCampaign::factory()->create(['brand_id' => $brand->id]);
        $product = Product::factory()->create(['brand_id' => $brand->id]);
        $c1 = Creator::factory()->create();
        $c2 = Creator::factory()->create();
        $c3 = Creator::factory()->create();
        $run->creators()->attach([$c1->id, $c2->id, $c3->id]);

        // Handed to the courier, not yet delivered, not posted.
        Shipment::factory()->create([
            'seeding_campaign_id' => $run->id,
            'creator_id' => $c1->id,
            'product_id' => $product->id,
            'status' => ShipmentStatus::Shipped,
            'shipped_at' => now()->subDay(),
            'posting_required' => true,
            'posted' => false,
        ]);

        // Delivered and already posted about.
        Shipment::factory()->create([
            'seeding_campaign_id' => $run->id,
            'creator_id' => $c2->id,
            'product_id' => $product->id,
            'status' => ShipmentStatus::Delivered,
            'shipped_at' => now()->subDays(3),
            'delivered_at' => now()->subDay(),
            'posting_required' => true,
            'posted' => true,
        ]);

        // Still pending — never shipped, never delivered, never posted.
        Shipment::factory()->create([
            'seeding_campaign_id' => $run->id,
            'creator_id' => $c3->id,
            'product_id' => $product->id,
            'status' => ShipmentStatus::Pending,
            'posting_required' => true,
            'posted' => false,
        ]);

        $this->get(route('crm.seeding.show', $run))
            ->assertOk()
            ->assertSee('Roster 3')
            ->assertSee('Shipped 2/3')
            ->assertSee('Delivered 1/3')
            ->assertSee('Posted 1/3')
            ->assertSee('The Posted count goes up once a creator’s post is matched to this run.');
    }

    public function test_a_returned_parcel_is_not_counted_as_shipped_or_delivered(): void
    {
        $this->actingAsCrmStaff();

        $brand = Brand::factory()->create();
        $run = SeedingCampaign::factory()->create(['brand_id' => $brand->id]);
        $product = Product::factory()->create(['brand_id' => $brand->id]);
        $creator = Creator::factory()->create();
        $run->creators()->attach([$creator->id]);

        // Delivered, then returned — the edit form preserves shipped_at and
        // delivered_at on a Returned parcel, so a timestamp-based count would
        // wrongly tally it. Counting strictly by status must not (M07).
        Shipment::factory()->create([
            'seeding_campaign_id' => $run->id,
            'creator_id' => $creator->id,
            'product_id' => $product->id,
            'status' => ShipmentStatus::Returned,
            'shipped_at' => now()->subDays(3),
            'delivered_at' => now()->subDay(),
        ]);

        $this->get(route('crm.seeding.show', $run))
            ->assertOk()
            ->assertSee('Shipped 0/1')
            ->assertSee('Delivered 0/1');
    }

    public function test_the_strip_does_not_render_for_a_run_with_zero_shipments(): void
    {
        $this->actingAsCrmStaff();

        $run = SeedingCampaign::factory()->create();

        $this->get(route('crm.seeding.show', $run))
            ->assertOk()
            ->assertDontSee('The Posted count goes up once a creator’s post is matched to this run.');
    }

    public function test_a_run_that_requires_no_posts_shows_posted_zero_of_zero(): void
    {
        $this->actingAsCrmStaff();

        $brand = Brand::factory()->create();
        $run = SeedingCampaign::factory()->create(['brand_id' => $brand->id]);
        $product = Product::factory()->create(['brand_id' => $brand->id]);

        // A gifting run where no post is expected: every parcel is
        // posting_required = false. "Posted" then has a zero denominator —
        // it must read 0/0, never 0/N (which would overstate outstanding work).
        Shipment::factory()->count(3)->create([
            'seeding_campaign_id' => $run->id,
            'product_id' => $product->id,
            'status' => ShipmentStatus::Delivered,
            'posting_required' => false,
            'posted' => false,
        ]);

        $this->get(route('crm.seeding.show', $run))
            ->assertOk()
            ->assertSee('Posted 0/0')
            ->assertDontSee('Posted 0/3');
    }
}
