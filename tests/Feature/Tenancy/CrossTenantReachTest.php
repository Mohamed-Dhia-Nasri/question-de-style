<?php

namespace Tests\Feature\Tenancy;

use App\Models\Tenant;
use App\Modules\CRM\Models\PlatformAccount;
use App\Modules\Monitoring\Models\ContentItem;
use App\Modules\Monitoring\Models\ReachConfiguration;
use App\Platform\Enrichment\Reach\ReachCalculator;
use App\Shared\Enums\MetricTier;
use App\Shared\Enums\Platform;
use App\Shared\ValueObjects\MetricValue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Reach-specific cross-tenant isolation (REQ-M1-006, ADR-0022, ADR-0019).
 * ReachCalculator::activeConfiguration() relies entirely on the model's
 * TenantScope (no explicit tenant_id filter in the query) — this proves
 * that reliance actually holds across two real tenants: a configuration
 * belonging to one tenant never leaks into another tenant's computation,
 * both tenants can keep their own ACTIVE configuration simultaneously
 * (the one-active-per-tenant constraint is a partial unique on tenant_id,
 * not global), and each tenant's computed reach uses only its own weights.
 */
class CrossTenantReachTest extends TestCase
{
    use RefreshDatabase;

    /** Build a ContentItem (with a follower account) inside the given tenant's context. */
    private function contentIn(Tenant $tenant, int $views, int $followers = 0): ContentItem
    {
        return $this->withTenant($tenant, function () use ($views, $followers): ContentItem {
            $account = PlatformAccount::factory()->create([
                'follower_count' => new MetricValue($followers, MetricTier::Public, 'followers'),
            ]);

            return ContentItem::factory()->create([
                'platform_account_id' => $account->id,
                'platform' => Platform::Instagram,
                'public_metrics' => [new MetricValue($views, MetricTier::Public, 'views')],
            ]);
        });
    }

    public function test_tenant_a_active_configuration_never_computes_tenant_b_content(): void
    {
        [$tenantA, $tenantB] = $this->makeTenantPair();

        // Only tenant A has an ACTIVE configuration.
        $this->withTenant($tenantA, fn () => ReachConfiguration::factory()->active()->create());

        // Content built in tenant B's context, with both views and a follower account.
        $contentB = $this->contentIn($tenantB, 1000, 5000);

        $resultB = $this->withTenant(
            $tenantB,
            fn () => app(ReachCalculator::class)->calculate($contentB)
        );

        $this->assertNull($resultB, "Tenant A's active configuration must never be used to compute tenant B's reach.");
        $this->assertDatabaseCount('reach_results', 0);
    }

    public function test_each_tenant_can_have_its_own_active_configuration_simultaneously(): void
    {
        [$tenantA, $tenantB] = $this->makeTenantPair();

        $this->withTenant($tenantA, fn () => ReachConfiguration::factory()->active()->create());
        $this->withTenant($tenantB, fn () => ReachConfiguration::factory()->active()->create());

        $activeInA = $this->withTenant($tenantA, fn () => app(ReachCalculator::class)->activeConfiguration());
        $activeInB = $this->withTenant($tenantB, fn () => app(ReachCalculator::class)->activeConfiguration());

        $this->assertNotNull($activeInA, "Tenant A's own active configuration must still be active.");
        $this->assertNotNull($activeInB, "Tenant B's own active configuration must still be active.");
        $this->assertTrue($activeInA->isActive());
        $this->assertTrue($activeInB->isActive());
        $this->assertNotSame($activeInA->id, $activeInB->id);
        $this->assertSame($tenantA->id, $activeInA->tenant_id);
        $this->assertSame($tenantB->id, $activeInB->tenant_id);
    }

    public function test_each_tenants_computed_reach_uses_only_its_own_weights(): void
    {
        [$tenantA, $tenantB] = $this->makeTenantPair();

        $this->withTenant($tenantA, fn () => ReachConfiguration::factory()->active()->create([
            'params' => ['view_weight' => 0.7, 'follower_weight' => 0.0],
        ]));
        $this->withTenant($tenantB, fn () => ReachConfiguration::factory()->active()->create([
            'params' => ['view_weight' => 0.2, 'follower_weight' => 0.0],
        ]));

        // Identical content (views=1000, followers=0) in each tenant.
        $contentA = $this->contentIn($tenantA, 1000, 0);
        $contentB = $this->contentIn($tenantB, 1000, 0);

        $resultA = $this->withTenant($tenantA, fn () => app(ReachCalculator::class)->calculate($contentA)->fresh());
        $resultB = $this->withTenant($tenantB, fn () => app(ReachCalculator::class)->calculate($contentB)->fresh());

        // round(0.7 * 1000) = 700 for A; round(0.2 * 1000) = 200 for B.
        $this->assertSame(700.0, $resultA->value->amount);
        $this->assertSame(200.0, $resultB->value->amount);
    }
}
