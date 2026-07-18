<?php

namespace Tests\Unit\Enrichment;

use App\Platform\Enrichment\Attribution\EvidenceBundle;
use App\Platform\Enrichment\Attribution\ShipmentEvidence;
use Tests\TestCase;

class EvidenceBundleShapeTest extends TestCase
{
    public function test_paid_label_is_tristate_and_cues_present(): void
    {
        $b = new EvidenceBundle(contextualCues: ['gifting-cue:pr']);

        $this->assertNull($b->paidPartnershipLabel);
        $this->assertSame(['gifting-cue:pr'], $b->contextualCues);
    }

    public function test_shipment_evidence_carries_product_id(): void
    {
        $s = new ShipmentEvidence(reference: 'shipment-record:1', productLabel: 'You Perfume', productId: 42);
        $this->assertSame(42, $s->productId);
    }
}
