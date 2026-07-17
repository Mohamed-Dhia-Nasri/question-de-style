<?php

namespace Tests\Feature\Settings;

use App\Models\Tenant;
use App\Modules\Monitoring\Models\MonitoringSetting;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Per-tenant monitoring settings storage (ADR-0025): append-only latest-
 * row-wins rows, NOT NULL tenant ownership, and DB CHECK ranges mirroring
 * the page validation.
 */
class MonitoringSettingsSchemaTest extends TestCase
{
    use RefreshDatabase;

    private function makeRow(Tenant $tenant, array $overrides = []): MonitoringSetting
    {
        $row = new MonitoringSetting(array_merge([
            'shipment_window_days' => 60,
            'engagement_trend_window_days' => 30,
            'story_retention_days' => 180,
            'communication_retention_days' => 0,
        ], $overrides));
        $row->tenant_id = $tenant->id;
        $row->save();

        return $row;
    }

    public function test_rows_persist_with_tenant_ownership_and_history_accumulates(): void
    {
        $tenant = Tenant::factory()->create();

        $first = $this->makeRow($tenant);
        $second = $this->makeRow($tenant, ['shipment_window_days' => 45]);

        $this->assertDatabaseCount('monitoring_settings', 2);
        $this->assertSame($tenant->id, $first->refresh()->tenant_id);
        $this->assertSame(45, MonitoringSetting::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)->latest('id')->first()->shipment_window_days);
        $this->assertNotSame($first->id, $second->id);
    }

    public function test_tenant_id_is_required(): void
    {
        $this->expectException(QueryException::class);

        DB::table('monitoring_settings')->insert([
            'shipment_window_days' => 60,
            'engagement_trend_window_days' => 30,
            'story_retention_days' => 180,
            'communication_retention_days' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_check_constraint_rejects_out_of_range_values(): void
    {
        $tenant = Tenant::factory()->create();

        $this->expectException(QueryException::class);
        $this->makeRow($tenant, ['shipment_window_days' => 0]); // gift window has no off-state
    }
}
