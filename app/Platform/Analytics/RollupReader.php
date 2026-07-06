<?php

namespace App\Platform\Analytics;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Read side of SVC-Analytics: dashboards and SVC-Export consume approved
 * ROLLUP-* materialized views through this reader ONLY — never FACT-*
 * tables, never live aggregation over OLTP rows (ADR-0010).
 *
 * Every ESTIMATED aggregate keeps its tier label column; a NULL aggregate
 * means "not measured / not available" and must surface as Unavailable —
 * never as zero (deferred-register UI rule).
 */
class RollupReader
{
    public const GRAINS = ['week', 'month', 'quarter', 'year'];

    /**
     * ROLLUP-CreatorByPeriod buckets for one creator (trend series).
     *
     * @return Collection<int, \stdClass>
     */
    public function creatorSeries(int $creatorId, string $grain, ?Carbon $from = null, ?Carbon $to = null): Collection
    {
        return DB::table('rollup_creator_by_period')
            ->where('creator_id', $creatorId)
            ->where('grain', $this->grain($grain))
            ->when($from, fn ($q) => $q->where('bucket_start', '>=', $from->toDateString()))
            ->when($to, fn ($q) => $q->where('bucket_start', '<=', $to->toDateString()))
            ->orderBy('bucket_start')
            ->get();
    }

    /**
     * Latest ROLLUP-CreatorByPeriod bucket per creator (current stats).
     *
     * @param  list<int>|null  $creatorIds
     * @return Collection<int, \stdClass>
     */
    public function latestCreatorBuckets(string $grain, ?array $creatorIds = null): Collection
    {
        return DB::table('rollup_creator_by_period')
            ->where('grain', $this->grain($grain))
            ->when($creatorIds !== null, fn ($q) => $q->whereIn('creator_id', $creatorIds))
            ->orderBy('creator_id')
            ->orderByDesc('bucket_start')
            ->get()
            ->unique('creator_id')
            ->values();
    }

    /**
     * ROLLUP-MentionByBrand buckets, optionally filtered by brand.
     *
     * @return Collection<int, \stdClass>
     */
    public function mentionsByBrand(string $grain, ?Carbon $from = null, ?Carbon $to = null, ?int $brandId = null): Collection
    {
        return DB::table('rollup_mention_by_brand')
            ->where('grain', $this->grain($grain))
            ->when($from, fn ($q) => $q->where('bucket_start', '>=', $from->toDateString()))
            ->when($to, fn ($q) => $q->where('bucket_start', '<=', $to->toDateString()))
            ->when($brandId !== null, fn ($q) => $q->where('brand_id', $brandId))
            ->orderBy('bucket_start')
            ->get();
    }

    /**
     * Aggregate KPI totals across ROLLUP-MentionByBrand for a period.
     * ESTIMATED totals stay separate and labelled; NULL means unavailable.
     *
     * @return object{mention_count: int|null, total_views: string|null, total_estimated_reach: string|null, total_emv: string|null}
     */
    public function mentionTotals(?Carbon $from = null, ?Carbon $to = null, ?int $brandId = null): object
    {
        $totals = DB::table('rollup_mention_by_brand')
            ->where('grain', 'week')
            ->when($from, fn ($q) => $q->where('bucket_start', '>=', $from->startOfWeek()->toDateString()))
            ->when($to, fn ($q) => $q->where('bucket_start', '<=', $to->toDateString()))
            ->when($brandId !== null, fn ($q) => $q->where('brand_id', $brandId))
            ->selectRaw('sum(mention_count) as mention_count')
            ->selectRaw('sum(total_views) as total_views')
            ->selectRaw('sum(total_estimated_reach) as total_estimated_reach')
            ->selectRaw('sum(total_emv) as total_emv')
            ->first();

        $totals->mention_count = $totals->mention_count === null ? null : (int) $totals->mention_count;

        return $totals;
    }

    /**
     * PUBLIC-component totals across ROLLUP-CreatorByPeriod for a period
     * (views + engagement components; DERIVED rates are recomputed by the
     * consumer, never summed).
     *
     * @return object{views_sum: string|null, engagement_sum: string|null, content_count: string|null}
     */
    public function creatorTotals(?Carbon $from = null, ?Carbon $to = null, ?int $creatorId = null): object
    {
        return DB::table('rollup_creator_by_period')
            ->where('grain', 'week')
            ->when($from, fn ($q) => $q->where('bucket_start', '>=', $from->startOfWeek()->toDateString()))
            ->when($to, fn ($q) => $q->where('bucket_start', '<=', $to->toDateString()))
            ->when($creatorId !== null, fn ($q) => $q->where('creator_id', $creatorId))
            ->selectRaw('sum(views_sum) as views_sum')
            ->selectRaw('sum(engagement_sum) as engagement_sum')
            ->selectRaw('sum(content_count) as content_count')
            ->first();
    }

    /** Timestamp of the last successful rollup refresh (freshness signal). */
    public function lastRefreshedAt(): ?Carbon
    {
        $finishedAt = DB::table('analytics_refreshes')
            ->where('status', 'COMPLETED')
            ->max('finished_at');

        return $finishedAt === null ? null : Carbon::parse($finishedAt);
    }

    private function grain(string $grain): string
    {
        return in_array($grain, self::GRAINS, true) ? $grain : 'month';
    }
}
