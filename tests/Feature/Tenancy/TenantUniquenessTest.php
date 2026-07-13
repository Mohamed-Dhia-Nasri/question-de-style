<?php

namespace Tests\Feature\Tenancy;

use App\Modules\CRM\Models\PlatformAccount;
use App\Modules\Monitoring\Models\ContentItem;
use App\Modules\Monitoring\Models\Story;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ADR-0019 — natural keys are unique WITHIN a tenant, not globally:
 * two tenants may track the same public handle or platform item.
 */
class TenantUniquenessTest extends TestCase
{
    use RefreshDatabase;

    public function test_same_platform_handle_is_allowed_in_two_tenants(): void
    {
        PlatformAccount::factory()->create(['platform' => 'INSTAGRAM', 'handle' => 'shared.handle']);

        $tenantB = $this->makeTenant('Tenant B');

        $accountB = $this->withTenant(
            $tenantB,
            fn () => PlatformAccount::factory()->create(['platform' => 'INSTAGRAM', 'handle' => 'shared.handle']),
        );

        $this->assertSame($tenantB->id, $accountB->tenant_id);
    }

    public function test_same_platform_handle_is_rejected_within_one_tenant(): void
    {
        PlatformAccount::factory()->create(['platform' => 'INSTAGRAM', 'handle' => 'shared.handle']);

        $this->expectException(QueryException::class);

        PlatformAccount::factory()->create(['platform' => 'INSTAGRAM', 'handle' => 'shared.handle']);
    }

    public function test_same_content_external_id_is_allowed_in_two_tenants(): void
    {
        ContentItem::factory()->create(['platform' => 'INSTAGRAM', 'external_id' => 'IG-POST-1']);

        $tenantB = $this->makeTenant('Tenant B');

        $itemB = $this->withTenant(
            $tenantB,
            fn () => ContentItem::factory()->create(['platform' => 'INSTAGRAM', 'external_id' => 'IG-POST-1']),
        );

        $this->assertSame($tenantB->id, $itemB->tenant_id);
    }

    public function test_same_content_external_id_is_rejected_within_one_tenant(): void
    {
        ContentItem::factory()->create(['platform' => 'INSTAGRAM', 'external_id' => 'IG-POST-1']);

        $this->expectException(QueryException::class);

        ContentItem::factory()->create(['platform' => 'INSTAGRAM', 'external_id' => 'IG-POST-1']);
    }

    public function test_same_story_external_id_is_allowed_in_two_tenants(): void
    {
        Story::factory()->create(['platform' => 'INSTAGRAM', 'external_id' => 'IG-STORY-1']);

        $tenantB = $this->makeTenant('Tenant B');

        $storyB = $this->withTenant(
            $tenantB,
            fn () => Story::factory()->create(['platform' => 'INSTAGRAM', 'external_id' => 'IG-STORY-1']),
        );

        $this->assertSame($tenantB->id, $storyB->tenant_id);
    }
}
