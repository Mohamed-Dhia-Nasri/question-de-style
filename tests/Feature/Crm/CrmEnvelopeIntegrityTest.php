<?php

namespace Tests\Feature\Crm;

use App\Modules\CRM\Models\Campaign;
use App\Modules\CRM\Models\Product;
use App\Modules\CRM\Models\SeedingCampaign;
use App\Modules\CRM\Models\Shipment;
use App\Shared\Enums\MetricTier;
use App\Shared\ValueObjects\MetricValue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The M3 MetricValue envelopes — products.unit_value and
 * shipments.product_value_at_ship (Step 1), plus campaigns.spend and
 * seeding_campaigns.spend (Step 4, spec D1) — round-trip through
 * AsValueObject with tier CONFIRMED (agency-known monetary values; spec
 * doctrine §4). MetricValue has NO currency field: the canonical shape is
 * amount + tier (+ the flagged optional metric label).
 */
class CrmEnvelopeIntegrityTest extends TestCase
{
    use RefreshDatabase;

    public function test_product_unit_value_round_trips_at_tier_confirmed(): void
    {
        $product = Product::factory()->create([
            'unit_value' => new MetricValue(49.90, MetricTier::Confirmed),
        ]);

        $fresh = $product->fresh();
        $this->assertNotNull($fresh);

        $this->assertInstanceOf(MetricValue::class, $fresh->unit_value);
        $this->assertSame(49.90, $fresh->unit_value->amount);
        $this->assertSame(MetricTier::Confirmed, $fresh->unit_value->tier);

        // Raw jsonb carries the tier with the number (DP-001).
        $raw = json_decode((string) $fresh->getRawOriginal('unit_value'), true);
        $this->assertSame('CONFIRMED', $raw['tier']);
    }

    public function test_product_unit_value_is_nullable(): void
    {
        $product = Product::factory()->create(['unit_value' => null]);

        $fresh = $product->fresh();
        $this->assertNotNull($fresh);
        $this->assertNull($fresh->unit_value);
    }

    public function test_shipment_product_value_at_ship_round_trips_at_tier_confirmed(): void
    {
        $shipment = Shipment::factory()->create([
            'product_value_at_ship' => new MetricValue(120.0, MetricTier::Confirmed),
        ]);

        $fresh = $shipment->fresh();
        $this->assertNotNull($fresh);

        $this->assertInstanceOf(MetricValue::class, $fresh->product_value_at_ship);
        $this->assertSame(120.0, $fresh->product_value_at_ship->amount);
        $this->assertSame(MetricTier::Confirmed, $fresh->product_value_at_ship->tier);

        $raw = json_decode((string) $fresh->getRawOriginal('product_value_at_ship'), true);
        $this->assertSame('CONFIRMED', $raw['tier']);
    }

    public function test_campaign_spend_round_trips_at_tier_confirmed(): void
    {
        $campaign = Campaign::factory()->create([
            'spend' => new MetricValue(2500.0, MetricTier::Confirmed, 'spend'),
        ]);

        $fresh = $campaign->fresh();
        $this->assertNotNull($fresh);

        $this->assertInstanceOf(MetricValue::class, $fresh->spend);
        $this->assertSame(2500.0, $fresh->spend->amount);
        $this->assertSame(MetricTier::Confirmed, $fresh->spend->tier);
        $this->assertSame('spend', $fresh->spend->metric);

        // Raw jsonb carries the tier and metric label with the number (DP-001).
        $raw = json_decode((string) $fresh->getRawOriginal('spend'), true);
        $this->assertSame('CONFIRMED', $raw['tier']);
        $this->assertSame('spend', $raw['metric']);
    }

    public function test_seeding_campaign_spend_round_trips_at_tier_confirmed(): void
    {
        $seeding = SeedingCampaign::factory()->create([
            'spend' => new MetricValue(780.5, MetricTier::Confirmed, 'spend'),
        ]);

        $fresh = $seeding->fresh();
        $this->assertNotNull($fresh);

        $this->assertInstanceOf(MetricValue::class, $fresh->spend);
        $this->assertSame(780.5, $fresh->spend->amount);
        $this->assertSame(MetricTier::Confirmed, $fresh->spend->tier);
        $this->assertSame('spend', $fresh->spend->metric);

        $raw = json_decode((string) $fresh->getRawOriginal('spend'), true);
        $this->assertSame('CONFIRMED', $raw['tier']);
        $this->assertSame('spend', $raw['metric']);
    }

    public function test_spend_is_nullable_on_both_tables(): void
    {
        // Absent spend stays NULL — never a zero envelope (DP-001).
        $campaign = Campaign::factory()->create(['spend' => null]);
        $seeding = SeedingCampaign::factory()->create(['spend' => null]);

        $freshCampaign = $campaign->fresh();
        $freshSeeding = $seeding->fresh();
        $this->assertNotNull($freshCampaign);
        $this->assertNotNull($freshSeeding);
        $this->assertNull($freshCampaign->spend);
        $this->assertNull($freshSeeding->spend);
    }
}
