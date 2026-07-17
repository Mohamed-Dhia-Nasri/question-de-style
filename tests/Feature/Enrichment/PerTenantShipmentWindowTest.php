<?php

namespace Tests\Feature\Enrichment;

use App\Models\Tenant;
use App\Modules\Monitoring\Models\MonitoringSetting;
use App\Platform\Enrichment\Attribution\EvidenceBundle;
use App\Platform\Enrichment\Attribution\MentionClassifier;
use App\Platform\Enrichment\Attribution\ShipmentEvidence;
use App\Shared\Enums\ConfidenceLevel;
use App\Shared\Enums\MentionType;
use App\Shared\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ADR-0025: the gift-link (shipment attribution) window is a per-tenant
 * setting. The same evidence classifies differently under a tenant whose
 * window is shorter than the config default.
 */
class PerTenantShipmentWindowTest extends TestCase
{
    use RefreshDatabase;

    /** Published 4 days after delivery of the same brand's shipment. */
    private function evidence(): EvidenceBundle
    {
        return new EvidenceBundle(
            recognitions: [['type' => 'LOGO', 'brand' => 'Maison Lumière', 'level' => ConfidenceLevel::High]],
            shipments: [new ShipmentEvidence(
                reference: 'shipment-record:42',
                brandName: 'Maison Lumière',
                deliveredAt: CarbonImmutable::parse('2026-06-02'),
            )],
            publishedAt: CarbonImmutable::parse('2026-06-06'),
        );
    }

    public function test_default_window_still_classifies_seeded_without_a_tenant_row(): void
    {
        $result = (new MentionClassifier)->classify($this->evidence());

        $this->assertNotNull($result);
        $this->assertSame(MentionType::Seeded, $result->mentionType);
    }

    public function test_a_tenant_with_a_shorter_window_no_longer_links_the_gift(): void
    {
        $tenant = Tenant::factory()->create();
        $row = new MonitoringSetting([
            'shipment_window_days' => 3, // published 4 days after delivery → outside
            'engagement_trend_window_days' => 30,
            'story_retention_days' => 180,
            'communication_retention_days' => 0,
        ]);
        $row->tenant_id = $tenant->id;
        $row->save();

        $result = app(TenantContext::class)->runAs(
            $tenant->id,
            fn () => (new MentionClassifier)->classify($this->evidence()),
        );

        // Timing is KNOWN outside this tenant's window → the shipment is no
        // proving record; whatever remains, it must never be SEEDED.
        $this->assertNotSame(MentionType::Seeded, $result?->mentionType);
    }
}
