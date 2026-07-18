<?php

namespace Tests\Feature\Crm;

use App\Modules\CRM\Exceptions\BrandRestrictionViolation;
use App\Modules\CRM\Models\Brand;
use App\Modules\CRM\Models\BrandPreference;
use App\Modules\CRM\Models\Creator;
use App\Modules\CRM\Services\BrandRestrictionGuard;
use App\Shared\Enums\RoleName;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Item 5a: a brand no-go list must match a brand's OTHER names (aliases),
 * not just its canonical name. Both the throwing path
 * (assertNotRestricted) and the bulk path (restrictedCreatorIds) fold the
 * same needle set (name + aliases) so they cannot diverge; the typed-name
 * path (restrictedCreatorIdsForName) stays name-only by design because the
 * wizard's brand may not exist yet and carries no aliases.
 */
class BrandRestrictionAliasTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsCrmStaff(): void
    {
        $this->seedRoles();
        $this->actingAs($this->makeUser(RoleName::InfluencerRelationsManager));
    }

    public function test_a_creator_who_restricts_a_brand_alias_is_now_flagged_and_blocked(): void
    {
        $this->actingAsCrmStaff();
        $brand = Brand::factory()->create(['name' => 'Aurelia', 'aliases' => ['Aurelia Cosmetics', 'AC Beauty']]);
        $creator = Creator::factory()->create();
        BrandPreference::factory()->create(['creator_id' => $creator->id, 'restricted_brands' => ['ac beauty']]); // alias, not the name

        $guard = app(BrandRestrictionGuard::class);
        $this->assertSame([$creator->id], $guard->restrictedCreatorIds([$creator->id], $brand));
        $this->expectException(BrandRestrictionViolation::class);
        $guard->assertNotRestricted($creator, $brand);
    }

    public function test_name_only_string_matcher_ignores_aliases(): void
    {
        $this->actingAsCrmStaff();
        $creator = Creator::factory()->create();
        BrandPreference::factory()->create(['creator_id' => $creator->id, 'restricted_brands' => ['ac beauty']]);

        $guard = app(BrandRestrictionGuard::class);
        // The typed-name path (wizard, brand may not exist yet) matches the name only.
        $this->assertSame([], $guard->restrictedCreatorIdsForName([$creator->id], 'Aurelia'));
        $this->assertSame([$creator->id], $guard->restrictedCreatorIdsForName([$creator->id], 'AC Beauty'));
    }

    public function test_name_match_still_works_and_folding_is_unicode_safe(): void
    {
        $this->actingAsCrmStaff();
        $brand = Brand::factory()->create(['name' => 'Müller', 'aliases' => null]);
        $creator = Creator::factory()->create();
        BrandPreference::factory()->create(['creator_id' => $creator->id, 'restricted_brands' => ['  MÜLLER ']]);

        $this->assertSame([$creator->id],
            app(BrandRestrictionGuard::class)->restrictedCreatorIds([$creator->id], $brand));
    }
}
