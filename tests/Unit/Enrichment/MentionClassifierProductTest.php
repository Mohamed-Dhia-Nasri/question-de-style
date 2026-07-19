<?php

namespace Tests\Unit\Enrichment;

use App\Platform\Enrichment\Attribution\EvidenceBundle;
use App\Platform\Enrichment\Attribution\MentionClassifier;
use App\Platform\Enrichment\Attribution\ShipmentEvidence;
use App\Shared\Enums\ConfidenceLevel;
use App\Shared\Enums\MentionType;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MentionClassifierProductTest extends TestCase
{
    use RefreshDatabase;

    private function recognition(string $brand, ?int $productId = null, ?string $product = null): array
    {
        return ['type' => 'PRODUCT_TAG', 'brand' => $brand, 'level' => ConfidenceLevel::High, 'productId' => $productId, 'product' => $product];
    }

    public function test_product_level_alignment_is_high_seeded(): void
    {
        $result = (new MentionClassifier)->classify(new EvidenceBundle(
            recognitions: [$this->recognition('Glossier', 42, 'You Perfume')],
            shipments: [new ShipmentEvidence(reference: 'shipment-record:1', brandName: 'Glossier', productLabel: 'You Perfume', productId: 42, deliveredAt: CarbonImmutable::parse('2026-06-01'))],
            publishedAt: CarbonImmutable::parse('2026-06-03'),
            productDoctrine: true,
        ));

        $this->assertSame(MentionType::Seeded, $result->mentionType);
        $this->assertSame(ConfidenceLevel::High, $result->confidenceLevel);
    }

    public function test_brand_only_alignment_is_medium_and_flagged_for_review(): void
    {
        $result = (new MentionClassifier)->classify(new EvidenceBundle(
            recognitions: [$this->recognition('Glossier', null, null)],
            shipments: [new ShipmentEvidence(reference: 'shipment-record:1', brandName: 'Glossier', productLabel: 'You Perfume', productId: 42, deliveredAt: CarbonImmutable::parse('2026-06-01'))],
            publishedAt: CarbonImmutable::parse('2026-06-03'),
            productDoctrine: true,
        ));

        $this->assertSame(MentionType::Seeded, $result->mentionType);
        $this->assertSame(ConfidenceLevel::Medium, $result->confidenceLevel);
        $this->assertContains('product-unconfirmed', $result->signals);
    }
}
