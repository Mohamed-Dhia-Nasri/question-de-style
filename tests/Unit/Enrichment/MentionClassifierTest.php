<?php

namespace Tests\Unit\Enrichment;

use App\Platform\Enrichment\Attribution\EvidenceBundle;
use App\Platform\Enrichment\Attribution\MentionClassifier;
use App\Platform\Enrichment\Attribution\ShipmentEvidence;
use App\Platform\Enrichment\Support\HashtagScope;
use App\Shared\Enums\ConfidenceLevel;
use App\Shared\Enums\MentionType;
use App\Shared\Enums\VerificationStatus;
use App\Shared\ValueObjects\ConfidenceAssessment;
use Carbon\CarbonImmutable;
use Tests\TestCase;

/**
 * Pure evidence-chain classification doctrine (REQ-M1-002, AC-M1-002/003/020,
 * ADR-0008): PAID/SEEDED only with a proving record or label, a shipment or
 * hashtag alone never proves anything, weak/ambiguous evidence routes to
 * review, and organic is never asserted as fact (no CONFIRMED_ORGANIC).
 *
 * Extends Tests\TestCase only for config() — the classifier is pure, no DB.
 */
class MentionClassifierTest extends TestCase
{
    private MentionClassifier $classifier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->classifier = new MentionClassifier;
    }

    /** @return array{type: string, brand: string, level: ConfidenceLevel} */
    private function recognition(string $brand, ConfidenceLevel $level = ConfidenceLevel::High): array
    {
        return ['type' => 'LOGO', 'brand' => $brand, 'level' => $level];
    }

    /** @return array{original: string, scope: string, campaign_id: int|null, brand_id: int|null, brand_name: string|null, product_label: string|null} */
    private function brandHashtag(string $brandName, int $brandId = 7): array
    {
        return [
            'original' => '#'.strtolower(str_replace(' ', '', $brandName)),
            'scope' => HashtagScope::Brand->value,
            'campaign_id' => null,
            'brand_id' => $brandId,
            'brand_name' => $brandName,
            'product_label' => null,
        ];
    }

    public function test_no_evidence_at_all_creates_no_mention(): void
    {
        $result = $this->classifier->classify(new EvidenceBundle(
            publishedAt: CarbonImmutable::parse('2026-06-10'),
        ));

        $this->assertNull($result);
    }

    public function test_a_shipment_alone_never_proves_attribution_and_creates_no_mention(): void
    {
        // A documented seeding record with zero relevance evidence: the
        // shipment is only a LINK — without independently evidenced brand/
        // product relevance there is nothing to classify (AC-M1-020).
        $result = $this->classifier->classify(new EvidenceBundle(
            shipments: [new ShipmentEvidence(
                reference: 'shipment-record:42',
                brandName: 'Maison Lumière',
                deliveredAt: CarbonImmutable::parse('2026-06-05'),
            )],
            publishedAt: CarbonImmutable::parse('2026-06-10'),
        ));

        $this->assertNull($result);
    }

    public function test_a_brand_hashtag_alone_is_likely_organic_never_seeded(): void
    {
        $result = $this->classifier->classify(new EvidenceBundle(
            hashtagMatches: [$this->brandHashtag('Maison Lumière')],
            publishedAt: CarbonImmutable::parse('2026-06-10'),
        ));

        $this->assertNotNull($result);
        $this->assertSame(MentionType::LikelyOrganic, $result->mentionType);
        $this->assertNotSame(MentionType::Seeded, $result->mentionType);
        $this->assertContains('no-seeding-record', $result->signals);
        $this->assertContains('no-disclosure-label', $result->signals);
    }

    public function test_strong_recognition_with_aligned_shipment_in_window_is_seeded_high(): void
    {
        $this->assertSame(60, config('qds.enrichment.attribution.shipment_window_days'));

        // Published a few days after delivery of the same brand's shipment:
        // under the default (flag-off) legacy brand-level doctrine, brand
        // alignment alone is sufficient to prove HIGH — no product-level
        // evidence required (the product-aware tightening only applies when
        // the text_signals kill switch is ON; see MentionClassifierProductTest).
        $result = $this->classifier->classify(new EvidenceBundle(
            recognitions: [$this->recognition('Maison Lumière')],
            shipments: [new ShipmentEvidence(
                reference: 'shipment-record:42',
                brandName: 'Maison Lumière',
                shippedAt: CarbonImmutable::parse('2026-05-30'),
                deliveredAt: CarbonImmutable::parse('2026-06-02'),
            )],
            publishedAt: CarbonImmutable::parse('2026-06-06'),
        ));

        $this->assertNotNull($result);
        $this->assertSame(MentionType::Seeded, $result->mentionType);
        $this->assertSame(ConfidenceLevel::High, $result->confidenceLevel);

        // The proving record and the relevance evidence are both in the
        // signals (AC-M1-003, DP-003).
        $this->assertContains('shipment-record:42', $result->signals);
        $this->assertContains('recognition:LOGO:Maison Lumière:HIGH', $result->signals);
        $this->assertNotContains('shipment-timing-unverified', $result->signals);
        $this->assertNotContains('product-unconfirmed', $result->signals);
    }

    public function test_a_shipment_of_a_different_brand_is_no_link(): void
    {
        $result = $this->classifier->classify(new EvidenceBundle(
            recognitions: [$this->recognition('Maison Lumière')],
            shipments: [new ShipmentEvidence(
                reference: 'shipment-record:43',
                brandName: 'Autre Marque',
                deliveredAt: CarbonImmutable::parse('2026-06-02'),
            )],
            publishedAt: CarbonImmutable::parse('2026-06-06'),
        ));

        $this->assertNotNull($result);
        $this->assertSame(MentionType::LikelyOrganic, $result->mentionType);
        $this->assertNotContains('shipment-record:43', $result->signals);
        $this->assertContains('no-seeding-record', $result->signals);
    }

    public function test_content_published_outside_the_shipment_window_is_not_seeded(): void
    {
        $shipment = new ShipmentEvidence(
            reference: 'shipment-record:44',
            brandName: 'Maison Lumière',
            shippedAt: CarbonImmutable::parse('2026-06-08'),
            deliveredAt: CarbonImmutable::parse('2026-06-12'),
        );

        // Published BEFORE the product even shipped: no link.
        $before = $this->classifier->classify(new EvidenceBundle(
            recognitions: [$this->recognition('Maison Lumière')],
            shipments: [$shipment],
            publishedAt: CarbonImmutable::parse('2026-06-01'),
        ));

        $this->assertNotNull($before);
        $this->assertSame(MentionType::LikelyOrganic, $before->mentionType);
        $this->assertNotContains('shipment-record:44', $before->signals);

        // Published long after the window (default 60 days): no link either.
        $after = $this->classifier->classify(new EvidenceBundle(
            recognitions: [$this->recognition('Maison Lumière')],
            shipments: [$shipment],
            publishedAt: CarbonImmutable::parse('2026-09-15'),
        ));

        $this->assertNotNull($after);
        $this->assertSame(MentionType::LikelyOrganic, $after->mentionType);
        $this->assertNotContains('shipment-record:44', $after->signals);
    }

    public function test_undated_aligned_shipment_with_strong_recognition_is_seeded_medium(): void
    {
        // No shipped/delivered dates → the timing can neither confirm nor
        // exclude the link: SEEDED stands, capped at MEDIUM and flagged.
        $result = $this->classifier->classify(new EvidenceBundle(
            recognitions: [$this->recognition('Maison Lumière')],
            shipments: [new ShipmentEvidence(
                reference: 'shipment-record:45',
                brandName: 'Maison Lumière',
            )],
            publishedAt: CarbonImmutable::parse('2026-06-06'),
        ));

        $this->assertNotNull($result);
        $this->assertSame(MentionType::Seeded, $result->mentionType);
        $this->assertSame(ConfidenceLevel::Medium, $result->confidenceLevel);
        $this->assertContains('shipment-record:45', $result->signals);
        $this->assertContains('shipment-timing-unverified', $result->signals);
    }

    public function test_only_ambiguous_hashtags_stay_unknown_and_route_to_review(): void
    {
        $result = $this->classifier->classify(new EvidenceBundle(
            ambiguousHashtags: ['glowdrop'],
            publishedAt: CarbonImmutable::parse('2026-06-10'),
        ));

        $this->assertNotNull($result);
        $this->assertSame(MentionType::Unknown, $result->mentionType);
        $this->assertSame(ConfidenceLevel::Low, $result->confidenceLevel);
        $this->assertContains('ambiguous-hashtag-match', $result->signals);

        // Persisted as an AI_ASSESSED envelope this routes to human review
        // (DP-004).
        $envelope = new ConfidenceAssessment(
            $result->mentionType->value,
            $result->confidenceLevel,
            $result->signals,
            VerificationStatus::AiAssessed,
        );

        $this->assertTrue($envelope->needsHumanReview());
    }

    public function test_only_weak_recognitions_stay_unknown_with_weak_signal(): void
    {
        $result = $this->classifier->classify(new EvidenceBundle(
            recognitions: [$this->recognition('Maison Lumière', ConfidenceLevel::Low)],
            publishedAt: CarbonImmutable::parse('2026-06-10'),
        ));

        $this->assertNotNull($result);
        $this->assertSame(MentionType::Unknown, $result->mentionType);
        $this->assertSame(ConfidenceLevel::Low, $result->confidenceLevel);
        $this->assertContains('weak-signal', $result->signals);

        $envelope = new ConfidenceAssessment(
            $result->mentionType->value,
            $result->confidenceLevel,
            $result->signals,
            VerificationStatus::AiAssessed,
        );

        $this->assertTrue($envelope->needsHumanReview());
    }

    public function test_platform_disclosure_label_is_the_only_path_to_paid(): void
    {
        $recognitions = [$this->recognition('Maison Lumière')];
        $shipments = [new ShipmentEvidence(
            reference: 'shipment-record:42',
            brandName: 'Maison Lumière',
            deliveredAt: CarbonImmutable::parse('2026-06-02'),
        )];

        // The platform's own paid-partnership label is a proving record for
        // PAID (AC-M1-003) and outranks every other reading.
        $labelled = $this->classifier->classify(new EvidenceBundle(
            recognitions: $recognitions,
            shipments: $shipments,
            paidPartnershipLabel: true,
            publishedAt: CarbonImmutable::parse('2026-06-06'),
        ));

        $this->assertNotNull($labelled);
        $this->assertSame(MentionType::Paid, $labelled->mentionType);
        $this->assertSame(ConfidenceLevel::High, $labelled->confidenceLevel);
        $this->assertContains('platform-paid-partnership-label', $labelled->signals);

        // The exact same seeded evidence WITHOUT the label never yields PAID:
        // QDS content is never processed as paid sponsorship.
        $unlabelled = $this->classifier->classify(new EvidenceBundle(
            recognitions: $recognitions,
            shipments: $shipments,
            paidPartnershipLabel: false,
            publishedAt: CarbonImmutable::parse('2026-06-06'),
        ));

        $this->assertNotNull($unlabelled);
        $this->assertNotSame(MentionType::Paid, $unlabelled->mentionType);
        $this->assertSame(MentionType::Seeded, $unlabelled->mentionType);
    }

    public function test_confirmed_organic_does_not_exist_as_an_outcome(): void
    {
        // ADR-0008: organic is never asserted as fact — the enum itself has
        // no CONFIRMED_ORGANIC value for the classifier to ever return.
        $this->assertNull(MentionType::tryFrom('CONFIRMED_ORGANIC'));
    }
}
