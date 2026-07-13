<?php

namespace App\Platform\Ingestion\Support;

use App\Modules\CRM\Models\PlatformAccount;
use App\Shared\Enums\Platform;

/**
 * How many ingestion jobs one account contributes to a cycle — the single
 * source of the per-platform poll shape, shared by the whole-roster and
 * per-creator cycle fan-outs so their jobs_expected bookkeeping can never
 * drift apart.
 *
 * Cost plan (reviews/PLAN-apify-cost-optimization-2026-07-07.md) shape:
 * - TikTok dispatches NO profile job (rec 4) — the content run's items
 *   embed authorMeta, so the content job syncs the profile for free.
 * - Scheduled FULL cycles dispatch NO story job (rec 2) — stories belong
 *   to the tighter story-only cycle exclusively (they used to run in BOTH,
 *   10 story polls/day instead of the documented 6). Story-only cycles are
 *   batch-planned by RunMonitoringCycleJob (rec 3), not per-account here.
 * - On-demand creator runs ($includeStories) still poll stories inline: an
 *   explicit human request fetches everything the creator has.
 */
final class PollPlan
{
    /**
     * Jobs one account contributes to a FULL cycle's fan-out.
     * $includeProfile is the PLANNING-TIME profile-interval decision
     * (AdaptiveCadence::shouldPollProfile) — it must be evaluated once by
     * the cycle planner and passed unchanged to the dispatch so
     * jobs_expected can never drift from the actual dispatches.
     */
    public static function jobCountFor(PlatformAccount $account, bool $includeStories = false, bool $includeProfile = true): int
    {
        $profile = ($includeProfile && self::dispatchesProfileJob($account)) ? 1 : 0;

        $stories = ($includeStories && self::storyCapable($account) && self::storiesEnabled()) ? 1 : 0;

        return $profile + 1 + $stories; // profile? + content (+ stories on demand)
    }

    /** TikTok profiles ride along in the content payload (rec 4). */
    public static function dispatchesProfileJob(PlatformAccount $account): bool
    {
        return $account->platform !== Platform::TikTok;
    }

    /** Stories exist only on Instagram in v1 (matrix §2.1). */
    public static function storyCapable(PlatformAccount $account): bool
    {
        return $account->platform === Platform::Instagram;
    }

    public static function storiesEnabled(): bool
    {
        return (bool) config('qds.ingestion.stories_enabled');
    }

    private function __construct() {}
}
