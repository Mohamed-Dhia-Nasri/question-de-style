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
        Brand::factory()->create(['name' => "L'Oréal", 'aliases' => []]);
        Brand::factory()->create(['name' => 'CeraVe', 'aliases' => []]);

        $lex = new BrandLexicon;
        $found = $lex->matchAllInText('loving loreal and cerave together');

        $this->assertEqualsCanonicalizing(["L'Oréal", 'CeraVe'], $found);
    }

    public function test_resolves_at_handle_to_brand(): void
    {
        Brand::factory()->create(['name' => 'Glossier', 'aliases' => [], 'social_handles' => ['glossier']]);

        $this->assertSame('Glossier', (new BrandLexicon)->resolveHandle('@glossier'));
        $this->assertNull((new BrandLexicon)->resolveHandle('@unknownbrand'));
    }
}
