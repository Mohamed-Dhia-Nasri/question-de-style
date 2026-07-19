<?php

namespace App\Platform\AiBudget;

use App\Platform\AiBudget\Models\AiUsageCounter;
use App\Platform\Ingestion\Observability\AlertService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

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
}
