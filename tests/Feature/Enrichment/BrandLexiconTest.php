<?php

namespace Tests\Feature\Enrichment;

use App\Modules\CRM\Models\Brand;
use App\Platform\Enrichment\Recognition\BrandLexicon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BrandLexiconTest extends TestCase
{
    use RefreshDatabase;

    public function test_matches_all_brands_diacritic_insensitive(): void
    {
        Brand::factory()->create(['name' => 'Nestlé', 'aliases' => []]);
        Brand::factory()->create(['name' => 'CeraVe', 'aliases' => []]);

        $found = (new BrandLexicon)->matchAllInText('loving nestle and cerave together');

        $this->assertEqualsCanonicalizing(['Nestlé', 'CeraVe'], $found);
    }

    public function test_matches_possessive_brand_mention(): void
    {
        Brand::factory()->create(['name' => 'Nike', 'aliases' => []]);

        // Apostrophes are kept in folding, so "nike's" still boundary-matches "nike".
        $this->assertSame(['Nike'], (new BrandLexicon)->matchAllInText("obsessed with nike's new drop"));
    }

    public function test_returns_brands_in_first_offset_order_even_via_alias(): void
    {
        Brand::factory()->create(['name' => 'Glossier', 'aliases' => ['glossy']]);
        Brand::factory()->create(['name' => 'Dove', 'aliases' => []]);

        // "glossy" (a Glossier alias) appears before "dove"; Glossier must sort first.
        $found = (new BrandLexicon)->matchAllInText('glossy serum then dove cream then glossier reveal');

        $this->assertSame(['Glossier', 'Dove'], $found);
    }

    public function test_resolves_at_handle_to_brand(): void
    {
        Brand::factory()->create(['name' => 'Glossier', 'aliases' => [], 'social_handles' => ['glossier']]);

        $this->assertSame('Glossier', (new BrandLexicon)->resolveHandle('@glossier'));
        $this->assertNull((new BrandLexicon)->resolveHandle('@unknownbrand'));
    }
}
