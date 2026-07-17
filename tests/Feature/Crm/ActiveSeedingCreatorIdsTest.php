<?php

namespace Tests\Feature\Crm;

use App\Modules\CRM\Models\Creator;
use App\Modules\CRM\Models\SeedingCampaign;
use App\Modules\CRM\Services\ActiveSeedingCreatorIds;
use App\Shared\Enums\SeedingCampaignStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ActiveSeedingCreatorIds — the single owner of the "active seeding"
 * definition (ACTIVE + SHIPPING) behind the monitoring "Active seeding
 * only" filter. Tenant-scoped via SeedingCampaign's BelongsToTenant.
 */
class ActiveSeedingCreatorIdsTest extends TestCase
{
    use RefreshDatabase;

    private function runWithCreators(SeedingCampaignStatus $status, Creator ...$creators): SeedingCampaign
    {
        $run = SeedingCampaign::factory()->create(['status' => $status]);
        $run->creators()->attach(array_map(fn (Creator $c): int => $c->id, $creators));

        return $run;
    }

    public function test_returns_only_creators_of_active_and_shipping_campaigns(): void
    {
        $active = Creator::factory()->create();
        $shipping = Creator::factory()->create();
        $this->runWithCreators(SeedingCampaignStatus::Active, $active);
        $this->runWithCreators(SeedingCampaignStatus::Shipping, $shipping);

        // Every OTHER status must be excluded — iterate the enum, no hardcoded list.
        foreach (SeedingCampaignStatus::cases() as $status) {
            if (in_array($status, ActiveSeedingCreatorIds::ACTIVE_STATUSES, true)) {
                continue;
            }
            $this->runWithCreators($status, Creator::factory()->create());
        }

        $ids = app(ActiveSeedingCreatorIds::class)->forCurrentTenant();

        $this->assertEqualsCanonicalizing([$active->id, $shipping->id], $ids);
    }

    public function test_deduplicates_a_creator_enrolled_in_two_active_campaigns(): void
    {
        $creator = Creator::factory()->create();
        $this->runWithCreators(SeedingCampaignStatus::Active, $creator);
        $this->runWithCreators(SeedingCampaignStatus::Shipping, $creator);

        $this->assertSame([$creator->id], app(ActiveSeedingCreatorIds::class)->forCurrentTenant());
    }

    public function test_scoped_to_the_current_tenant(): void
    {
        $tenantB = $this->makeTenant('Tenant B');
        $this->withTenant($tenantB, function (): void {
            $this->runWithCreators(SeedingCampaignStatus::Active, Creator::factory()->create());
        });

        $this->assertSame([], app(ActiveSeedingCreatorIds::class)->forCurrentTenant());
    }

    public function test_returns_empty_array_when_nothing_is_active(): void
    {
        $this->runWithCreators(SeedingCampaignStatus::Completed, Creator::factory()->create());

        $this->assertSame([], app(ActiveSeedingCreatorIds::class)->forCurrentTenant());
    }
}
