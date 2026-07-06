<?php

namespace Tests\Feature\Monitoring;

use App\Modules\Monitoring\Models\MetricSnapshot;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\TestCase;

/**
 * ENT-MetricSnapshot is the sole substrate for historical growth
 * (ADR-0003): history accumulates, it is never mutated. Enforced twice —
 * at the model layer and by a database trigger.
 */
class AppendOnlyMetricSnapshotTest extends TestCase
{
    use RefreshDatabase;

    public function test_account_level_and_content_level_snapshots_can_be_written(): void
    {
        $account = MetricSnapshot::factory()->create();
        $content = MetricSnapshot::factory()->contentLevel()->create();

        $this->assertNotNull($account->platform_account_id);
        $this->assertNull($account->content_item_id);
        $this->assertNull($content->platform_account_id);
        $this->assertNotNull($content->content_item_id);
    }

    public function test_snapshot_must_target_exactly_one_of_account_or_content(): void
    {
        $this->expectException(QueryException::class);

        MetricSnapshot::factory()->create([
            'platform_account_id' => null,
            'content_item_id' => null,
        ]);
    }

    public function test_model_updates_are_rejected(): void
    {
        $snapshot = MetricSnapshot::factory()->create();

        $this->expectException(LogicException::class);

        $snapshot->update(['captured_at' => now()->addDay()]);
    }

    public function test_model_deletes_are_rejected(): void
    {
        $snapshot = MetricSnapshot::factory()->create();

        $this->expectException(LogicException::class);

        $snapshot->delete();
    }

    public function test_database_trigger_rejects_raw_updates(): void
    {
        $snapshot = MetricSnapshot::factory()->create();

        try {
            DB::table('metric_snapshots')->where('id', $snapshot->id)->update(['captured_at' => now()->addDay()]);
            $this->fail('Raw UPDATE on metric_snapshots should have been rejected by the trigger.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('append-only', $e->getMessage());
        }
    }

    public function test_database_trigger_rejects_raw_deletes(): void
    {
        $snapshot = MetricSnapshot::factory()->create();

        try {
            DB::table('metric_snapshots')->where('id', $snapshot->id)->delete();
            $this->fail('Raw DELETE on metric_snapshots should have been rejected by the trigger.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('append-only', $e->getMessage());
        }
    }

    public function test_history_accumulates_as_an_ordered_series(): void
    {
        $first = MetricSnapshot::factory()->create(['captured_at' => now()->subDays(2)]);

        MetricSnapshot::factory()->create([
            'platform_account_id' => $first->platform_account_id,
            'captured_at' => now()->subDay(),
        ]);
        MetricSnapshot::factory()->create([
            'platform_account_id' => $first->platform_account_id,
            'captured_at' => now(),
        ]);

        $series = MetricSnapshot::query()
            ->where('platform_account_id', $first->platform_account_id)
            ->orderBy('captured_at')
            ->pluck('captured_at');

        $this->assertCount(3, $series);
        $this->assertTrue($series->first()->lt($series->last()));
    }
}
