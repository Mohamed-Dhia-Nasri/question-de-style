<?php

namespace App\Platform\Ingestion\Support;

/**
 * The provider-side metric-refresh window (cost plan rec 1): content polls
 * ask the actor for items published within the last N days instead of the
 * newest N items forever. Re-fetching IN-window posts is the mechanism that
 * refreshes their public metrics (snapshots are DB-only) — the window
 * bounds that refresh by TIME, which is the semantics engagement KPIs need.
 */
final class RefreshWindow
{
    /**
     * Relative-date string for actor date params ("14 days"), understood by
     * both the Instagram scrapers' `onlyPostsNewerThan` and the TikTok
     * scraper's `oldestPostDateUnified`. Null = no filter (full depth):
     * either the periodic sweep asked for it or the window is disabled.
     */
    public static function relative(bool $fullDepth): ?string
    {
        $days = (int) config('qds.ingestion.refresh_window_days');

        return ($fullDepth || $days <= 0) ? null : "{$days} days";
    }

    private function __construct() {}
}
