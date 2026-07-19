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
use App\Platform\Enrichment\Attribution\EvidenceBundle;
use App\Platform\Enrichment\Matching\SeededContentLinker;
use App\Shared\Enums\ConfidenceLevel;
use App\Shared\Enums\MentionType;
use App\Shared\Enums\Platform;
use App\Shared\Enums\RecognitionType;
use App\Shared\Enums\VerificationStatus;
use App\Shared\ValueObjects\ConfidenceAssessment;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

class VisualEvidenceGateTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: ContentItem, 1: Product, 2: Shipment} wired creator/content + in-window shipment */
    private function wired(): array
    {
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

        $brand = Brand::factory()->create(['name' => 'Glossier']);
        $campaign = Campaign::factory()->create(['brand_id' => $brand->id]);
        $product = Product::factory()->create(['brand_id' => $brand->id, 'name' => 'You Perfume']);
        $seeding = SeedingCampaign::factory()->create(['brand_id' => $brand->id, 'campaign_id' => $campaign->id]);
        $shipment = Shipment::factory()->create([
            'seeding_campaign_id' => $seeding->id,
            'creator_id' => $creator->id,
            'product_id' => $product->id,
            'shipped_at' => CarbonImmutable::parse('2026-06-01 10:00:00'),
            'delivered_at' => CarbonImmutable::parse('2026-06-03 10:00:00'),
        ]);

        return [$content, $product, $shipment];
    }

    private function visualDetection(ContentItem $content, Product $product, ConfidenceLevel $level, VerificationStatus $status = VerificationStatus::AiAssessed): RecognitionDetection
    {
        return RecognitionDetection::factory()->create([
            'content_item_id' => $content->id,
            'recognition_type' => RecognitionType::VisualProduct,
            'provider_label' => 'visual-product:'.$product->id,
            'detected_brand' => 'Glossier',
            'detected_product' => $product->name,
            'product_id' => $product->id,
            'assessment' => new ConfidenceAssessment(
                'Glossier',
                $level,
                ['visual-product-match:'.$product->name, 'embedding-model:gemini-embedding-2'],
                $status,
            ),
        ]);
    }

    private function evidenceFor(ContentItem $content): EvidenceBundle
    {
        return (new ReflectionMethod(AttributionService::class, 'buildEvidence'))
            ->invoke(app(AttributionService::class), $content);
    }

    public function test_auto_visual_match_drives_high_seeded_without_text_signals(): void
    {
        // Visual-only mode: A's switch OFF, C's switch ON — the doctrine OR.
        config(['qds.enrichment.text_signals.enabled' => false, 'qds.enrichment.visual_match.enabled' => true]);

        [$content, $product] = $this->wired();
        $this->visualDetection($content, $product, ConfidenceLevel::High);

        // The product id flows into evidence (visual precision gate passes).
        $this->assertSame($product->id, $this->evidenceFor($content)->recognitions[0]['productId']);

        app(AttributionService::class)->enrich($content);

        $mention = Mention::query()->where('content_item_id', $content->id)->firstOrFail();
        $this->assertSame(MentionType::Seeded, $mention->mention_type);
        $this->assertSame(ConfidenceLevel::High, $mention->classification->confidenceLevel);
        $this->assertNotContains('product-unconfirmed', $mention->classification->signals);
    }

    public function test_review_visual_match_caps_at_medium_product_unconfirmed_and_never_auto_links(): void
    {
        config(['qds.enrichment.text_signals.enabled' => false, 'qds.enrichment.visual_match.enabled' => true]);

        [$content, $product, $shipment] = $this->wired();
        $this->visualDetection($content, $product, ConfidenceLevel::Low);

        // The REVIEW-band product id is withheld from evidence entirely.
        $this->assertNull($this->evidenceFor($content)->recognitions[0]['productId']);

        app(AttributionService::class)->enrich($content);

        $mention = Mention::query()->where('content_item_id', $content->id)->firstOrFail();
        $this->assertSame(MentionType::Seeded, $mention->mention_type);
        $this->assertSame(ConfidenceLevel::Medium, $mention->classification->confidenceLevel);
        $this->assertContains('product-unconfirmed', $mention->classification->signals);

        // The §2.4 trap stays closed: guarded mentions never auto-link.
        $summary = app(SeededContentLinker::class)->run();
        $this->assertSame(0, $summary->linked);
        $this->assertDatabaseMissing('shipment_resulting_content', ['shipment_id' => $shipment->id]);
        $this->assertNull($mention->refresh()->campaign_id);
    }

    public function test_human_approved_low_detection_unlocks_product_flow_and_auto_link(): void
    {
        config(['qds.enrichment.text_signals.enabled' => false, 'qds.enrichment.visual_match.enabled' => true]);

        [$content, $product, $shipment] = $this->wired();
        $this->visualDetection($content, $product, ConfidenceLevel::Low, VerificationStatus::HumanReviewed);

        // Human-blessed: the gate re-opens and product_id flows.
        $this->assertSame($product->id, $this->evidenceFor($content)->recognitions[0]['productId']);

        app(AttributionService::class)->enrich($content);

        $mention = Mention::query()->where('content_item_id', $content->id)->firstOrFail();
        $this->assertSame(MentionType::Seeded, $mention->mention_type);
        // A LOW recognition stays weak relevance → MEDIUM, but product-level
        // aligned and NOT flagged — auto-link eligible again.
        $this->assertSame(ConfidenceLevel::Medium, $mention->classification->confidenceLevel);
        $this->assertNotContains('product-unconfirmed', $mention->classification->signals);

        $summary = app(SeededContentLinker::class)->run();
        $this->assertSame(1, $summary->linked);
        $this->assertDatabaseHas('shipment_resulting_content', ['shipment_id' => $shipment->id]);
    }

    public function test_visual_rows_are_inert_when_the_switch_is_off(): void
    {
        config(['qds.enrichment.text_signals.enabled' => false, 'qds.enrichment.visual_match.enabled' => false]);

        [$content, $product] = $this->wired();
        $this->visualDetection($content, $product, ConfidenceLevel::High);

        $mentions = app(AttributionService::class)->enrich($content);

        // Rollback no-op: no visual evidence, no other signal — nothing to
        // classify, exactly as pre-C behaviour.
        $this->assertSame([], $mentions);
        $this->assertSame(0, Mention::query()->count());
    }

    public function test_evidence_is_byte_identical_when_the_switch_is_off(): void
    {
        config(['qds.enrichment.text_signals.enabled' => false, 'qds.enrichment.visual_match.enabled' => false]);

        [$content, $product] = $this->wired();

        $before = serialize($this->evidenceFor($content));

        $this->visualDetection($content, $product, ConfidenceLevel::High);

        $this->assertSame($before, serialize($this->evidenceFor($content)));

        // Sanity: the same comparison DOES change once the switch is on —
        // the byte-identity above is the gate's doing, not test blindness.
        config(['qds.enrichment.visual_match.enabled' => true]);
        $this->assertNotSame($before, serialize($this->evidenceFor($content)));
    }

    public function test_switch_off_excludes_visual_rows_even_when_text_signals_are_on(): void
    {
        config(['qds.enrichment.text_signals.enabled' => true, 'qds.enrichment.visual_match.enabled' => false]);

        [$content, $product] = $this->wired();
        $before = serialize($this->evidenceFor($content));

        $this->visualDetection($content, $product, ConfidenceLevel::High);

        $this->assertSame($before, serialize($this->evidenceFor($content)));
    }

    public function test_product_doctrine_is_the_or_of_both_switches(): void
    {
        [$content] = $this->wired();

        config(['qds.enrichment.text_signals.enabled' => false, 'qds.enrichment.visual_match.enabled' => false]);
        $this->assertFalse($this->evidenceFor($content)->productDoctrine);

        config(['qds.enrichment.text_signals.enabled' => true, 'qds.enrichment.visual_match.enabled' => false]);
        $this->assertTrue($this->evidenceFor($content)->productDoctrine);

        config(['qds.enrichment.text_signals.enabled' => false, 'qds.enrichment.visual_match.enabled' => true]);
        $this->assertTrue($this->evidenceFor($content)->productDoctrine);
    }
}
