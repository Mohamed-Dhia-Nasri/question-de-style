<?php

namespace Tests\Feature\Crm;

use App\Modules\CRM\Models\Campaign;
use App\Modules\CRM\Models\Creator;
use App\Modules\CRM\Models\SeedingCampaign;
use App\Modules\CRM\Models\Shipment;
use App\Modules\Monitoring\Models\ContentItem;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The three M3 Step-1 pivots (campaign_creator, seeding_campaign_creator,
 * shipment_resulting_content — spec §2/D2) enforce composite uniqueness
 * and referential integrity on both sides.
 */
class CrmPivotIntegrityTest extends TestCase
{
    use RefreshDatabase;

    public function test_campaign_creators_attach_and_detach(): void
    {
        $campaign = Campaign::factory()->create();
        $creator = Creator::factory()->create();

        $campaign->creators()->attach($creator);
        $this->assertTrue($campaign->creators()->whereKey($creator->id)->exists());
        $this->assertTrue($creator->campaigns()->whereKey($campaign->id)->exists());

        $campaign->creators()->detach($creator);
        $this->assertSame(0, $campaign->creators()->count());
    }

    public function test_campaign_creator_rejects_duplicate_pair(): void
    {
        $campaign = Campaign::factory()->create();
        $creator = Creator::factory()->create();
        $campaign->creators()->attach($creator);

        $this->expectException(QueryException::class);

        DB::table('campaign_creator')->insert([
            'campaign_id' => $campaign->id,
            'creator_id' => $creator->id,
        ]);
    }

    public function test_campaign_creator_rejects_unknown_campaign(): void
    {
        $creator = Creator::factory()->create();

        $this->expectException(QueryException::class);

        DB::table('campaign_creator')->insert([
            'campaign_id' => 999_999,
            'creator_id' => $creator->id,
        ]);
    }

    public function test_campaign_creator_rows_die_with_the_campaign(): void
    {
        $campaign = Campaign::factory()->create();
        $campaign->creators()->attach(Creator::factory()->create());

        $campaign->delete();

        $this->assertDatabaseCount('campaign_creator', 0);
    }

    public function test_seeding_campaign_creators_attach(): void
    {
        $seedingCampaign = SeedingCampaign::factory()->create();
        $creator = Creator::factory()->create();

        $seedingCampaign->creators()->attach($creator);

        $this->assertTrue($seedingCampaign->creators()->whereKey($creator->id)->exists());
        $this->assertTrue($creator->seedingCampaigns()->whereKey($seedingCampaign->id)->exists());
    }

    public function test_seeding_campaign_creator_rejects_duplicate_pair(): void
    {
        $seedingCampaign = SeedingCampaign::factory()->create();
        $creator = Creator::factory()->create();
        $seedingCampaign->creators()->attach($creator);

        $this->expectException(QueryException::class);

        DB::table('seeding_campaign_creator')->insert([
            'seeding_campaign_id' => $seedingCampaign->id,
            'creator_id' => $creator->id,
        ]);
    }

    public function test_shipment_resulting_content_attach(): void
    {
        // REQ-M3-008 matching only WRITES these rows in Step 3; the join
        // itself must already hold referential integrity now (D2).
        $shipment = Shipment::factory()->create();
        $contentItem = ContentItem::factory()->create();

        $shipment->resultingContent()->attach($contentItem);

        $this->assertTrue($shipment->resultingContent()->whereKey($contentItem->id)->exists());
    }

    public function test_shipment_resulting_content_rejects_duplicate_pair(): void
    {
        $shipment = Shipment::factory()->create();
        $contentItem = ContentItem::factory()->create();
        $shipment->resultingContent()->attach($contentItem);

        $this->expectException(QueryException::class);

        DB::table('shipment_resulting_content')->insert([
            'shipment_id' => $shipment->id,
            'content_item_id' => $contentItem->id,
        ]);
    }

    public function test_shipment_resulting_content_rejects_unknown_content_item(): void
    {
        $shipment = Shipment::factory()->create();

        $this->expectException(QueryException::class);

        DB::table('shipment_resulting_content')->insert([
            'shipment_id' => $shipment->id,
            'content_item_id' => 999_999,
        ]);
    }
}
