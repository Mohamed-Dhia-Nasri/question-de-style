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

/**
 * Sub-project D evidence gate (spec §7): VLM_PRODUCT rows are excluded
 * ENTIRELY while qds.enrichment.vlm.enabled is off (rollback no-op,
 * byte-identical evidence), flow product evidence only past the shared
 * precision gate (NOT AI_ASSESSED && LOW/UNKNOWN), and productDoctrine
 * is the triple OR of the A/C/D switches. Zero MentionClassifier
 * changes — the end-to-end cases drive the real classifier: a VLM
 * "yes" reaches SEEDED only through an in-window shipment.
 */
class VlmEvidenceGateTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: ContentItem, 1: Product, 2: Shipment|null} wired creator/content (+ in-window shipment unless disabled) */
    private function wired(bool $withShipment = true): array
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
        $product = Product::factory()->create(['brand_id' => $brand->id, 'name' => 'You Perfume']);

        if (! $withShipment) {
            return [$content, $product, null];
        }

        $campaign = Campaign::factory()->create(['brand_id' => $brand->id]);
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

    private function vlmDetection(ContentItem $content, Product $product, ConfidenceLevel $level, VerificationStatus $status = VerificationStatus::AiAssessed): RecognitionDetection
    {
        return RecognitionDetection::factory()->create([
            'content_item_id' => $content->id,
            'recognition_type' => RecognitionType::VlmProduct,
            'provider_label' => 'vlm-product:'.$product->id,
            'detected_brand' => 'Glossier',
            'detected_product' => $product->name,
            'product_id' => $product->id,
            'assessment' => new ConfidenceAssessment(
                'Glossier',
                $level,
                ['vlm-product-match:'.$product->name, 'vlm-confidence:0.91', 'vlm-visible:true', 'vlm-model:gemini-3.5-flash'],
                $status,
            ),
        ]);
    }

    private function visualDetection(ContentItem $content, Product $product, ConfidenceLevel $level): RecognitionDetection
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
                VerificationStatus::AiAssessed,
            ),
        ]);
    }

    private function evidenceFor(ContentItem $content): EvidenceBundle
    {
        return (new ReflectionMethod(AttributionService::class, 'buildEvidence'))
            ->invoke(app(AttributionService::class), $content);
    }

    private function switches(bool $text, bool $visual, bool $vlm): void
    {
        config([
            'qds.enrichment.text_signals.enabled' => $text,
            'qds.enrichment.visual_match.enabled' => $visual,
            'qds.enrichment.vlm.enabled' => $vlm,
        ]);
    }

    public function test_auto_vlm_match_drives_high_seeded_without_text_or_visual_signals(): void
    {
        // VLM-only mode: A and C OFF, D ON — the doctrine triple-OR.
        $this->switches(text: false, visual: false, vlm: true);

        [$content, $product] = $this->wired();
        $this->vlmDetection($content, $product, ConfidenceLevel::High);

        // The product id flows into evidence (precision gate passes).
        $this->assertSame($product->id, $this->evidenceFor($content)->recognitions[0]['productId']);

        app(AttributionService::class)->enrich($content);

        $mention = Mention::query()->where('content_item_id', $content->id)->firstOrFail();
        $this->assertSame(MentionType::Seeded, $mention->mention_type);
        $this->assertSame(ConfidenceLevel::High, $mention->classification->confidenceLevel);
        $this->assertNotContains('product-unconfirmed', $mention->classification->signals);
    }

    public function test_review_vlm_match_caps_at_medium_product_unconfirmed_and_never_auto_links(): void
    {
        $this->switches(text: false, visual: false, vlm: true);

        [$content, $product, $shipment] = $this->wired();
        $this->vlmDetection($content, $product, ConfidenceLevel::Low);

        // The REVIEW-band product id is withheld from evidence entirely.
        $this->assertNull($this->evidenceFor($content)->recognitions[0]['productId']);

        app(AttributionService::class)->enrich($content);

        $mention = Mention::query()->where('content_item_id', $content->id)->firstOrFail();
        $this->assertSame(MentionType::Seeded, $mention->mention_type);
        $this->assertSame(ConfidenceLevel::Medium, $mention->classification->confidenceLevel);
        $this->assertContains('product-unconfirmed', $mention->classification->signals);

        // The §2.4 trap stays closed for VLM rows too: guarded mentions
        // never auto-link.
        $summary = app(SeededContentLinker::class)->run();
        $this->assertSame(0, $summary->linked);
        $this->assertDatabaseMissing('shipment_resulting_content', ['shipment_id' => $shipment->id]);
        $this->assertNull($mention->refresh()->campaign_id);
    }

    public function test_human_approved_low_vlm_detection_unlocks_product_flow_and_auto_link(): void
    {
        $this->switches(text: false, visual: false, vlm: true);

        [$content, $product, $shipment] = $this->wired();
        $this->vlmDetection($content, $product, ConfidenceLevel::Low, VerificationStatus::HumanReviewed);

        // Human-blessed: the gate re-opens and product_id flows.
        $this->assertSame($product->id, $this->evidenceFor($content)->recognitions[0]['productId']);

        app(AttributionService::class)->enrich($content);

        $mention = Mention::query()->where('content_item_id', $content->id)->firstOrFail();
        $this->assertSame(MentionType::Seeded, $mention->mention_type);
        // A LOW recognition stays weak relevance → MEDIUM, but product-
        // level aligned and NOT flagged — auto-link eligible again.
        $this->assertSame(ConfidenceLevel::Medium, $mention->classification->confidenceLevel);
        $this->assertNotContains('product-unconfirmed', $mention->classification->signals);

        $summary = app(SeededContentLinker::class)->run();
        $this->assertSame(1, $summary->linked);
        $this->assertDatabaseHas('shipment_resulting_content', ['shipment_id' => $shipment->id]);
    }

    public function test_vlm_high_without_shipment_stays_likely_organic(): void
    {
        // The classifier's gates are untouched: a VLM "yes" never
        // confirms seeding on its own — no in-window shipment, no SEEDED
        // (spec §1: the VLM never auto-confirms seeding).
        $this->switches(text: false, visual: false, vlm: true);

        [$content, $product] = $this->wired(withShipment: false);
        $this->vlmDetection($content, $product, ConfidenceLevel::High);

        app(AttributionService::class)->enrich($content);

        $mention = Mention::query()->where('content_item_id', $content->id)->firstOrFail();
        $this->assertSame(MentionType::LikelyOrganic, $mention->mention_type);
        $this->assertSame(ConfidenceLevel::Medium, $mention->classification->confidenceLevel);
        $this->assertContains('no-seeding-record', $mention->classification->signals);
    }

    public function test_vlm_rows_are_inert_when_the_switch_is_off(): void
    {
        $this->switches(text: false, visual: false, vlm: false);

        [$content, $product] = $this->wired();
        $this->vlmDetection($content, $product, ConfidenceLevel::High);

        $mentions = app(AttributionService::class)->enrich($content);

        // Rollback no-op: no VLM evidence, no other signal — nothing to
        // classify, exactly as pre-D behaviour.
        $this->assertSame([], $mentions);
        $this->assertSame(0, Mention::query()->count());
    }

    public function test_evidence_is_byte_identical_when_the_vlm_switch_is_off(): void
    {
        $this->switches(text: false, visual: false, vlm: false);

        [$content, $product] = $this->wired();

        $before = serialize($this->evidenceFor($content));

        $this->vlmDetection($content, $product, ConfidenceLevel::High);

        $this->assertSame($before, serialize($this->evidenceFor($content)));

        // Sanity: the same comparison DOES change once D's switch is on —
        // the byte-identity above is the gate's doing, not test blindness.
        config(['qds.enrichment.vlm.enabled' => true]);
        $this->assertNotSame($before, serialize($this->evidenceFor($content)));
    }

    public function test_switch_off_excludes_vlm_rows_even_when_text_and_visual_are_on(): void
    {
        $this->switches(text: true, visual: true, vlm: false);

        [$content, $product] = $this->wired();
        $before = serialize($this->evidenceFor($content));

        $this->vlmDetection($content, $product, ConfidenceLevel::High);

        $this->assertSame($before, serialize($this->evidenceFor($content)));
    }

    public function test_product_doctrine_is_the_triple_or_of_all_switches(): void
    {
        [$content] = $this->wired();

        $this->switches(text: false, visual: false, vlm: false);
        $this->assertFalse($this->evidenceFor($content)->productDoctrine);

        $this->switches(text: true, visual: false, vlm: false);
        $this->assertTrue($this->evidenceFor($content)->productDoctrine);

        $this->switches(text: false, visual: true, vlm: false);
        $this->assertTrue($this->evidenceFor($content)->productDoctrine);

        $this->switches(text: false, visual: false, vlm: true);
        $this->assertTrue($this->evidenceFor($content)->productDoctrine);
    }

    public function test_vlm_and_visual_rows_flow_independently_without_arbitration(): void
    {
        // Disagreement is not resolved by D (spec §7): C's REVIEW-band
        // row keeps flowing brand-only evidence while D's AUTO row
        // carries the product — both stand, sub-project E arbitrates.
        $this->switches(text: false, visual: true, vlm: true);

        [$content, $product] = $this->wired();
        $this->visualDetection($content, $product, ConfidenceLevel::Low);
        $this->vlmDetection($content, $product, ConfidenceLevel::High);

        $recognitions = $this->evidenceFor($content)->recognitions;
        $byType = collect($recognitions)->keyBy('type');

        $this->assertCount(2, $recognitions);
        $this->assertNull($byType['VISUAL_PRODUCT']['productId']);            // C's REVIEW row: brand only
        $this->assertSame($product->id, $byType['VLM_PRODUCT']['productId']); // D's AUTO row: product flows

        app(AttributionService::class)->enrich($content);

        $mention = Mention::query()->where('content_item_id', $content->id)->firstOrFail();
        $this->assertSame(MentionType::Seeded, $mention->mention_type);
        $this->assertSame(ConfidenceLevel::High, $mention->classification->confidenceLevel);
        $this->assertNotContains('product-unconfirmed', $mention->classification->signals);
    }
}
