<?php

namespace Tests\Feature\Crm;

use App\Modules\CRM\Models\Product;
use App\Modules\CRM\Models\Shipment;
use App\Shared\Enums\MetricTier;
use App\Shared\ValueObjects\MetricValue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The two M3 Step-1 MetricValue envelopes — products.unit_value and
 * shipments.product_value_at_ship — round-trip through AsValueObject with
 * tier CONFIRMED (agency-known monetary values; spec doctrine §4).
 * MetricValue has NO currency field: the canonical shape is amount + tier.
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
}
