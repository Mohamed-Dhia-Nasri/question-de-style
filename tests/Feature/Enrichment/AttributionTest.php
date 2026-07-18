<?php

namespace Tests\Feature\Enrichment;

use App\Modules\CRM\Models\Creator;
use App\Modules\CRM\Models\PlatformAccount;
use App\Modules\CRM\Models\Product;
use App\Modules\Monitoring\Models\ContentItem;
use App\Modules\Monitoring\Models\Mention;
use App\Modules\Monitoring\Models\MonitoredSubject;
use App\Modules\Monitoring\Models\RecognitionDetection;
use App\Modules\Monitoring\Models\Story;
use App\Platform\Enrichment\Attribution\AttributionService;
use App\Platform\Enrichment\Attribution\ShipmentEvidence;
use App\Platform\Enrichment\Contracts\SeedingEvidenceSource;
use App\Shared\Enums\ConfidenceLevel;
use App\Shared\Enums\MentionType;
use App\Shared\Enums\Platform;
use App\Shared\Enums\VerificationStatus;
use App\Shared\ValueObjects\ConfidenceAssessment;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Seeded-attribution stage end to end (REQ-M1-002): evidence assembly from
 * persisted detections + a SeedingEvidenceSource, Mention upsert per active
 * CREATOR MonitoredSubject with the AI_ASSESSED envelope (DP-003) and the
 * content's provenance (DP-002), and human precedence (DP-004).
 */
class AttributionTest extends TestCase
{
    use RefreshDatabase;

    private const BRAND = 'Maison Lumière';

    private Creator $creator;

    private MonitoredSubject $subject;

    private ContentItem $content;

    protected function setUp(): void
    {
        parent::setUp();

        // creator → platform account → active CREATOR subject → content,
        // with the subject watching the content's platform.
        $this->creator = Creator::factory()->create();

        $account = PlatformAccount::factory()
            ->forCreator($this->creator)
            ->onPlatform(Platform::Instagram)
            ->create();

        $this->subject = MonitoredSubject::factory()->create([
            'creator_id' => $this->creator->id,
            'platforms' => [Platform::Instagram, Platform::TikTok],
            'active' => true,
        ]);

        $this->content = ContentItem::factory()->create([
            'platform_account_id' => $account->id,
            'platform' => Platform::Instagram,
            'published_at' => CarbonImmutable::parse('2026-06-10 12:00:00'),
        ]);
    }

    /** Bind a fake Module 3 boundary returning one aligned, dated shipment. */
    private function bindAlignedShipmentSource(): void
    {
        $this->app->instance(SeedingEvidenceSource::class, new class implements SeedingEvidenceSource
        {
            /** @return list<ShipmentEvidence> */
            public function forTarget(ContentItem|Story $target): array
            {
                return [new ShipmentEvidence(
                    reference: 'shipment-record:42',
                    brandName: 'Maison Lumière',
                    shippedAt: CarbonImmutable::parse('2026-06-01'),
                    deliveredAt: CarbonImmutable::parse('2026-06-05'),
                )];
            }
        });
    }

    private function strongRecognition(): RecognitionDetection
    {
        return RecognitionDetection::factory()->create([
            'content_item_id' => $this->content->id,
            'detected_brand' => self::BRAND,
            'assessment' => new ConfidenceAssessment(
                self::BRAND,
                ConfidenceLevel::High,
                ['logo-match-score:0.95'],
                VerificationStatus::AiAssessed,
            ),
        ]);
    }

    public function test_recognition_plus_aligned_shipment_creates_a_seeded_mention(): void
    {
        $this->strongRecognition();
        $this->bindAlignedShipmentSource();

        $mentions = app(AttributionService::class)->enrich($this->content);

        $this->assertCount(1, $mentions);
        $this->assertSame(1, Mention::query()->count());

        $mention = $mentions[0]->fresh();

        $this->assertSame($this->subject->id, $mention->monitored_subject_id);
        $this->assertSame($this->content->id, $mention->content_item_id);
        $this->assertSame(MentionType::Seeded, $mention->mention_type);

        // AI writes start AI_ASSESSED with the proving record and the
        // recognition evidence in the signals (DP-003, AC-M1-003).
        $this->assertInstanceOf(ConfidenceAssessment::class, $mention->classification);
        $this->assertSame(MentionType::Seeded->value, $mention->classification->value);

        // Under the default (text_signals kill switch OFF) legacy
        // brand-level doctrine, brand alignment alone proves HIGH — the
        // product-aware tightening (product-unconfirmed → MEDIUM) only
        // applies when the flag is ON (see AttributionProductEvidenceTest).
        $this->assertSame(ConfidenceLevel::High, $mention->classification->confidenceLevel);
        $this->assertSame(VerificationStatus::AiAssessed, $mention->classification->verificationStatus);
        $this->assertNotEmpty($mention->classification->signals);
        $this->assertContains('shipment-record:42', $mention->classification->signals);
        $this->assertNotContains('product-unconfirmed', $mention->classification->signals);

        // The mention derives from the externally-sourced content: its
        // provenance is the content's provenance (DP-002).
        $this->assertSame($this->content->provenance->source, $mention->provenance->source);
    }

