<?php

namespace Tests\Feature\Enrichment;

use App\Modules\CRM\Models\Brand;
use App\Modules\CRM\Models\Product;
use App\Platform\Enrichment\TextSignals\ProductResolver;
use App\Platform\Ingestion\DTO\ProductTag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_caption_name_match_requires_brand_co_occurrence(): void
    {
        $brand = Brand::factory()->create(['name' => 'Glossier']);
        Product::factory()->create(['brand_id' => $brand->id, 'name' => 'You Perfume']);

        $resolver = app(ProductResolver::class);

        // Brand present → product resolves.
        $withBrand = $resolver->matchInCaption('loving You Perfume', ['Glossier']);
        $this->assertCount(1, $withBrand);
        $this->assertSame('You Perfume', $withBrand[0]->name);

        // Brand absent → generic-name guard suppresses it.
        $this->assertSame([], $resolver->matchInCaption('loving You Perfume', []));
    }

    public function test_resolve_tag_prefers_exact_sku(): void
    {
        $brand = Brand::factory()->create(['name' => 'Glossier']);
        $product = Product::factory()->create(['brand_id' => $brand->id, 'name' => 'You Perfume', 'sku' => 'GLO-YOU-50']);

        $resolved = app(ProductResolver::class)->resolveTag(
            new ProductTag('Glossier', 'wrong name', 'GLO-YOU-50', 'ig-1')
        );

        $this->assertNotNull($resolved);
        $this->assertSame($product->id, $resolved->productId);
        $this->assertSame('sku', $resolved->rung);
    }
}
