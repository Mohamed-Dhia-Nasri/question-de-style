<?php

namespace Tests\Feature\Enrichment;

use App\Modules\CRM\Models\Creator;
use App\Modules\CRM\Models\PlatformAccount;
use App\Modules\CRM\Models\Product;
use App\Modules\CRM\Models\ProductReferencePhoto;
use App\Modules\CRM\Models\SeedingCampaign;
use App\Modules\CRM\Models\Shipment;
use App\Modules\Monitoring\Models\ContentItem;
use App\Modules\Monitoring\Models\MonitoringSetting;
use App\Modules\Monitoring\Models\ProductPhotoEmbedding;
use App\Modules\Monitoring\Models\Story;
use App\Platform\AiBudget\Priority;
use App\Platform\Enrichment\VisualMatch\Candidates\Candidate;
use App\Platform\Enrichment\VisualMatch\Candidates\CandidateScope;
use App\Platform\Enrichment\VisualMatch\Candidates\CandidateSet;
use App\Shared\Enums\SectorLabel;
use App\Shared\Enums\SeedingCampaignStatus;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Spec §7: candidates = products of in-window shipments (byte-identical
 * window semantics to attribution, ADR-0025 per-tenant) ∪ primary products
 * of ACTIVE/SHIPPING roster campaigns. Empty set ⇒ the post costs nothing
 * — this tiering is what makes most posts free.
 */
class CandidateScopeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['qds.enrichment.visual_match.model_version' => 'gemini-embedding-2']);
    }

    /** @return array{0: Creator, 1: ContentItem} */
    private function makeCreatorWithPost(CarbonImmutable $publishedAt): array
    {
        $creator = Creator::factory()->create();
        $account = PlatformAccount::factory()->forCreator($creator)->create();
        $item = ContentItem::factory()->for($account, 'platformAccount')->create(['published_at' => $publishedAt]);

        return [$creator, $item];
    }

    private function makeProduct(string $name, ?SectorLabel $category = SectorLabel::Beauty): Product
    {
        return Product::factory()->create(['name' => $name, 'category' => $category]);
    }

    private function makeShipment(
        Creator $creator,
        Product $product,
        ?CarbonImmutable $shippedAt,
        ?CarbonImmutable $deliveredAt = null,
        SeedingCampaignStatus $campaignStatus = SeedingCampaignStatus::Completed,
    ): Shipment {
        $campaign = SeedingCampaign::factory()->create([
            'brand_id' => $product->brand_id,
            'status' => $campaignStatus,
        ]);

        return Shipment::factory()->create([
            'seeding_campaign_id' => $campaign->id,
            'creator_id' => $creator->id,
            'product_id' => $product->id,
            'shipped_at' => $shippedAt,
            'delivered_at' => $deliveredAt,
        ]);
    }

    private function makeRosterCampaign(Creator $creator, Product $product, SeedingCampaignStatus $status): SeedingCampaign
    {
        $campaign = SeedingCampaign::factory()->create([
            'brand_id' => $product->brand_id,
            'product_id' => $product->id,
            'status' => $status,
        ]);
        $campaign->creators()->attach($creator->id);

        return $campaign;
    }

    private function embedPhoto(Product $product, string $modelVersion = 'gemini-embedding-2'): void
    {
        $photo = ProductReferencePhoto::factory()->create(['product_id' => $product->id]);
        ProductPhotoEmbedding::factory()->create([
            'product_reference_photo_id' => $photo->id,
            'model_version' => $modelVersion,
        ]);
    }

    private function scope(): CandidateScope
    {
        return app(CandidateScope::class);
    }

    public function test_in_window_shipment_products_become_candidates_with_evidence(): void
    {
        $publishedAt = CarbonImmutable::parse('2026-07-10 12:00:00');
        [$creator, $item] = $this->makeCreatorWithPost($publishedAt);
        $product = $this->makeProduct('Nexon Headset', SectorLabel::Tech);
        $shipment = $this->makeShipment($creator, $product, $publishedAt->subDays(10), $publishedAt->subDays(8));

        $set = $this->scope()->forTarget($item);

        $this->assertCount(1, $set->candidates);
        $candidate = $set->candidates[0];
        $this->assertSame($product->id, $candidate->productId);
        $this->assertSame('Nexon Headset', $candidate->productLabel);
        $this->assertSame($product->brand->name, $candidate->brandName);
        $this->assertSame(SectorLabel::Tech, $candidate->category);
        $this->assertSame('shipment', $candidate->source);
        $this->assertTrue($candidate->shipmentInWindow);
        $this->assertSame($shipment->seeding_campaign_id, $candidate->seedingCampaignId);
        $this->assertTrue($candidate->shipmentAnchorAt !== null && $candidate->shipmentAnchorAt->equalTo($publishedAt->subDays(8)));
        $this->assertSame(8, $candidate->shipmentAgeDays);
        $this->assertFalse($candidate->hasEmbeddedPhotos);
        $this->assertTrue($set->hasInWindowShipment());
        $this->assertFalse($set->isEmpty());
    }

    public function test_the_anchor_falls_back_to_shipped_at_when_undelivered(): void
    {
        $publishedAt = CarbonImmutable::parse('2026-07-10 12:00:00');
        [$creator, $item] = $this->makeCreatorWithPost($publishedAt);
        $this->makeShipment($creator, $this->makeProduct('Silk Scarf'), $publishedAt->subDays(12), null);

        $set = $this->scope()->forTarget($item);

        $this->assertCount(1, $set->candidates);
        $anchor = $set->candidates[0]->shipmentAnchorAt;
        $this->assertTrue($anchor !== null && $anchor->equalTo($publishedAt->subDays(12)));
        $this->assertSame(12, $set->candidates[0]->shipmentAgeDays);
    }

    public function test_window_edges_match_the_classifier_semantics(): void
    {
        $anchor = CarbonImmutable::parse('2026-05-01 00:00:00');
        $creator = Creator::factory()->create();
        $account = PlatformAccount::factory()->forCreator($creator)->create();
        $this->makeShipment($creator, $this->makeProduct('Edge Product'), $anchor->subDay(), $anchor);

        $postAt = fn (CarbonImmutable $at): ContentItem => ContentItem::factory()
            ->for($account, 'platformAccount')
            ->create(['published_at' => $at]);

        // Default window 60 days, BOTH edges inclusive (MentionClassifier parity).
        $this->assertCount(1, $this->scope()->forTarget($postAt($anchor))->candidates);
        $this->assertCount(1, $this->scope()->forTarget($postAt($anchor->addDays(60)))->candidates);
        $this->assertCount(0, $this->scope()->forTarget($postAt($anchor->addDays(60)->addSecond()))->candidates);
        $this->assertCount(0, $this->scope()->forTarget($postAt($anchor->subSecond()))->candidates);
    }

    public function test_the_per_tenant_window_setting_applies(): void
    {
        $publishedAt = CarbonImmutable::parse('2026-07-10 12:00:00');
        [$creator, $item] = $this->makeCreatorWithPost($publishedAt);
        $this->makeShipment($creator, $this->makeProduct('Short Window'), $publishedAt->subDays(6), $publishedAt->subDays(5));

        MonitoringSetting::query()->create([
            'shipment_window_days' => 3, // anchor 5 days back → outside
            'engagement_trend_window_days' => 30,
            'story_retention_days' => 180,
            'communication_retention_days' => 0,
        ]);

        $this->assertTrue($this->scope()->forTarget($item)->isEmpty());
    }

    public function test_unshipped_and_out_of_window_shipments_are_never_candidates(): void
    {
        $publishedAt = CarbonImmutable::parse('2026-07-10 12:00:00');
        [$creator, $item] = $this->makeCreatorWithPost($publishedAt);
        $this->makeShipment($creator, $this->makeProduct('Unshipped'), null);
        $this->makeShipment($creator, $this->makeProduct('Ancient'), $publishedAt->subDays(200), $publishedAt->subDays(190));

        $set = $this->scope()->forTarget($item);

        $this->assertTrue($set->isEmpty());
        $this->assertFalse($set->hasInWindowShipment());
    }

    public function test_roster_primaries_of_active_and_shipping_campaigns_are_candidates(): void
    {
        [$creator, $item] = $this->makeCreatorWithPost(CarbonImmutable::parse('2026-07-10 12:00:00'));

        $active = $this->makeProduct('Active Primary');
        $shipping = $this->makeProduct('Shipping Primary');
        $this->makeRosterCampaign($creator, $active, SeedingCampaignStatus::Active);
        $this->makeRosterCampaign($creator, $shipping, SeedingCampaignStatus::Shipping);
        // Not candidates: wrong status, other creator's roster, no primary product.
        $this->makeRosterCampaign($creator, $this->makeProduct('Draft Primary'), SeedingCampaignStatus::Draft);
        $this->makeRosterCampaign(Creator::factory()->create(), $this->makeProduct('Off Roster'), SeedingCampaignStatus::Active);
        $productless = SeedingCampaign::factory()->create(['status' => SeedingCampaignStatus::Active]);
        $productless->creators()->attach($creator->id);

        $set = $this->scope()->forTarget($item);

        $this->assertSame(
            [$active->id, $shipping->id],
            array_map(fn (Candidate $c): int => $c->productId, $set->candidates),
        );
        $this->assertSame('roster', $set->candidates[0]->source);
        $this->assertFalse($set->candidates[0]->shipmentInWindow);
        $this->assertNull($set->candidates[0]->shipmentAnchorAt);
        $this->assertNull($set->candidates[0]->shipmentAgeDays);
        $this->assertNotNull($set->candidates[0]->seedingCampaignId);
        $this->assertFalse($set->hasInWindowShipment());
        $this->assertSame(Priority::High, $set->priority);
    }

    public function test_a_product_seen_via_both_sources_is_one_shipment_candidate(): void
    {
        $publishedAt = CarbonImmutable::parse('2026-07-10 12:00:00');
        [$creator, $item] = $this->makeCreatorWithPost($publishedAt);
        $product = $this->makeProduct('Both Sources');
        $this->makeShipment($creator, $product, $publishedAt->subDays(9), $publishedAt->subDays(7), SeedingCampaignStatus::Active);
        $this->makeRosterCampaign($creator, $product, SeedingCampaignStatus::Active);

        $set = $this->scope()->forTarget($item);

        $this->assertCount(1, $set->candidates);
        $this->assertSame('shipment', $set->candidates[0]->source);
        $this->assertTrue($set->candidates[0]->shipmentInWindow);
    }

    public function test_multiple_shipments_of_one_product_keep_the_freshest_anchor(): void
    {
        $publishedAt = CarbonImmutable::parse('2026-07-10 12:00:00');
        [$creator, $item] = $this->makeCreatorWithPost($publishedAt);
        $product = $this->makeProduct('Restocked');
        $this->makeShipment($creator, $product, $publishedAt->subDays(30), $publishedAt->subDays(28));
        $this->makeShipment($creator, $product, $publishedAt->subDays(6), $publishedAt->subDays(4));

        $set = $this->scope()->forTarget($item);

        $this->assertCount(1, $set->candidates);
        $this->assertSame(4, $set->candidates[0]->shipmentAgeDays);
    }

    public function test_priority_is_high_only_with_an_active_campaign_link(): void
    {
        $publishedAt = CarbonImmutable::parse('2026-07-10 12:00:00');
        [$creator, $item] = $this->makeCreatorWithPost($publishedAt);
        $this->makeShipment($creator, $this->makeProduct('Old Gift'), $publishedAt->subDays(9), $publishedAt->subDays(7), SeedingCampaignStatus::Completed);

        // Shipment outside an active campaign → medium.
        $this->assertSame(Priority::Medium, $this->scope()->forTarget($item)->priority);

        // An ACTIVE-campaign shipment lifts the whole run to high.
        $this->makeShipment($creator, $this->makeProduct('Fresh Gift'), $publishedAt->subDays(3), $publishedAt->subDays(2), SeedingCampaignStatus::Active);

        $this->assertSame(Priority::High, $this->scope()->forTarget($item)->priority);
    }

    public function test_only_products_with_embedded_photos_are_matchable(): void
    {
        $publishedAt = CarbonImmutable::parse('2026-07-10 12:00:00');
        [$creator, $item] = $this->makeCreatorWithPost($publishedAt);

        $embedded = $this->makeProduct('Embedded');
        $photoOnly = $this->makeProduct('Photo Only');
        $staleModel = $this->makeProduct('Stale Model');

        foreach ([$embedded, $photoOnly, $staleModel] as $product) {
            $this->makeShipment($creator, $product, $publishedAt->subDays(9), $publishedAt->subDays(7));
        }

        $this->embedPhoto($embedded);
        ProductReferencePhoto::factory()->create(['product_id' => $photoOnly->id]);
        $this->embedPhoto($staleModel, 'some-older-model');

        $set = $this->scope()->forTarget($item);

        // Unmatchable candidates stay recorded (coverage) but cost nothing.
        $this->assertCount(3, $set->candidates);
        $this->assertSame([$embedded->id], array_map(fn (Candidate $c): int => $c->productId, $set->matchable()));
        $this->assertTrue($set->candidates[0]->hasEmbeddedPhotos);
    }

    public function test_an_unresolvable_creator_yields_an_empty_set(): void
    {
        $account = PlatformAccount::factory()->create(); // creator_id stays null
        $item = ContentItem::factory()->for($account, 'platformAccount')->create();

        $set = $this->scope()->forTarget($item);

        $this->assertTrue($set->isEmpty());
        $this->assertSame([], $set->matchable());
    }

    public function test_stories_scope_on_captured_at(): void
    {
        $capturedAt = CarbonImmutable::parse('2026-07-10 09:00:00');
        $creator = Creator::factory()->create();
        $account = PlatformAccount::factory()->forCreator($creator)->create();
        $story = Story::factory()->for($account, 'platformAccount')->create([
            'captured_at' => $capturedAt,
            'expires_at' => $capturedAt->addDay(),
        ]);
        $this->makeShipment($creator, $this->makeProduct('Story Gift'), $capturedAt->subDays(4), $capturedAt->subDays(3));

        $set = $this->scope()->forTarget($story);

        $this->assertCount(1, $set->candidates);
        $this->assertSame(3, $set->candidates[0]->shipmentAgeDays);
    }

    public function test_candidates_are_tenant_isolated(): void
    {
        [$tenantA, $tenantB] = $this->makeTenantPair();
        $publishedAt = CarbonImmutable::parse('2026-07-10 12:00:00');

        $postA = $this->withTenant($tenantA, function () use ($publishedAt): ContentItem {
            [$creator, $item] = $this->makeCreatorWithPost($publishedAt);
            $this->makeShipment($creator, $this->makeProduct('Product A'), $publishedAt->subDays(10), $publishedAt->subDays(8));

            return $item;
        });

        $this->withTenant($tenantB, function () use ($publishedAt): void {
            [$creator] = $this->makeCreatorWithPost($publishedAt);
            $this->makeShipment($creator, $this->makeProduct('Product B'), $publishedAt->subDays(10), $publishedAt->subDays(8));
        });

        $set = $this->withTenant($tenantA, fn (): CandidateSet => $this->scope()->forTarget($postA));

        $this->assertCount(1, $set->candidates);
        $this->assertSame('Product A', $set->candidates[0]->productLabel);
    }
}
