<?php

namespace App\Platform\AiBudget;

use App\Platform\AiBudget\Models\TenantAiQuota;

/**
 * Effective per-tenant AI quota limits (spec §10): the tenant_ai_quotas
 * override row wins column-by-column; a NULL column falls back to the
 * capability's config default. Rows are memoized per (tenant, capability)
 * for the life of THIS instance (MonitoringSettingsResolver pattern —
 * the class is NOT a singleton; each resolution starts fresh).
 */
final class TenantQuotaResolver
{
    /** @var array<string, TenantAiQuota|null> */
    private array $rows = [];

    /** @return array{daily: int, monthly: int} */
    public function for(int $tenantId, string $capability): array
    {
        $key = $tenantId.':'.$capability;

        if (! array_key_exists($key, $this->rows)) {
            $this->rows[$key] = TenantAiQuota::query()
                ->where('tenant_id', $tenantId)
                ->where('capability', $capability)
                ->first();
        }

        $override = $this->rows[$key];

        return [
            'daily' => $override?->daily_units ?? (int) config("qds.ai_budget.capabilities.{$capability}.tenant_daily_units"),
            'monthly' => $override?->monthly_units ?? (int) config("qds.ai_budget.capabilities.{$capability}.tenant_monthly_units"),
        ];
    }
}
