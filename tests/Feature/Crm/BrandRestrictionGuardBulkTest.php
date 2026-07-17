<?php

namespace Tests\Feature\Crm;

use App\Modules\CRM\Models\Brand;
use App\Modules\CRM\Models\BrandPreference;
use App\Modules\CRM\Models\Creator;
use App\Modules\CRM\Services\BrandRestrictionGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Bulk companion to the AC-M3-007 hard filter: the roster picker needs to
 * know, for a batch of candidates, which ones a brand restriction skips —
 * WITHOUT one guard call per creator. Matching must stay byte-identical to
 * BrandRestrictionGuard::assertNotRestricted (trim + case-insensitive, in
 * PHP, unioned across every preference row of a creator).
 */
class BrandRestrictionGuardBulkTest extends TestCase
{
    use RefreshDatabase;

    private function guard(): BrandRestrictionGuard
    {
        return app(BrandRestrictionGuard::class);
    }

    public function test_it_matches_after_trimming_and_case_folding(): void
    {
        $creator = Creator::factory()->create();
        BrandPreference::factory()->create([
            'creator_id' => $creator->id,
            'restricted_brands' => ['  nike '],
        ]);

        $this->assertSame(
            [$creator->id],
            $this->guard()->restrictedCreatorIdsForName([$creator->id], 'NIKE'),
        );
    }

    public function test_it_unions_across_multiple_preference_rows(): void
    {
        $creator = Creator::factory()->create();
        BrandPreference::factory()->create([
            'creator_id' => $creator->id,
            'restricted_brands' => ['Adidas'],
        ]);
        BrandPreference::factory()->create([
            'creator_id' => $creator->id,
            'restricted_brands' => ['Puma'],
        ]);

        $this->assertSame(
            [$creator->id],
            $this->guard()->restrictedCreatorIdsForName([$creator->id], 'puma'),
        );
    }

    public function test_it_treats_a_null_restricted_list_as_no_restriction(): void
    {
        $creator = Creator::factory()->create();
        BrandPreference::factory()->create([
            'creator_id' => $creator->id,
            'restricted_brands' => null,
        ]);

        $this->assertSame([], $this->guard()->restrictedCreatorIdsForName([$creator->id], 'Nike'));
    }

    public function test_it_excludes_creators_without_a_matching_preference(): void
    {
        $restricted = Creator::factory()->create();
        BrandPreference::factory()->create([
            'creator_id' => $restricted->id,
            'restricted_brands' => ['Nike'],
        ]);
        $unrelated = Creator::factory()->create();
        BrandPreference::factory()->create([
            'creator_id' => $unrelated->id,
            'restricted_brands' => ['Adidas'],
        ]);
        $noPreferences = Creator::factory()->create();

        $this->assertSame(
            [$restricted->id],
            $this->guard()->restrictedCreatorIdsForName(
                [$restricted->id, $unrelated->id, $noPreferences->id],
                'Nike',
            ),
        );
    }

    public function test_it_only_returns_ids_that_were_asked_about(): void
    {
        $asked = Creator::factory()->create();
        BrandPreference::factory()->create([
            'creator_id' => $asked->id,
            'restricted_brands' => ['Nike'],
        ]);
        $notAsked = Creator::factory()->create();
        BrandPreference::factory()->create([
            'creator_id' => $notAsked->id,
            'restricted_brands' => ['Nike'],
        ]);

        $this->assertSame(
            [$asked->id],
            $this->guard()->restrictedCreatorIdsForName([$asked->id], 'Nike'),
        );
    }

    public function test_the_brand_overload_delegates_with_the_brand_name(): void
    {
        $creator = Creator::factory()->create();
        $brand = Brand::factory()->create(['name' => 'Aurelia']);
        BrandPreference::factory()->create([
            'creator_id' => $creator->id,
            'restricted_brands' => ['aurelia'],
        ]);

        $this->assertSame(
            [$creator->id],
            $this->guard()->restrictedCreatorIds([$creator->id], $brand),
        );
    }

    public function test_an_empty_candidate_list_returns_empty_without_querying(): void
    {
        $this->assertSame([], $this->guard()->restrictedCreatorIdsForName([], 'Nike'));
    }
}
