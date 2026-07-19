<?php

namespace Tests\Feature\Enrichment;

use App\Modules\CRM\Models\Brand;
use App\Modules\CRM\Models\Campaign;
use App\Modules\CRM\Models\Creator;
use App\Modules\CRM\Models\PlatformAccount;
use App\Modules\CRM\Models\Product;
use App\Modules\CRM\Models\SeedingCampaign;
use App\Modules\CRM\Models\Shipment;
use App\Modules\Monitoring\Models\ContentItem;
use App\Modules\Monitoring\Models\Mention;
use App\Modules\Monitoring\Models\MonitoredSubject;
use App\Modules\Monitoring\Models\RecognitionDetection;
use App\Platform\Enrichment\Attribution\AttributionService;
use App\Shared\Enums\ConfidenceLevel;
use App\Shared\Enums\MentionType;
use App\Shared\Enums\Platform;
use App\Shared\Enums\RecognitionType;
use App\Shared\Enums\VerificationStatus;
use App\Shared\ValueObjects\ConfidenceAssessment;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AttributionProductEvidenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_product_tag_detection_drives_high_seeded_with_shipment(): void
    {
        // The product-aware doctrine only applies when the text_signals
        // kill switch is ON (Task 16: OFF = legacy behaviour, ON = new
        // product-aware behaviour).
        config(['qds.enrichment.text_signals.enabled' => true]);

        // creator → account → active Instagram subject → in-window content
        // (wiring mirrors AttributionTest.php / ShipmentEvidenceSourceTest.php).
        $creator = Creator::factory()->create();
        $account = PlatformAccount::factory()->forCreator($creator)->onPlatform(Platform::Instagram)->create();
        MonitoredSubject::factory()->create([
            'creator_id' => $creator->id,
            'platforms' => [Platform::Instagram],
            'active' => true,
        ]);
        $content = ContentItem::factory()->create([
            'platform_account_id' => $account->id,
            'platform' => Platform::Instagram,
            'published_at' => CarbonImmutable::parse('2026-06-10 12:00:00'),
        ]);

        // Brand + product + a delivered, in-window shipment of that exact product.
        $brand = Brand::factory()->create(['name' => 'Glossier']);
        $campaign = Campaign::factory()->create(['brand_id' => $brand->id]);
        $product = Product::factory()->create(['brand_id' => $brand->id, 'name' => 'You Perfume']);
        $seeding = SeedingCampaign::factory()->create(['brand_id' => $brand->id, 'campaign_id' => $campaign->id]);
        Shipment::factory()->create([
            'seeding_campaign_id' => $seeding->id,
            'creator_id' => $creator->id,
            'product_id' => $product->id,
            'shipped_at' => CarbonImmutable::parse('2026-06-01 10:00:00'),
            'delivered_at' => CarbonImmutable::parse('2026-06-03 10:00:00'),
        ]);

        // A product-level detection (near-ground-truth product tag) for the shipped SKU.
        RecognitionDetection::factory()->create([
            'content_item_id' => $content->id,
            'recognition_type' => RecognitionType::ProductTag,
            'detected_brand' => 'Glossier',
            'detected_product' => 'You Perfume',
            'product_id' => $product->id,
            'assessment' => new ConfidenceAssessment(
                'You Perfume',
                ConfidenceLevel::High,
                ['product-tag-match:You Perfume:rung=sku'],
                VerificationStatus::AiAssessed,
            ),
        ]);

        app(AttributionService::class)->enrich($content);

        $mention = Mention::query()->where('content_item_id', $content->id)->firstOrFail();
        $this->assertSame(MentionType::Seeded, $mention->mention_type);
        $this->assertSame(ConfidenceLevel::High, $mention->classification->confidenceLevel);
        $this->assertNotContains('product-unconfirmed', $mention->classification->signals);
    }

    public function test_brand_only_detection_with_shipment_is_medium_and_flagged_when_the_switch_is_on(): void
    {
        // With the text_signals kill switch ON, brand-only alignment (no
        // product_id on the recognition) is real but unconfirmed at the
        // product level: SEEDED stands, capped at MEDIUM and flagged for
        // review — preserving end-to-end coverage of the new brand-only→
        // review path (Task 16).
        config(['qds.enrichment.text_signals.enabled' => true]);

        $creator = Creator::factory()->create();
        $account = PlatformAccount::factory()->forCreator($creator)->onPlatform(Platform::Instagram)->create();
        MonitoredSubject::factory()->create([
            'creator_id' => $creator->id,
            'platforms' => [Platform::Instagram],
            'active' => true,
        ]);
        $content = ContentItem::factory()->create([
            'platform_account_id' => $account->id,
            'platform' => Platform::Instagram,
            'published_at' => CarbonImmutable::parse('2026-06-10 12:00:00'),
        ]);

        // Brand + product + a delivered, in-window shipment of that product.
        $brand = Brand::factory()->create(['name' => 'Glossier']);
        $campaign = Campaign::factory()->create(['brand_id' => $brand->id]);
        $product = Product::factory()->create(['brand_id' => $brand->id, 'name' => 'You Perfume']);
        $seeding = SeedingCampaign::factory()->create(['brand_id' => $brand->id, 'campaign_id' => $campaign->id]);
        Shipment::factory()->create([
            'seeding_campaign_id' => $seeding->id,
            'creator_id' => $creator->id,
            'product_id' => $product->id,
            'shipped_at' => CarbonImmutable::parse('2026-06-01 10:00:00'),
            'delivered_at' => CarbonImmutable::parse('2026-06-03 10:00:00'),
        ]);

        // A matched, non-Logo, BRAND-ONLY detection: no product_id, so the
        // shipment aligns on brand name alone.
        RecognitionDetection::factory()->create([
            'content_item_id' => $content->id,
            'recognition_type' => RecognitionType::CaptionText,
            'detected_brand' => 'Glossier',
            'detected_product' => null,
            'product_id' => null,
            'assessment' => new ConfidenceAssessment(
                'Glossier',
                ConfidenceLevel::High,
                ['caption-brand-match:Glossier'],
                VerificationStatus::AiAssessed,
            ),
        ]);

        app(AttributionService::class)->enrich($content);

        $mention = Mention::query()->where('content_item_id', $content->id)->firstOrFail();
        $this->assertSame(MentionType::Seeded, $mention->mention_type);
        $this->assertSame(ConfidenceLevel::Medium, $mention->classification->confidenceLevel);
        $this->assertContains('product-unconfirmed', $mention->classification->signals);
    }
}
