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

    /** Seed a usage row for TODAY (or the given date) without touching record(). */
    private function seedUsage(int $tenantId, int $units, ?string $date = null): void
    {
        AiUsageCounter::query()->create([
            'capability' => 'embedding',
            'tenant_id' => $tenantId,
            'usage_date' => $date ?? CarbonImmutable::now()->toDateString(),
            'units' => $units,
        ]);
    }

    public function test_read_only_mode_blocks_every_priority_instantly(): void
    {
        $this->configureBudget();
        $guard = app(AiBudgetGuard::class);
        $tenantId = $this->defaultTenant->id;

        Cache::forever(AiBudgetGuard::READ_ONLY_CACHE_KEY, true);

        foreach ([Priority::High, Priority::Medium] as $priority) {
            $decision = $guard->allows('embedding', $tenantId, 1, $priority);
            $this->assertFalse($decision->allowed);
            $this->assertSame('read-only', $decision->reason);
        }

        // A cached FALSE overrides even a truthy config default.
        config(['qds.ai_budget.read_only' => true]);
        Cache::forever(AiBudgetGuard::READ_ONLY_CACHE_KEY, false);
        $this->assertFalse($guard->readOnly());
        $this->assertTrue($guard->allows('embedding', $tenantId, 1, Priority::Medium)->allowed);

        // With NO cached flag the config default decides.
        Cache::forget(AiBudgetGuard::READ_ONLY_CACHE_KEY);
        $this->assertTrue($guard->readOnly());
    }

    public function test_unknown_capability_fails_closed(): void
    {
        $this->configureBudget();

        $decision = app(AiBudgetGuard::class)->allows('vlm_verification', $this->defaultTenant->id, 1, Priority::High);

        $this->assertFalse($decision->allowed);
        $this->assertSame('unknown-capability', $decision->reason);
    }

    public function test_per_post_ceiling_applies_to_medium_priority(): void
    {
        $this->configureBudget();
        $guard = app(AiBudgetGuard::class);
        $tenantId = $this->defaultTenant->id;

        $decision = $guard->allows('embedding', $tenantId, 13, Priority::Medium);
        $this->assertFalse($decision->allowed);
        $this->assertSame('per-post-exceeded', $decision->reason);

        $this->assertTrue($guard->allows('embedding', $tenantId, 12, Priority::Medium)->allowed);
    }

    public function test_medium_priority_denies_when_the_tenant_daily_budget_is_exhausted(): void
    {
        $this->configureBudget();
        $guard = app(AiBudgetGuard::class);
        $tenantId = $this->defaultTenant->id;

        $this->seedUsage($tenantId, 95); // 95 of 100 daily units used

        $decision = $guard->allows('embedding', $tenantId, 10, Priority::Medium);
        $this->assertFalse($decision->allowed);
        $this->assertSame('tenant-daily-exhausted', $decision->reason);

        // The remaining 5 still fit.
        $this->assertTrue($guard->allows('embedding', $tenantId, 5, Priority::Medium)->allowed);
    }

    public function test_high_priority_ignores_soft_caps_but_stops_at_the_global_hard_cap(): void
    {
        $this->configureBudget(['capabilities' => ['embedding' => ['global_daily_hard_units' => 200]]]);
        $guard = app(AiBudgetGuard::class);
        $tenantId = $this->defaultTenant->id;

        $this->seedUsage($tenantId, 195); // tenant daily (100) long gone; hard cap at 200

        // High does not care about the exhausted tenant budget…
        $this->assertTrue($guard->allows('embedding', $tenantId, 5, Priority::High)->allowed);

        // …but the global HARD cap stops even High.
        $decision = $guard->allows('embedding', $tenantId, 6, Priority::High);
        $this->assertFalse($decision->allowed);
        $this->assertSame('global-hard-exhausted', $decision->reason);
    }

    public function test_global_soft_budget_sums_across_tenants_for_medium(): void
    {
        $this->configureBudget(['capabilities' => ['embedding' => [
            'tenant_daily_units' => 10000,
            'tenant_monthly_units' => 100000,
            'global_daily_units' => 50,
        ]]]);
        $guard = app(AiBudgetGuard::class);

        // ANOTHER tenant's spend counts toward the global dimension — this
        // is why the models are not TenantScoped.
        $this->seedUsage($this->makeTenant('Tenant B')->id, 45);

        $decision = $guard->allows('embedding', $this->defaultTenant->id, 10, Priority::Medium);
        $this->assertFalse($decision->allowed);
        $this->assertSame('global-daily-exhausted', $decision->reason);

        $this->assertTrue($guard->allows('embedding', $this->defaultTenant->id, 5, Priority::Medium)->allowed);
    }

    public function test_tenant_quota_override_beats_the_config_default(): void
    {
        $this->configureBudget();
        $tenantId = $this->defaultTenant->id;

        TenantAiQuota::query()->create(['tenant_id' => $tenantId, 'capability' => 'embedding', 'daily_units' => 5, 'monthly_units' => null]);
        $guard = app(AiBudgetGuard::class);

        $decision = $guard->allows('embedding', $tenantId, 6, Priority::Medium);
        $this->assertFalse($decision->allowed);
        $this->assertSame('tenant-daily-exhausted', $decision->reason);

        $this->assertTrue($guard->allows('embedding', $tenantId, 5, Priority::Medium)->allowed);
        // High ignores the override entirely (tenant caps are soft).
        $this->assertTrue($guard->allows('embedding', $tenantId, 6, Priority::High)->allowed);
    }

    public function test_monthly_budget_is_the_sum_of_the_months_days_and_rolls_over(): void
    {
        $this->configureBudget(['capabilities' => ['embedding' => [
            'tenant_daily_units' => 1000,
            'tenant_monthly_units' => 100,
        ]]]);
        $guard = app(AiBudgetGuard::class);
        $tenantId = $this->defaultTenant->id;

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-06-30 12:00:00'));
        $this->seedUsage($tenantId, 60, '2026-06-01');
        $this->seedUsage($tenantId, 40, '2026-06-30');

        // June: 60 + 40 = the whole monthly budget.
        $decision = $guard->allows('embedding', $tenantId, 1, Priority::Medium);
        $this->assertFalse($decision->allowed);
        $this->assertSame('tenant-monthly-exhausted', $decision->reason);

        // July 1st: June's spend counts toward NOTHING.
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-01 00:05:00'));
        $this->assertTrue($guard->allows('embedding', $tenantId, 1, Priority::Medium)->allowed);

        CarbonImmutable::setTestNow();
    }

    public function test_record_increments_one_row_atomically_and_prices_units(): void
    {
        $this->configureBudget();
        $guard = app(AiBudgetGuard::class);
        $tenantId = $this->defaultTenant->id;

        $guard->record('embedding', $tenantId, 8, postsProcessed: 1);
        $guard->record('embedding', $tenantId, 4, postsProcessed: 1, postsSkippedBudget: 2, postsSkippedNoCandidates: 3);

        // ONE row per (capability, tenant, day) — the second call incremented, never duplicated.
        $this->assertSame(1, AiUsageCounter::query()->count());

        $row = AiUsageCounter::query()->firstOrFail();
        $this->assertSame(12, $row->units);
        $this->assertSame(12 * 120, $row->estimated_cost_micro_usd); // units × price_micro_usd_per_unit
        $this->assertSame(2, $row->posts_processed);
        $this->assertSame(2, $row->posts_skipped_budget);
        $this->assertSame(3, $row->posts_skipped_no_candidates);
        $this->assertSame($tenantId, $row->tenant_id);
        $this->assertSame(CarbonImmutable::now()->toDateString(), $row->usage_date->toDateString());
    }

    public function test_threshold_crossings_raise_deduplicated_tenant_attributed_alerts(): void
    {
        $this->configureBudget(); // tenant daily 100 is the only dimension in alert reach
        $guard = app(AiBudgetGuard::class);
        $tenantId = $this->defaultTenant->id;

        $guard->record('embedding', $tenantId, 85); // crosses 50 and 80

        $alerts = IngestionAlert::query()->where('alert_type', AlertType::AiBudgetThreshold->value)->get();
        $this->assertCount(2, $alerts);
        $this->assertTrue($alerts->every(fn (IngestionAlert $alert): bool => $alert->tenant_id === $tenantId));
        $this->assertTrue($alerts->every(fn (IngestionAlert $alert): bool => $alert->severity === 'warning'));

        $guard->record('embedding', $tenantId, 15); // 100 of 100 — crosses 95 and 100

        $alerts = IngestionAlert::query()->where('alert_type', AlertType::AiBudgetThreshold->value)->get();
        $this->assertCount(4, $alerts);
        $this->assertSame(1, $alerts->where('severity', 'critical')->count()); // only the 100 % crossing
        $this->assertNotNull($alerts->firstWhere(
            'source', 'embedding:tenant-daily:100:'.CarbonImmutable::now()->toDateString(),
        ));

        // Spend past 100 % crosses nothing new → no alert spam.
        $guard->record('embedding', $tenantId, 5);
        $this->assertSame(4, IngestionAlert::query()->where('alert_type', AlertType::AiBudgetThreshold->value)->count());
    }
}
