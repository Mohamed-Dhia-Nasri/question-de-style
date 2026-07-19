<?php

namespace Tests\Feature\Enrichment;

use App\Modules\CRM\Models\Product;
use App\Modules\Monitoring\Models\RecognitionDetection;
use App\Shared\Enums\RecognitionType;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class VisualProductRecognitionTypeTest extends TestCase
{
    use RefreshDatabase;

    public function test_visual_product_case_exists_with_the_canonical_value(): void
    {
        $this->assertSame('VISUAL_PRODUCT', RecognitionType::VisualProduct->value);
        $this->assertSame(RecognitionType::VisualProduct, RecognitionType::from('VISUAL_PRODUCT'));
    }

    public function test_a_visual_product_detection_row_persists_and_reloads(): void
    {
        $product = Product::factory()->create();

        $detection = RecognitionDetection::factory()->create([
            'recognition_type' => RecognitionType::VisualProduct,
            // The stable DP-004 upsert identity Task 18's writer will use.
            'provider_label' => 'visual-product:'.$product->id,
            'detected_product' => $product->name,
            'product_id' => $product->id,
        ]);

        $reloaded = RecognitionDetection::query()->findOrFail($detection->id);

        $this->assertSame(RecognitionType::VisualProduct, $reloaded->recognition_type);
        $this->assertSame('visual-product:'.$product->id, $reloaded->provider_label);
        $this->assertSame($product->id, $reloaded->product_id);
    }

    public function test_the_widened_check_still_rejects_unknown_types(): void
    {
        $detection = RecognitionDetection::factory()->create();

        $this->expectException(QueryException::class);
        DB::statement(
            'UPDATE recognition_detections SET recognition_type = ? WHERE id = ?',
            ['HOLOGRAM', $detection->id]
        );
    }
}
