<?php

namespace Tests\Feature\Enrichment;

use App\Modules\CRM\Models\Brand;
use App\Modules\CRM\Models\Campaign;
use App\Modules\CRM\Models\Creator;
use App\Modules\CRM\Models\PlatformAccount;
use App\Modules\CRM\Models\Product;
use App\Modules\CRM\Models\SeedingCampaign;
use App\Modules\CRM\Models\Shipment;
use App\Modules\CRM\Services\ShipmentEvidenceSource;
use App\Modules\Monitoring\Models\ContentItem;
use App\Modules\Monitoring\Models\MonitoredSubject;
use App\Modules\Monitoring\Models\RecognitionDetection;
use App\Modules\Monitoring\Models\Story;
use App\Platform\Enrichment\Attribution\AttributionService;
use App\Platform\Enrichment\Contracts\SeedingEvidenceSource;
use App\Shared\Enums\MentionType;
use App\Shared\Enums\Platform;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * M3's real SeedingEvidenceSource (Step 3): the P1 Null binding is replaced,
 * dispatched shipments become attribution evidence (AC-M1-003/020), and the
 * end-to-end AC-M1-020 path — shipment + recognition → SEEDED mention with
 * the shipment reference in the signals — activates.
 */
class ShipmentEvidenceSourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_container_resolves_the_real_m3_evidence_source(): void
    {
        $this->assertInstanceOf(ShipmentEvidenceSource::class, app(SeedingEvidenceSource::class));
    }

    public function test_dispatched_shipments_become_evidence_with_brand_product_and_campaign(): void
    {
        $creator = Creator::factory()->create();
        $account = PlatformAccount::factory()->forCreator($creator)->create();
        $content = ContentItem::factory()->create(['platform_account_id' => $account->id]);

        $brand = Brand::factory()->create(['name' => 'Maison Lumière']);
        $campaign = Campaign::factory()->create(['brand_id' => $brand->id]);
        $product = Product::factory()->create(['brand_id' => $brand->id, 'name' => 'Sérum No. 5']);
        $seeding = SeedingCampaign::factory()->create([
            'brand_id' => $brand->id,
            'campaign_id' => $campaign->id,
        ]);
        $shipment = Shipment::factory()->create([
            'seeding_campaign_id' => $seeding->id,
            'creator_id' => $creator->id,
            'product_id' => $product->id,
            'shipped_at' => CarbonImmutable::parse('2026-06-01 10:00:00'),
            'delivered_at' => CarbonImmutable::parse('2026-06-03 10:00:00'),
        ]);

        $evidence = app(SeedingEvidenceSource::class)->forTarget($content);

        $this->assertCount(1, $evidence);
        $this->assertSame('shipment-record:'.$shipment->id, $evidence[0]->reference);
        $this->assertSame($brand->id, $evidence[0]->brandId);
        $this->assertSame('Maison Lumière', $evidence[0]->brandName);
        $this->assertSame('Sérum No. 5', $evidence[0]->productLabel);
        $this->assertSame($campaign->id, $evidence[0]->campaignId);
        $this->assertTrue($evidence[0]->shippedAt->equalTo('2026-06-01 10:00:00'));
        $this->assertTrue($evidence[0]->deliveredAt->equalTo('2026-06-03 10:00:00'));
    }

    public function test_unshipped_shipments_are_not_evidence(): void
    {
        $creator = Creator::factory()->create();
        $account = PlatformAccount::factory()->forCreator($creator)->create();
        $content = ContentItem::factory()->create(['platform_account_id' => $account->id]);

        // Spec D1: a pending shipment (shipped_at null) cannot have caused content.
        Shipment::factory()->create(['creator_id' => $creator->id, 'shipped_at' => null]);

        $this->assertSame([], app(SeedingEvidenceSource::class)->forTarget($content));
    }

    public function test_other_creators_shipments_are_not_evidence(): void
    {
        $creator = Creator::factory()->create();
        $account = PlatformAccount::factory()->forCreator($creator)->create();
        $content = ContentItem::factory()->create(['platform_account_id' => $account->id]);

        Shipment::factory()->create([
            'creator_id' => Creator::factory()->create()->id,
            'shipped_at' => now()->subDays(3),
        ]);

        $this->assertSame([], app(SeedingEvidenceSource::class)->forTarget($content));
    }

    public function test_story_targets_resolve_evidence_through_their_account(): void
    {
        $creator = Creator::factory()->create();
        $account = PlatformAccount::factory()->forCreator($creator)->create();
        $story = Story::factory()->create(['platform_account_id' => $account->id]);

        Shipment::factory()->create(['creator_id' => $creator->id, 'shipped_at' => now()->subDays(2)]);

        $this->assertCount(1, app(SeedingEvidenceSource::class)->forTarget($story));
    }

    public function test_end_to_end_a_dispatched_shipment_plus_recognition_yields_a_seeded_mention(): void
    {
        // AC-M1-020, now live: tracked creator + shipment + brand recognition
        // on their new content → SEEDED mention carrying the shipment
        // reference as the proving record (AC-M1-003).
        $creator = Creator::factory()->create();
        $account = PlatformAccount::factory()->forCreator($creator)->onPlatform(Platform::Instagram)->create();
        MonitoredSubject::factory()->create([
            'creator_id' => $creator->id,
            'platforms' => [Platform::Instagram],
            'active' => true,
        ]);

        $brand = Brand::factory()->create(['name' => 'Maison Lumière']);
        $seeding = SeedingCampaign::factory()->create(['brand_id' => $brand->id]);
        $shipment = Shipment::factory()->create([
            'seeding_campaign_id' => $seeding->id,
            'creator_id' => $creator->id,
            'product_id' => Product::factory()->create(['brand_id' => $brand->id])->id,
            'shipped_at' => CarbonImmutable::parse('2026-06-01 10:00:00'),
        ]);

        $content = ContentItem::factory()->create([
            'platform_account_id' => $account->id,
            'platform' => Platform::Instagram,
            'published_at' => CarbonImmutable::parse('2026-06-10 12:00:00'),
        ]);
        RecognitionDetection::factory()->create([
            'content_item_id' => $content->id,
            'detected_brand' => 'Maison Lumière',
            'provider_label' => 'Maison Lumière',
        ]);

        $mentions = app(AttributionService::class)->enrich($content);

        $this->assertCount(1, $mentions);
        $this->assertSame(MentionType::Seeded, $mentions[0]->mention_type);
        $this->assertContains('shipment-record:'.$shipment->id, $mentions[0]->classification->signals);
    }
}