    /** Bind a fake Module 3 boundary returning one aligned shipment carrying a productId. */
    private function bindAlignedShipmentSourceWithProductId(string $brandName, int $productId): void
    {
        $this->app->instance(SeedingEvidenceSource::class, new class($brandName, $productId) implements SeedingEvidenceSource
        {
            public function __construct(private readonly string $brandName, private readonly int $productId) {}

            /** @return list<ShipmentEvidence> */
            public function forTarget(ContentItem|Story $target): array
            {
                return [new ShipmentEvidence(
                    reference: 'shipment-record:99',
                    brandName: $this->brandName,
                    productId: $this->productId,
                    shippedAt: CarbonImmutable::parse('2026-06-01'),
                    deliveredAt: CarbonImmutable::parse('2026-06-05'),
                )];
            }
        });
    }

    public function test_a_stale_recognition_product_id_does_not_align_a_brand_mismatched_shipment_when_the_switch_is_off(): void
    {
        // A recognition whose brand was corrected AWAY from the shipment's
        // brand (e.g. via ReviewService after the flag was previously ON)
        // but whose product_id column still happens to equal the shipment's
        // product id. With the kill switch OFF, MentionClassifier's
        // productId shortcut must never see this productId — evidence
        // gating strips it — so alignment can only fall back to a brand
        // name match, which fails here. Before the fix this stray productId
        // still aligned the shipment and produced SEEDED/HIGH.
        $product = Product::factory()->create();

        RecognitionDetection::factory()->create([
            'content_item_id' => $this->content->id,
            'detected_brand' => 'A Totally Different Brand',
            'product_id' => $product->id,
            'assessment' => new ConfidenceAssessment(
                'A Totally Different Brand',
                ConfidenceLevel::High,
                ['logo-match-score:0.95'],
                VerificationStatus::AiAssessed,
            ),
        ]);

        // The shipment is self::BRAND's, not "A Totally Different Brand"'s —
        // only the stray matching productId could align them.
        $this->bindAlignedShipmentSourceWithProductId(self::BRAND, $product->id);

        $mentions = app(AttributionService::class)->enrich($this->content);

        $this->assertCount(1, $mentions);
        $mention = $mentions[0]->fresh();

        // Never SEEDED: with the flag off, the shipment (a different brand)
        // has no aligned evidence at all — a strong, unlinked recognition of
        // a brand with no seeding record is LIKELY_ORGANIC (legacy
        // doctrine), never SEEDED.
        $this->assertNotSame(MentionType::Seeded, $mention->mention_type);
        $this->assertSame(MentionType::LikelyOrganic, $mention->mention_type);
        $this->assertNotContains('shipment-record:99', $mention->classification->signals);
    }

    public function test_a_human_corrected_classification_is_never_overwritten_by_ai(): void
    {
        $this->strongRecognition();
        $this->bindAlignedShipmentSource();

        $service = app(AttributionService::class);

        [$mention] = $service->enrich($this->content);
        $this->assertSame(MentionType::Seeded, $mention->mention_type);

        // A human corrects the classification (DP-004).
        $mention->update([
            'mention_type' => MentionType::LikelyOrganic,
            'classification' => new ConfidenceAssessment(
                MentionType::LikelyOrganic->value,
                ConfidenceLevel::High,
                ['human-decision:creator-bought-the-product'],
                VerificationStatus::HumanCorrected,
            ),
        ]);

        // A later AI run still sees SEEDED evidence — and must yield.
        $again = $service->enrich($this->content);

        $this->assertCount(1, $again);
        $this->assertSame(1, Mention::query()->count());

        $mention->refresh();

        $this->assertSame(MentionType::LikelyOrganic, $mention->mention_type);
        $this->assertSame(VerificationStatus::HumanCorrected, $mention->classification->verificationStatus);
        $this->assertContains('human-decision:creator-bought-the-product', $mention->classification->signals);
    }

    public function test_human_rejected_recognitions_carry_no_evidential_weight(): void
    {
        // One detection retracted to a null value, one explicitly rejected
        // by a human: neither may count as relevance evidence.
        RecognitionDetection::factory()->create([
            'content_item_id' => $this->content->id,
            'detected_brand' => self::BRAND,
            'assessment' => new ConfidenceAssessment(
                null,
                ConfidenceLevel::Unknown,
                ['human-review:no-brand-present'],
                VerificationStatus::HumanCorrected,
            ),
        ]);

        RecognitionDetection::factory()->create([
            'content_item_id' => $this->content->id,
            'detected_brand' => self::BRAND,
            'assessment' => new ConfidenceAssessment(
                self::BRAND,
                ConfidenceLevel::High,
                ['logo-match-score:0.95', 'human-rejected'],
                VerificationStatus::HumanCorrected,
            ),
        ]);

        $this->bindAlignedShipmentSource();

        // With the rejected recognitions ignored, the aligned shipment is
        // the only remaining evidence — and a shipment alone never proves
        // attribution, so no Mention is created at all.
        $mentions = app(AttributionService::class)->enrich($this->content);

        $this->assertSame([], $mentions);
        $this->assertSame(0, Mention::query()->count());
    }
}
