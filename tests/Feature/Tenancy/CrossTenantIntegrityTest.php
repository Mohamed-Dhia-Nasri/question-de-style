<?php

namespace Tests\Feature\Tenancy;

use App\Modules\CRM\Models\Campaign;
use App\Modules\CRM\Models\Contact;
use App\Modules\CRM\Models\Creator;
use App\Modules\CRM\Models\SeedingCampaign;
use App\Modules\CRM\Models\Shipment;
use App\Shared\Enums\RoleName;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ADR-0019 — cross-tenant relationships are STRUCTURALLY impossible:
 * composite (fk, tenant_id) foreign keys reject any row that would link
 * records of two different tenants, regardless of application bugs.
 */
class CrossTenantIntegrityTest extends TestCase
{
    use RefreshDatabase;

    public function test_campaign_cannot_attach_a_creator_from_another_tenant(): void
    {
        $campaign = Campaign::factory()->create();

        $tenantB = $this->makeTenant('Tenant B');
        $foreignCreator = $this->withTenant($tenantB, fn () => Creator::factory()->create());

        $this->expectException(QueryException::class);

        $campaign->creators()->attach($foreignCreator->id);
    }

    public function test_seeding_campaign_cannot_attach_a_creator_from_another_tenant(): void
    {
        $seeding = SeedingCampaign::factory()->create();

        $tenantB = $this->makeTenant('Tenant B');
        $foreignCreator = $this->withTenant($tenantB, fn () => Creator::factory()->create());

        $this->expectException(QueryException::class);

        $seeding->creators()->attach($foreignCreator->id);
    }

    public function test_shipment_cannot_reference_a_creator_from_another_tenant(): void
    {
        $seeding = SeedingCampaign::factory()->create();

        $tenantB = $this->makeTenant('Tenant B');
        $foreignCreator = $this->withTenant($tenantB, fn () => Creator::factory()->create());

        $this->expectException(QueryException::class);

        Shipment::factory()->create([
            'seeding_campaign_id' => $seeding->id,
            'creator_id' => $foreignCreator->id,
        ]);
    }

    public function test_contact_cannot_belong_to_a_creator_from_another_tenant(): void
    {
        $tenantB = $this->makeTenant('Tenant B');
        $foreignCreator = $this->withTenant($tenantB, fn () => Creator::factory()->create());

        $this->expectException(QueryException::class);

        Contact::factory()->create(['creator_id' => $foreignCreator->id]);
    }

    public function test_raw_sql_cannot_forge_a_cross_tenant_pivot_row(): void
    {
        $campaign = Campaign::factory()->create();

        $tenantB = $this->makeTenant('Tenant B');
        $foreignCreator = $this->withTenant($tenantB, fn () => Creator::factory()->create());

        $this->expectException(QueryException::class);

        // Even stamping the pivot with the foreign tenant fails: the
        // campaign-side composite FK then disagrees.
        DB::table('campaign_creator')->insert([
            'campaign_id' => $campaign->id,
            'creator_id' => $foreignCreator->id,
            'tenant_id' => $tenantB->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_route_model_binding_is_tenant_scoped(): void
    {
        // SetTenantContext is priority-pinned BEFORE SubstituteBindings
        // (bootstrap/app.php), so a foreign tenant's id 404s at binding
        // time instead of resolving (adversarial-review fix).
        $this->seedRoles();
        $admin = $this->makeUser(RoleName::Admin);

        $ownCreator = Creator::factory()->create();

        $tenantB = $this->makeTenant('Tenant B');
        $foreignCreator = $this->withTenant($tenantB, fn () => Creator::factory()->create());

        $this->actingAs($admin)
            ->get(route('crm.creators.show', $ownCreator))
            ->assertOk();

        $this->actingAs($admin)
            ->get(route('crm.creators.show', $foreignCreator->id))
            ->assertNotFound();
    }

    public function test_isolated_tenant_pairs_from_the_shared_helper(): void
    {
        [$tenantA, $tenantB] = $this->makeTenantPair();

        $this->assertNotSame($tenantA->id, $tenantB->id);
        $this->assertNotNull($tenantA->owner);
        $this->assertNotNull($tenantB->owner);
        $this->assertSame($tenantA->id, $tenantA->owner->tenant_id);
        $this->assertSame($tenantB->id, $tenantB->owner->tenant_id);

        $creatorA = $this->withTenant($tenantA, fn () => Creator::factory()->create());
        $creatorB = $this->withTenant($tenantB, fn () => Creator::factory()->create());

        $this->assertSame($tenantA->id, $creatorA->tenant_id);
        $this->assertSame($tenantB->id, $creatorB->tenant_id);

        $this->assertFalse(
            $this->withTenant($tenantA, fn () => Creator::query()->whereKey($creatorB->id)->exists()),
            'tenant A context must not see tenant B records',
        );
    }
}
