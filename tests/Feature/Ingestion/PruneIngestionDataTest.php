<?php

namespace Tests\Feature\Ingestion;

use App\Platform\AiBudget\Models\AiUsageCounter;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * qds:prune-ingestion-data — AI usage counters age out with the existing
 * telemetry retention window (spec §13: operational counters, no
 * personal data, pruned with telemetry — 90 d default).
 */
class PruneIngestionDataTest extends TestCase
{
    use RefreshDatabase;

    public function test_old_ai_usage_counters_are_pruned_with_the_telemetry_window(): void
    {
        config(['qds.ingestion.telemetry_retention_days' => 90]);

        $tenantId = $this->defaultTenant->id;

        AiUsageCounter::query()->create([
            'capability' => 'embedding',
            'tenant_id' => $tenantId,
            'usage_date' => CarbonImmutable::now()->subDays(91)->toDateString(),
            'units' => 10,
            'estimated_cost_micro_usd' => 1200,
        ]);
        AiUsageCounter::query()->create([
            'capability' => 'embedding',
            'tenant_id' => $tenantId,
            'usage_date' => CarbonImmutable::now()->toDateString(),
            'units' => 5,
            'estimated_cost_micro_usd' => 600,
        ]);

        $this->artisan('qds:prune-ingestion-data')
            ->expectsOutputToContain('1 AI usage counter rows')
            ->assertExitCode(0);

        $this->assertSame(1, AiUsageCounter::query()->count());
        $this->assertSame(5, (int) AiUsageCounter::query()->value('units'));
    }
}
