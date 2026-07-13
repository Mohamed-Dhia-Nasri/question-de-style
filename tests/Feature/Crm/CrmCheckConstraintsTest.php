<?php

namespace Tests\Feature\Crm;

use App\Modules\CRM\Models\Brand;
use App\Modules\CRM\Models\Creator;
use App\Modules\CRM\Models\PlatformAccount;
use App\Modules\CRM\Models\Product;
use App\Modules\CRM\Models\SeedingCampaign;
use App\Shared\Enums\Platform;
use App\Shared\Enums\SeedingType;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Database-level guardrails for the M3 Step-1 closed enum sets: every
 * closed-enum column rejects out-of-set values via a Postgres CHECK
 * mirroring the glossary (spec doctrine §4), including the four confirmed
 * seeding_type tokens (spec D1, module-3 §2.5 / AC-M3-010).
 */
class CrmCheckConstraintsTest extends TestCase
{
    use RefreshDatabase;

    public function test_one_account_per_platform_per_creator_is_db_enforced(): void
    {
        // Deep-review L1: the app-layer check-then-insert race needs a DB
        // backstop — the partial unique index rejects a second same-platform
        // account for one creator even with a DIFFERENT handle (which the
        // (platform, handle) key cannot catch).
        $creator = Creator::factory()->create();
        PlatformAccount::factory()->create([
            'creator_id' => $creator->id,
            'platform' => Platform::Instagram,
            'handle' => 'alpha',
        ]);

        try {
            PlatformAccount::factory()->create([
                'creator_id' => $creator->id,
                'platform' => Platform::Instagram,
                'handle' => 'beta',
            ]);
            $this->fail('A second INSTAGRAM account for the same creator must violate the L1 backstop index.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('platform_accounts_creator_platform_unique', $e->getMessage());
        }
    }

    public function test_product_category_check_rejects_unknown_sector(): void
    {
        $brand = Brand::factory()->create();

        $this->expectException(QueryException::class);

        DB::table('products')->insert([
            'tenant_id' => $this->defaultTenant->id,
            'brand_id' => $brand->id,
            'name' => 'Serum',
            'category' => 'CRYPTO',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_seeding_type_check_rejects_unknown_variant(): void
    {
        $brand = Brand::factory()->create();

        $this->expectException(QueryException::class);

        DB::table('seeding_campaigns')->insert([
            'tenant_id' => $this->defaultTenant->id,
            'name' => 'Bad variant',
            'seeding_type' => 'LOAN',
            'brand_id' => $brand->id,
            'status' => 'DRAFT',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_all_four_seeding_type_tokens_are_accepted(): void
    {
        // The exact 4-variant closed set confirmed by the product owner (D1):
        // GIFTING / GIFTING_WITH_POST / PAID_PLUS_PRODUCT / ORGANIC.
        foreach (SeedingType::cases() as $type) {
            $seedingCampaign = SeedingCampaign::factory()->ofType($type)->create();

            $this->assertDatabaseHas('seeding_campaigns', [
                'id' => $seedingCampaign->id,
                'seeding_type' => $type->value,
            ]);
        }

        $this->assertSame(4, SeedingCampaign::query()->count());
    }

    public function test_seeding_campaign_status_check_rejects_unknown_status(): void
    {
        $brand = Brand::factory()->create();

        $this->expectException(QueryException::class);

        DB::table('seeding_campaigns')->insert([
            'tenant_id' => $this->defaultTenant->id,
            'name' => 'Bad status',
            'seeding_type' => 'GIFTING',
            'brand_id' => $brand->id,
            'status' => 'ARCHIVED',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_shipment_status_check_rejects_unknown_status(): void
    {
        $seedingCampaign = SeedingCampaign::factory()->create();
        $creator = Creator::factory()->create();
        $product = Product::factory()->create();

        $this->expectException(QueryException::class);

        DB::table('shipments')->insert([
            'tenant_id' => $this->defaultTenant->id,
            'seeding_campaign_id' => $seedingCampaign->id,
            'creator_id' => $creator->id,
            'status' => 'LOST',
            'product_id' => $product->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_task_status_check_rejects_unknown_status(): void
    {
        $this->expectException(QueryException::class);

        DB::table('tasks')->insert([
            'tenant_id' => $this->defaultTenant->id,
            'title' => 'Follow up',
            'status' => 'SNOOZED',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
