<?php

namespace Tests\Feature\Crm;

use App\Modules\CRM\Models\Creator;
use App\Modules\CRM\Models\SeedingCampaign;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * M3 Step-1 data foundation: every M3-owned entity from the ownership
 * matrix is migrated with its exact canonical shape
 * (docs/30-data-model/00-data-model.md — ENT-Product, ENT-Contact,
 * ENT-BrandPreference, ENT-SeedingCampaign, ENT-Shipment,
 * ENT-CommunicationLog, ENT-DocumentAttachment, ENT-Task + pivots).
 */
class CrmSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_m3_tables_exist(): void
    {
        foreach ([
            'products',
            'contacts',
            'brand_preferences',
            'seeding_campaigns',
            'shipments',
            'communication_logs',
            'document_attachments',
            'tasks',
            'campaign_creator',
            'seeding_campaign_creator',
            'shipment_resulting_content',
        ] as $table) {
            $this->assertTrue(Schema::hasTable($table), "Missing table: {$table}");
        }
    }

    public function test_tables_carry_their_canonical_columns(): void
    {
        $shapes = [
            'products' => ['id', 'brand_id', 'name', 'sku', 'variant', 'unit_value', 'category'],
            'contacts' => ['id', 'creator_id', 'email', 'phone', 'postal_address', 'preferred_channel'],
            'brand_preferences' => ['id', 'creator_id', 'preferred_brands', 'restricted_brands', 'notes'],
            'seeding_campaigns' => ['id', 'campaign_id', 'name', 'seeding_type', 'brand_id', 'product_id', 'status'],
            'shipments' => [
                'id', 'seeding_campaign_id', 'creator_id', 'status', 'tracking_number', 'shipped_at',
                'delivered_at', 'product_id', 'quantity', 'product_value_at_ship', 'posting_required',
                'posted', 'posted_at',
            ],
            'communication_logs' => ['id', 'creator_id', 'campaign_id', 'channel', 'direction', 'summary', 'occurred_at'],
            'document_attachments' => ['id', 'creator_id', 'campaign_id', 'file_name', 'storage_url', 'uploaded_at'],
            'tasks' => ['id', 'title', 'status', 'assignee_user_id', 'due_at', 'creator_id', 'campaign_id'],
            'campaign_creator' => ['id', 'campaign_id', 'creator_id'],
            'seeding_campaign_creator' => ['id', 'seeding_campaign_id', 'creator_id'],
            'shipment_resulting_content' => ['id', 'shipment_id', 'content_item_id'],
        ];

        foreach ($shapes as $table => $columns) {
            $this->assertTrue(
                Schema::hasColumns($table, $columns),
                "Table {$table} is missing one of: ".implode(', ', $columns)
            );
        }
    }

    public function test_contacts_has_no_soft_delete_column(): void
    {
        // DP-005: hard-deletable — soft deletes would block the GDPR erase path.
        $this->assertFalse(Schema::hasColumn('contacts', 'deleted_at'));
    }

    public function test_shipment_requires_product(): void
    {
        // ENT-Shipment.productId is Required — the cross-creator aggregation key (REQ-M3-013).
        $seedingCampaign = SeedingCampaign::factory()->create();
        $creator = Creator::factory()->create();

        $this->expectException(QueryException::class);

        DB::table('shipments')->insert([
            'seeding_campaign_id' => $seedingCampaign->id,
            'creator_id' => $creator->id,
            'status' => 'PENDING',
            'product_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_communication_log_requires_occurred_at(): void
    {
        $creator = Creator::factory()->create();

        $this->expectException(QueryException::class);

        DB::table('communication_logs')->insert([
            'creator_id' => $creator->id,
            'channel' => 'email',
            'direction' => 'outbound',
            'summary' => 'call notes',
            'occurred_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_contact_requires_creator(): void
    {
        // ENT-Contact.creatorId is Required — a contact row is always owned.
        $this->expectException(QueryException::class);

        DB::table('contacts')->insert([
            'creator_id' => null,
            'email' => 'creator@example.test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
