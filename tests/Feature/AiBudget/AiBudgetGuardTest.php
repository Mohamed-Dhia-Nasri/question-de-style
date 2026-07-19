<?php

namespace Tests\Feature\AiBudget;

use App\Platform\AiBudget\AiBudgetGuard;
use App\Platform\AiBudget\Models\AiUsageCounter;
use App\Platform\AiBudget\Models\TenantAiQuota;
use App\Platform\AiBudget\Priority;
use App\Platform\AiBudget\TenantQuotaResolver;
use App\Platform\Ingestion\Models\IngestionAlert;
use App\Platform\Ingestion\Support\AlertType;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Spec §10 — capability-keyed AI budget governance: priority-dependent
 * dimension exhaustion, per-tenant quota overrides, month rollover,
 * atomic counter increments, deduplicated threshold alerts, read-only.
 */
class AiBudgetGuardTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Small, test-friendly budget numbers; individual tests override
     * single keys via the $overrides tree.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function configureBudget(array $overrides = []): void
    {
        config(['qds.ai_budget' => array_replace_recursive([
            'read_only' => false,
            'alert_thresholds' => [50, 80, 95, 100],
            'capabilities' => [
                'embedding' => [
                    'price_micro_usd_per_unit' => 120,
                    'per_post_units' => 12,
                    'tenant_daily_units' => 100,
                    'tenant_monthly_units' => 1000,
                    'global_daily_units' => 10000,
                    'global_daily_hard_units' => 20000,
                    'global_monthly_units' => 100000,
                    'global_monthly_hard_units' => 200000,
                ],
            ],
        ], $overrides)]);
    }

    public function test_shipped_config_defaults_match_the_spec(): void
    {
        $this->assertFalse((bool) config('qds.ai_budget.read_only'));
        $this->assertSame([50, 80, 95, 100], config('qds.ai_budget.alert_thresholds'));
        $this->assertSame(120, config('qds.ai_budget.capabilities.embedding.price_micro_usd_per_unit'));
        $this->assertSame(12, config('qds.ai_budget.capabilities.embedding.per_post_units'));
        $this->assertSame(2000, config('qds.ai_budget.capabilities.embedding.tenant_daily_units'));
        $this->assertSame(40000, config('qds.ai_budget.capabilities.embedding.tenant_monthly_units'));
        $this->assertSame(50000, config('qds.ai_budget.capabilities.embedding.global_daily_units'));
        $this->assertSame(100000, config('qds.ai_budget.capabilities.embedding.global_daily_hard_units'));
        $this->assertSame(1000000, config('qds.ai_budget.capabilities.embedding.global_monthly_units'));
        $this->assertSame(2000000, config('qds.ai_budget.capabilities.embedding.global_monthly_hard_units'));
    }

    public function test_counter_and_quota_rows_persist_with_their_unique_keys(): void
    {
        $tenantId = $this->defaultTenant->id;

        AiUsageCounter::query()->create([
            'capability' => 'embedding',
            'tenant_id' => $tenantId,
            'usage_date' => '2026-07-19',
            'units' => 3,
            'estimated_cost_micro_usd' => 360,
        ]);

        TenantAiQuota::query()->create([
            'tenant_id' => $tenantId,
            'capability' => 'embedding',
            'daily_units' => 100,
            'monthly_units' => null,
        ]);

        $this->assertDatabaseHas('ai_usage_counters', ['capability' => 'embedding', 'units' => 3]);
        $this->assertDatabaseHas('tenant_ai_quotas', ['capability' => 'embedding', 'daily_units' => 100]);

        // The atomic-upsert conflict target: ONE row per (capability, tenant, day).
        $this->expectException(UniqueConstraintViolationException::class);
        AiUsageCounter::query()->create([
            'capability' => 'embedding',
            'tenant_id' => $tenantId,
            'usage_date' => '2026-07-19',
            'units' => 1,
        ]);
    }

    public function test_quota_resolver_defaults_overrides_and_memoization(): void
    {
        $this->configureBudget();
        $tenantId = $this->defaultTenant->id;

        // No override row → config defaults.
        $this->assertSame(['daily' => 100, 'monthly' => 1000], app(TenantQuotaResolver::class)->for($tenantId, 'embedding'));

        // Override daily only; the NULL monthly column keeps the config default.
        TenantAiQuota::query()->create(['tenant_id' => $tenantId, 'capability' => 'embedding', 'daily_units' => 5, 'monthly_units' => null]);

        $resolver = app(TenantQuotaResolver::class);
        $this->assertSame(['daily' => 5, 'monthly' => 1000], $resolver->for($tenantId, 'embedding'));

        // Memoized for THIS instance's life…
        TenantAiQuota::query()->update(['daily_units' => 9]);
        $this->assertSame(['daily' => 5, 'monthly' => 1000], $resolver->for($tenantId, 'embedding'));

        // …while a fresh resolver (not a singleton) reads the new row.
        $this->assertSame(['daily' => 9, 'monthly' => 1000], app(TenantQuotaResolver::class)->for($tenantId, 'embedding'));
    }
}
