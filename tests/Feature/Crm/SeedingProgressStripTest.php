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
 * Item 6a (Stage D task 9): the read-only run-progress pipeline on the
 * seeding-run detail page. Counts are computed live from the Shipment table via
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
            // The pipeline card labels each stage and shows its count.
            ->assertSee('Run progress')
            ->assertSee('Creators')
            ->assertSee('on the roster')
            ->assertSee('Shipped')
            ->assertSee('2 of 3')   // shipped 2 of 3
            ->assertSee('Delivered')
            ->assertSee('1 of 3')   // delivered 1 of 3 (posted is also 1 of 3)
            ->assertSee('Posted')
            ->assertSee('Posted rises as each creator’s post is matched to this run.');
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
            ->assertSee('Shipped')
            ->assertSee('Delivered')
            ->assertSee('0 of 1'); // shipped and delivered both 0 of 1 (Returned counts as neither)
    }

    public function test_the_strip_does_not_render_for_a_run_with_zero_shipments(): void
    {
        $this->actingAsCrmStaff();

        $run = SeedingCampaign::factory()->create();

        $this->get(route('crm.seeding.show', $run))
            ->assertOk()
            // No creators and no shipments → the pipeline card is not rendered.
            ->assertDontSee('Run progress');
    }

    public function test_a_run_that_requires_no_posts_shows_a_plain_posted_count(): void
    {
        $this->actingAsCrmStaff();

        $brand = Brand::factory()->create();
        $run = SeedingCampaign::factory()->create(['brand_id' => $brand->id]);
        $product = Product::factory()->create(['brand_id' => $brand->id]);

        // A gifting run where no post is expected: every parcel is
        // posting_required = false. "Posted" has a zero denominator, so it must
        // show a plain count with "no posts required" — never a fraction.
        Shipment::factory()->count(3)->create([
            'seeding_campaign_id' => $run->id,
            'product_id' => $product->id,
            'status' => ShipmentStatus::Delivered,
            'posting_required' => false,
            'posted' => false,
        ]);

        $this->get(route('crm.seeding.show', $run))
            ->assertOk()
            ->assertSee('Posted')
            ->assertSee('no posts required')
            ->assertDontSee('0 of 0')   // no fraction at all when nothing is required…
            ->assertDontSee('0 of 3');  // …and never 0 of N, which would overstate outstanding work
    }

    public function test_a_post_on_a_no_post_run_shows_a_plain_count_not_one_over_zero(): void
    {
        $this->actingAsCrmStaff();

        $brand = Brand::factory()->create();
        $run = SeedingCampaign::factory()->create(['brand_id' => $brand->id]);
        $product = Product::factory()->create(['brand_id' => $brand->id]);
        $creator = Creator::factory()->create();
        $run->creators()->attach([$creator->id]);

        // No post required, but the creator posted anyway (run #1's situation).
        // "Posted" must read a plain "1 — no posts required", never "1/0".
        Shipment::factory()->create([
            'seeding_campaign_id' => $run->id,
            'creator_id' => $creator->id,
            'product_id' => $product->id,
            'status' => ShipmentStatus::Delivered,
            'posting_required' => false,
            'posted' => true,
        ]);

        $this->get(route('crm.seeding.show', $run))
            ->assertOk()
            ->assertSee('Posted')
            ->assertSee('no posts required')
            ->assertDontSee('1 of 0'); // the confusing fraction must never render
    }
}
