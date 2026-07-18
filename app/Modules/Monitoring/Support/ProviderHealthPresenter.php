<?php

namespace App\Modules\Monitoring\Support;

use App\Platform\Ingestion\SourceRegistry;
use Carbon\CarbonImmutable;

/**
 * Turns the raw ProviderHealthService::overview() output into plain-English,
 * non-technical rows for the Monitoring "Data collection status" panel:
 * friendly source names (SRC-clockworks-tiktok-scraper -> "TikTok"), a
 * three-level status (working / delayed / not working) with a short reason,
 * and the last-success time as a Carbon instance for the view to humanise.
 *
 * Presentation only. The canonical SRC-* ids and their health live in
 * SourceRegistry / ProviderHealthService; this class never changes them.
 */
final class ProviderHealthPresenter
{
    /** Friendly, non-technical names keyed by SRC-* id. */
    private const LABELS = [
        SourceRegistry::APIFY_INSTAGRAM_SCRAPER => 'Instagram',
        SourceRegistry::APIFY_INSTAGRAM_REEL_SCRAPER => 'Instagram Reels',
        SourceRegistry::APIFY_INSTAGRAM_PROFILE_SCRAPER => 'Instagram profiles',
        SourceRegistry::APIFY_INSTAGRAM_POST_SCRAPER => 'Instagram posts',
        SourceRegistry::APIFY_INSTAGRAM_COMMENT_SCRAPER => 'Instagram comments',
        SourceRegistry::APIFY_INSTAGRAM_STORY_DETAILS => 'Instagram stories',
        SourceRegistry::CLOCKWORKS_TIKTOK_SCRAPER => 'TikTok',
        SourceRegistry::YOUTUBE_DATA_API_V3 => 'YouTube',
        SourceRegistry::GOOGLE_CLOUD_VISION => 'Image analysis (Google)',
        SourceRegistry::GOOGLE_SPEECH_TO_TEXT => 'Speech-to-text (Google)',
        SourceRegistry::GOOGLE_VIDEO_INTELLIGENCE => 'Video analysis (Google)',
        SourceRegistry::AGENCY_MANUAL_ENTRY => 'Manual entry (staff)',
    ];

    /** Friendly name for one SRC-* id (falls back to the raw id). */
    public static function label(string $source): string
    {
        return self::LABELS[$source] ?? $source;
    }

    /**
     * Build view-ready rows for every source that has actually run, worst
     * status first (not working, then delayed, then errors, then working),
     * then by name. Sources that have never run are omitted.
     *
     * @param  array<string, array<string, mixed>>  $overview  ProviderHealthService::overview()
     * @return list<array{
     *     source: string, name: string, status: string, status_label: string,
     *     status_color: string, detail: string, last_success_at: ?CarbonImmutable
     * }>
     */
    public static function rows(array $overview): array
    {
        $rows = [];

        foreach ($overview as $source => $data) {
            if (! self::hasRun($data)) {
                continue;
            }

            [$status, $label, $color, $detail, $order] = self::classify($data);

            $lastSuccess = $data['last_success_at'] ?? null;

            $rows[] = [
                'source' => (string) $source,
                'name' => self::label((string) $source),
                'status' => $status,
                'status_label' => $label,
                'status_color' => $color,
                'detail' => $detail,
                'last_success_at' => is_string($lastSuccess) ? CarbonImmutable::parse($lastSuccess) : null,
                '_order' => $order,
            ];
        }

        usort($rows, fn (array $a, array $b): int => [$a['_order'], mb_strtolower($a['name'])] <=> [$b['_order'], mb_strtolower($b['name'])]);

        return array_map(function (array $row): array {
            unset($row['_order']);

            return $row;
        }, $rows);
    }

    /** Has this source ever run (any call, state, success or failure)? */
    private static function hasRun(array $data): bool
    {
        return ($data['status'] ?? 'UNKNOWN') !== 'UNKNOWN'
            || (int) ($data['total_calls'] ?? 0) > 0
            || (int) ($data['consecutive_failures'] ?? 0) > 0
            || ($data['last_success_at'] ?? null) !== null
            || ($data['last_failure_at'] ?? null) !== null;
    }

    /**
     * Reduce the raw health signals to one of four plain buckets.
     *
     * @return array{0: string, 1: string, 2: string, 3: string, 4: int}
     *     status key, badge label, badge colour, short reason, sort order
     */
    private static function classify(array $data): array
    {
        $consecutiveFailures = (int) ($data['consecutive_failures'] ?? 0);
        $status = (string) ($data['status'] ?? 'UNKNOWN');
        $stale = ($data['stale_data_warning'] ?? false) === true;

        if ($status === 'FAILING' || $consecutiveFailures > 0) {
            $detail = $consecutiveFailures > 0
                ? sprintf('The last %d %s failed.', $consecutiveFailures, $consecutiveFailures === 1 ? 'check' : 'checks')
                : 'This source is not working right now.';

            return ['broken', 'Not working', 'error', $detail, 0];
        }

        if ($stale) {
            return ['delayed', 'Delayed', 'warning', 'No new data recently.', 1];
        }

        if ($status === 'DEGRADED') {
            return ['errors', 'Some errors', 'warning', 'Working, but some recent checks failed.', 2];
        }

        return ['working', 'Working', 'success', '', 3];
    }

    private function __construct() {}
}
