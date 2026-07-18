<?php

namespace Tests\Feature\Enrichment;

use App\Modules\CRM\Models\Brand;
use App\Modules\CRM\Models\Product;
use App\Modules\Monitoring\Models\ContentItem;
use App\Modules\Monitoring\Models\RecognitionDetection;
use App\Platform\Enrichment\Recognition\BrandLexicon;
use App\Platform\Enrichment\TextSignals\MentionExtractor;
use App\Platform\Enrichment\TextSignals\ProductResolver;
use App\Platform\Enrichment\TextSignals\TextSignalRecognizer;
use App\Shared\Enums\RecognitionType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TextSignalRecognizerTest extends TestCase
{
    use RefreshDatabase;

    public function test_writes_caption_mention_and_product_tag_detections(): void
    {
        $brand = Brand::factory()->create(['name' => 'Glossier', 'social_handles' => ['glossier']]);
        $product = Product::factory()->create(['brand_id' => $brand->id, 'name' => 'You Perfume', 'sku' => 'GLO-YOU-50']);

        $item = ContentItem::factory()->create([
            'caption' => 'thanks @glossier for the You Perfume',
            'product_tags' => [['brand_ref' => 'Glossier', 'product_name' => 'You Perfume', 'product_sku' => 'GLO-YOU-50', 'provider_tag_id' => 'ig-1']],
        ]);

        (new TextSignalRecognizer(
            app(BrandLexicon::class),
            app(MentionExtractor::class),
            app(ProductResolver::class),
        ))->enrich($item);

        $types = RecognitionDetection::query()->where('content_item_id', $item->id)->pluck('recognition_type')->all();
        $this->assertContains(RecognitionType::Mention, $types);
        $this->assertContains(RecognitionType::ProductTag, $types);

        $tag = RecognitionDetection::query()->where('content_item_id', $item->id)
            ->where('recognition_type', RecognitionType::ProductTag)->firstOrFail();
        $this->assertSame($product->id, $tag->product_id);
        $this->assertSame('Glossier', $tag->detected_brand);
    }
}
