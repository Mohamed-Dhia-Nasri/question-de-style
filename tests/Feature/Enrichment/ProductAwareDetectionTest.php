<?php

namespace Tests\Feature\Enrichment;

use App\Modules\CRM\Models\Product;
use App\Modules\Monitoring\Models\RecognitionDetection;
use App\Shared\Enums\RecognitionType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductAwareDetectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_detection_stores_product_identity(): void
    {
        $product = Product::factory()->create(['name' => 'You Perfume']);

        $d = RecognitionDetection::factory()->create([
            'recognition_type' => RecognitionType::ProductTag,
            'detected_product' => 'You Perfume',
            'product_id' => $product->id,
        ]);

        $this->assertSame('You Perfume', $d->fresh()->detected_product);
        $this->assertSame($product->id, $d->fresh()->product_id);
        $this->assertSame('PRODUCT_TAG', RecognitionType::ProductTag->value);
    }
}
