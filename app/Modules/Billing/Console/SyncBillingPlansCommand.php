<?php

namespace App\Modules\Billing\Console;

use App\Modules\Billing\Services\SubscriptionPlanSync;
use Illuminate\Console\Command;

/**
 * qds:billing-sync-plans (ADR-0021) — upsert the subscription plan catalog
 * from config/billing.php. Run after deploys that change plan config
 * (idempotent; also invoked by the database seeder).
 */
class SyncBillingPlansCommand extends Command
{
    protected $signature = 'qds:billing-sync-plans';

    protected $description = 'Sync the subscription plan catalog from config/billing.php (ADR-0021)';

    public function handle(SubscriptionPlanSync $sync): int
    {
        $count = $sync->sync();

        $this->info("Synced {$count} subscription plan(s) from config/billing.php.");

        return self::SUCCESS;
    }
}
