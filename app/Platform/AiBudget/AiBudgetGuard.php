<?php

namespace App\Platform\AiBudget;

use App\Platform\AiBudget\Models\AiUsageCounter;
use App\Platform\Ingestion\Observability\AlertService;
use App\Platform\Ingestion\Support\AlertType;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Capability-keyed AI budget governance (spec §10; C builds, D reuses).
 *
 * allows() is the PRE-SPEND gate: read-only mode, the per-post ceiling,
 * tenant daily/monthly (per-tenant overrides via TenantQuotaResolver),
 * global daily/monthly soft budgets, and the global HARD caps. Priority
 * semantics (approved): High ignores every soft cap and stops only at
 * the hard caps or read-only; Medium stops at any exhausted budget; Low
 * never reaches the guard. Unknown capabilities deny (fail-closed).
 *
 * Monthly usage = SUM of the month's daily counter rows; global usage =
 * SUM across tenants (the counter models are deliberately unscoped).
 */
final class AiBudgetGuard
{
    public const READ_ONLY_CACHE_KEY = 'qds:ai-read-only';

    public function __construct(
        private readonly TenantQuotaResolver $quotas,
        private readonly AlertService $alerts,
    ) {}

    public function allows(string $capability, int $tenantId, int $units, Priority $priority): BudgetDecision
    {
        if ($this->readOnly()) {
            return new BudgetDecision(false, 'read-only');
        }

        $config = $this->capabilityConfig($capability);

        if ($config === null) {
            return new BudgetDecision(false, 'unknown-capability');
        }

        $today = CarbonImmutable::now()->toDateString();
        $monthStart = CarbonImmutable::now()->startOfMonth()->toDateString();

        $globalDaily = $this->sum($capability, null, $today, $today);
        $globalMonthly = $this->sum($capability, null, $monthStart, $today);

        // The global HARD caps stop EVERY priority.
        if ($globalDaily + $units > (int) $config['global_daily_hard_units']
            || $globalMonthly + $units > (int) $config['global_monthly_hard_units']) {
            return new BudgetDecision(false, 'global-hard-exhausted');
        }

        if ($priority === Priority::High) {
            return new BudgetDecision(true);
        }

        if ($units > (int) $config['per_post_units']) {
            return new BudgetDecision(false, 'per-post-exceeded');
        }

        $tenantLimits = $this->quotas->for($tenantId, $capability);

        if ($this->sum($capability, $tenantId, $today, $today) + $units > $tenantLimits['daily']) {
            return new BudgetDecision(false, 'tenant-daily-exhausted');
        }

        if ($this->sum($capability, $tenantId, $monthStart, $today) + $units > $tenantLimits['monthly']) {
            return new BudgetDecision(false, 'tenant-monthly-exhausted');
        }

        if ($globalDaily + $units > (int) $config['global_daily_units']) {
            return new BudgetDecision(false, 'global-daily-exhausted');
        }

        if ($globalMonthly + $units > (int) $config['global_monthly_units']) {
            return new BudgetDecision(false, 'global-monthly-exhausted');
        }

        return new BudgetDecision(true);
    }

