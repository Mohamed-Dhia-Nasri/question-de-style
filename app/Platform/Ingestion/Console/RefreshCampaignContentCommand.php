<?php

namespace App\Platform\Ingestion\Console;

use App\Platform\Ingestion\Jobs\RefreshCampaignContentJob;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Scheduler entry point for the daily campaign-linked metric refresh
 * (cost plan follow-up to rec 1): keeps EMV/CPE metrics live for content
 * matched to producing seeding campaigns after it ages out of the roster's
 * refresh window. Self-gating on the ingestion + campaign_refresh flags so
 * the schedule can be registered unconditionally.
 */
class RefreshCampaignContentCommand extends Command
{
    protected $signature = 'qds:refresh-campaign-content';

    protected $description = 'Refresh public metrics of campaign-linked content via direct post URLs (SVC-Ingestion)';

    public function handle(): int
    {
        if (! config('qds.ingestion.enabled')) {
            $this->warn('Ingestion is disabled (QDS_INGESTION_ENABLED=false) — skipping.');

            return self::SUCCESS;
        }

        if (! config('qds.ingestion.campaign_refresh.enabled')) {
            $this->warn('Campaign refresh is disabled (QDS_CAMPAIGN_REFRESH_ENABLED=false) — skipping.');

            return self::SUCCESS;
        }

        RefreshCampaignContentJob::dispatch((string) Str::uuid());

        $this->info('Campaign-linked content refresh dispatched.');

        return self::SUCCESS;
    }
}
