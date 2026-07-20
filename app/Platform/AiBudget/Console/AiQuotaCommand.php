<?php

namespace App\Platform\AiBudget\Console;

use App\Models\Tenant;
use App\Platform\AiBudget\Models\TenantAiQuota;
use App\Platform\AiBudget\TenantQuotaResolver;
use Illuminate\Console\Command;

/**
 * Per-tenant AI quota overrides (spec §10, v1 operator surface): sets or
 * clears the tenant_ai_quotas row and always reports the EFFECTIVE
 * limits (override column, or config default where NULL). Billing-plan
 * self-serve purchase is a documented billing-module follow-up.
 */
class AiQuotaCommand extends Command
{
    protected $signature = 'qds:ai-quota
        {tenant : Tenant id}
        {capability : Budget capability, e.g. embedding}
        {--daily= : Tenant daily unit cap (overrides the config default)}
        {--monthly= : Tenant monthly unit cap (overrides the config default)}
        {--clear : Remove the override row (back to config defaults)}';

    protected $description = 'Show or set a per-tenant AI budget quota override (NULL column = config default)';

    public function handle(TenantQuotaResolver $resolver): int
    {
        $tenantId = (int) $this->argument('tenant');
        $capability = (string) $this->argument('capability');

        if (Tenant::query()->whereKey($tenantId)->doesntExist()) {
            $this->error("Tenant {$tenantId} does not exist.");

            return self::FAILURE;
        }

        if (! is_array(config("qds.ai_budget.capabilities.{$capability}"))) {
            $this->error(sprintf(
                "Unknown capability '%s' — configured: %s.",
                $capability,
                implode(', ', array_keys((array) config('qds.ai_budget.capabilities'))),
            ));

            return self::FAILURE;
        }

        if ((bool) $this->option('clear')) {
            TenantAiQuota::query()
                ->where('tenant_id', $tenantId)
                ->where('capability', $capability)
                ->delete();

            $this->info("Cleared the {$capability} quota override for tenant {$tenantId} — config defaults apply.");

            return self::SUCCESS;
        }

        $daily = $this->option('daily');
        $monthly = $this->option('monthly');

        if ($daily !== null || $monthly !== null) {
            $quota = TenantAiQuota::query()->firstOrNew(['tenant_id' => $tenantId, 'capability' => $capability]);

            if ($daily !== null) {
                $quota->daily_units = max(0, (int) $daily);
            }

            if ($monthly !== null) {
                $quota->monthly_units = max(0, (int) $monthly);
            }

            $quota->save();
        }

        $effective = $resolver->for($tenantId, $capability);
        $override = TenantAiQuota::query()
            ->where('tenant_id', $tenantId)
            ->where('capability', $capability)
            ->first();

        $this->info(sprintf(
            'Tenant %d / %s: daily %d units (%s), monthly %d units (%s).',
            $tenantId,
            $capability,
            $effective['daily'],
            $override?->daily_units !== null ? 'override' : 'config default',
            $effective['monthly'],
            $override?->monthly_units !== null ? 'override' : 'config default',
        ));

        return self::SUCCESS;
    }
}