    /**
     * Post-spend ledger: ONE atomic INSERT … ON CONFLICT DO UPDATE per
     * call (never read-modify-write — concurrent enrichment jobs must
     * not lose increments), cost = units × the capability list price,
     * then deduplicated threshold alerts for every budget dimension the
     * increment pushed across a configured percentage.
     */
    public function record(string $capability, int $tenantId, int $units, int $postsProcessed = 0, int $postsSkippedBudget = 0, int $postsSkippedNoCandidates = 0): void
    {
        $config = $this->capabilityConfig($capability);
        $cost = $units * (int) ($config['price_micro_usd_per_unit'] ?? 0);
        $today = CarbonImmutable::now()->toDateString();

        DB::statement(<<<'SQL'
            INSERT INTO ai_usage_counters
                (capability, tenant_id, usage_date, units, estimated_cost_micro_usd,
                 posts_processed, posts_skipped_budget, posts_skipped_no_candidates, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ON CONFLICT (capability, tenant_id, usage_date) DO UPDATE SET
                units = ai_usage_counters.units + EXCLUDED.units,
                estimated_cost_micro_usd = ai_usage_counters.estimated_cost_micro_usd + EXCLUDED.estimated_cost_micro_usd,
                posts_processed = ai_usage_counters.posts_processed + EXCLUDED.posts_processed,
                posts_skipped_budget = ai_usage_counters.posts_skipped_budget + EXCLUDED.posts_skipped_budget,
                posts_skipped_no_candidates = ai_usage_counters.posts_skipped_no_candidates + EXCLUDED.posts_skipped_no_candidates,
                updated_at = NOW()
            SQL, [$capability, $tenantId, $today, $units, $cost, $postsProcessed, $postsSkippedBudget, $postsSkippedNoCandidates]);

        if ($units > 0 && $config !== null) {
            $this->raiseThresholdAlerts($capability, $tenantId, $units, $config);
        }
    }

    /** Effective read-only state: the cached qds:ai-read-only flag wins over the config default. */
    public function readOnly(): bool
    {
        return (bool) (Cache::get(self::READ_ONLY_CACHE_KEY) ?? config('qds.ai_budget.read_only'));
    }

    /** @return array<string, mixed>|null */
    private function capabilityConfig(string $capability): ?array
    {
        $config = config("qds.ai_budget.capabilities.{$capability}");

        return is_array($config) ? $config : null;
    }

    /** Units spent in [$from, $to] — for one tenant, or globally when $tenantId is null. */
    private function sum(string $capability, ?int $tenantId, string $from, string $to): int
    {
        return (int) AiUsageCounter::query()
            ->where('capability', $capability)
            ->when($tenantId !== null, fn ($query) => $query->where('tenant_id', $tenantId))
            ->whereBetween('usage_date', [$from, $to])
            ->sum('units');
    }

    /**
     * Raise a deduplicated AlertType::AiBudgetThreshold per (capability,
     * dimension, threshold, period) the increment crossed (before < t ≤
     * after — repeats past a threshold raise nothing). Fingerprint recipe
     * (spec §10): the source string carries capability+period+threshold+
     * date and AlertService adds the tenant. Tenant dimensions are
     * tenant-attributed (an operator sees only their own budget alerts);
     * global dimensions stay global (visible to all — no tenant data in
     * the message). Warning below 100 %, critical at 100 %.
     *
     * @param  array<string, mixed>  $config
     */
    private function raiseThresholdAlerts(string $capability, int $tenantId, int $units, array $config): void
    {
        $now = CarbonImmutable::now();
        $today = $now->toDateString();
        $monthStart = $now->startOfMonth()->toDateString();
        $monthKey = $now->format('Y-m');

        $tenantLimits = $this->quotas->for($tenantId, $capability);

        $dimensions = [
            ['period' => 'tenant-daily', 'limit' => $tenantLimits['daily'], 'after' => $this->sum($capability, $tenantId, $today, $today), 'date_key' => $today, 'tenant_id' => $tenantId],
            ['period' => 'tenant-monthly', 'limit' => $tenantLimits['monthly'], 'after' => $this->sum($capability, $tenantId, $monthStart, $today), 'date_key' => $monthKey, 'tenant_id' => $tenantId],
            ['period' => 'global-daily', 'limit' => (int) $config['global_daily_units'], 'after' => $this->sum($capability, null, $today, $today), 'date_key' => $today, 'tenant_id' => null],
            ['period' => 'global-monthly', 'limit' => (int) $config['global_monthly_units'], 'after' => $this->sum($capability, null, $monthStart, $today), 'date_key' => $monthKey, 'tenant_id' => null],
        ];

        foreach ($dimensions as $dimension) {
            $limit = (int) $dimension['limit'];

            if ($limit <= 0) {
                continue;
            }

            $after = (int) $dimension['after'];
            $before = max(0, $after - $units);

            foreach ((array) config('qds.ai_budget.alert_thresholds', [50, 80, 95, 100]) as $threshold) {
                $threshold = (int) $threshold;

                if ($before * 100 < $threshold * $limit && $after * 100 >= $threshold * $limit) {
                    $this->alerts->raise(
                        AlertType::AiBudgetThreshold,
                        "{$capability}:{$dimension['period']}:{$threshold}:{$dimension['date_key']}",
                        sprintf(
                            'AI budget %s: %s usage reached %d%% (%d of %d units) for %s.',
                            $capability, $dimension['period'], $threshold, $after, $limit, $dimension['date_key'],
                        ),
                        $threshold >= 100 ? 'critical' : 'warning',
                        $dimension['tenant_id'],
                    );
                }
            }
        }
    }
}
