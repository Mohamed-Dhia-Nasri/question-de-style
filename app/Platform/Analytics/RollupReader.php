<?php

namespace App\Platform\Analytics;

use App\Shared\Tenancy\TenantContext;
use Illuminate\Database\Query\Builder;
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
 *
 * Tenant isolation (ADR-0019, hard-enforcement phase). These queries use
 * the raw query builder against rollup_* / dim_* — which carry a NOT NULL
 * tenant_id but do NOT go through Eloquent, so the BelongsToTenant global
 * scope never fires here. Isolation is therefore intrinsic to the reader:
 * every rollup read is filtered by the active tenant WHEN a context is
 * bound (the only mode any authenticated dashboard/export runs in), exactly
 * as ReportBuilder does. A null context (platform tooling, tests without a
 * tenant) reads unfiltered on purpose. This is defense at the source: an
 * id-scoped method (creatorSeries, campaignMentionTotals, …) that already
 * receives a tenant-authorized id keeps the tenant predicate anyway, so
 * correctness never rests on the caller having pre-validated the id.
 */
class RollupReader
{
    public const GRAINS = ['week', 'month', 'quarter', 'year'];

    public function __construct(private readonly TenantContext $context) {}

    /**
     * ROLLUP-CreatorByPeriod buckets for one creator (trend series).
     *
     * @return Collection<int, \stdClass>
     */
    public function creatorSeries(int $creatorId, string $grain, ?Carbon $from = null, ?Carbon $to = null): Collection
    {
        return $this->tenant(DB::table('rollup_creator_by_period'))
            ->where('creator_id', $creatorId)
            ->where('grain', $this->grain($grain))
            ->when($from, fn ($q) => $q->where('bucket_start', '>=', $from->toDateString()))
            ->when($to, fn ($q) => $q->where('bucket_start', '<=', $to->toDateString()))
            ->orderBy('bucket_start')
            ->get();
    }

    /** ROLLUP-CreatorByPeriod measures a roster list may sort by. */
    public const CREATOR_SORT_METRICS = ['followers', 'follower_growth', 'avg_views', 'engagement_rate', 'last_post_at'];

    /**
     * Correlated subquery yielding one CREATOR_SORT_METRICS value from the
     * creator's LATEST ROLLUP-CreatorByPeriod bucket (same latest-bucket
     * pick as latestCreatorBuckets, so a sorted list orders by exactly the
     * numbers it displays). For embedding via selectSub() on a query whose
     * root table is `creators` — the rollup SQL stays inside this reader
     * (ADR-0010). Tenant-qualified because it runs as an inner subquery.
     */
    public function latestCreatorMetricSubquery(string $metric, string $grain): Builder
    {
        if (! in_array($metric, self::CREATOR_SORT_METRICS, true)) {
            throw new \InvalidArgumentException("Not a sortable creator rollup metric: {$metric}");
        }

        return $this->tenant(DB::table('rollup_creator_by_period'), 'rollup_creator_by_period.tenant_id')
            ->select($metric)
            ->whereColumn('rollup_creator_by_period.creator_id', 'creators.id')
            ->where('grain', $this->grain($grain))
            ->orderByDesc('bucket_start')
            ->limit(1);
    }

    /**
     * Latest ROLLUP-CreatorByPeriod bucket per creator (current stats).
     *
     * @param  list<int>|null  $creatorIds
     * @return Collection<int, \stdClass>
     */
    public function latestCreatorBuckets(string $grain, ?array $creatorIds = null): Collection
    {
        return $this->tenant(DB::table('rollup_creator_by_period'))
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
        return $this->tenant(DB::table('rollup_mention_by_brand'))
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
        $totals = $this->tenant(DB::table('rollup_mention_by_brand'))
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
        return $this->tenant(DB::table('rollup_creator_by_period'))
            ->where('grain', 'week')
            ->when($from, fn ($q) => $q->where('bucket_start', '>=', $from->startOfWeek()->toDateString()))
            ->when($to, fn ($q) => $q->where('bucket_start', '<=', $to->toDateString()))
            ->when($creatorId !== null, fn ($q) => $q->where('creator_id', $creatorId))
            ->selectRaw('sum(views_sum) as views_sum')
            ->selectRaw('sum(engagement_sum) as engagement_sum')
            ->selectRaw('sum(content_count) as content_count')
            ->first();
    }

    /**
     * ROLLUP-SeedingByProduct buckets — the cross-influencer product totals
     * (REQ-M3-013 / AC-M3-019). post_rate arrives DERIVED-recomputed at the
     * rollup grain; the brand filter resolves through DIM-Product (the
     * canonical view carries no brand column).
     *
     * @return Collection<int, \stdClass>
     */
    public function seedingByProduct(string $grain, ?Carbon $from = null, ?Carbon $to = null, ?int $brandId = null, ?int $productId = null): Collection
    {
        return $this->tenant(DB::table('rollup_seeding_by_product'))
            ->where('grain', $this->grain($grain))
            ->when($from, fn ($q) => $q->where('bucket_start', '>=', $from->toDateString()))
            ->when($to, fn ($q) => $q->where('bucket_start', '<=', $to->toDateString()))
            ->when($brandId !== null, fn ($q) => $q->whereIn(
                'product_id',
                $this->tenant(DB::table('dim_product'))->where('brand_id', $brandId)->select('product_id'),
            ))
            ->when($productId !== null, fn ($q) => $q->where('product_id', $productId))
            ->orderBy('bucket_start')
            ->orderBy('product_id')
            ->get();
    }

    /**
     * rollup_seeding_by_product_slice buckets — content-side measures per
     * platform / content type / country / city (module-3 §2.11 slices;
     * additive non-canonical view, Step-4 D5). country and city come from
     * the creator's highest-confidence DIM-Geo row — NULL for creators
     * without geography, so those slices surface as Unavailable, never zero.
     *
     * @return Collection<int, \stdClass>
     */
    public function seedingProductSlices(string $grain, ?Carbon $from = null, ?Carbon $to = null, ?int $brandId = null, ?int $productId = null, ?string $platform = null, ?string $contentType = null, ?string $country = null, ?string $city = null): Collection
    {
        return $this->tenant(DB::table('rollup_seeding_by_product_slice'))
            ->where('grain', $this->grain($grain))
            ->when($from, fn ($q) => $q->where('bucket_start', '>=', $from->toDateString()))
            ->when($to, fn ($q) => $q->where('bucket_start', '<=', $to->toDateString()))
            ->when($brandId !== null, fn ($q) => $q->whereIn(
                'product_id',
                $this->tenant(DB::table('dim_product'))->where('brand_id', $brandId)->select('product_id'),
            ))
            ->when($productId !== null, fn ($q) => $q->where('product_id', $productId))
            ->when($platform !== null, fn ($q) => $q->where('platform', $platform))
            ->when($contentType !== null, fn ($q) => $q->where('content_type', $contentType))
            ->when($country !== null, fn ($q) => $q->where('country', $country))
            ->when($city !== null, fn ($q) => $q->where('city', $city))
            ->orderBy('bucket_start')
            ->orderBy('product_id')
            ->get();
    }

    /**
     * ROLLUP-SeedingByShipment rows for one seeding run — per shipment:
     * what was sent, did they post, when, how did it perform (AC-M3-018).
     *
     * @return Collection<int, \stdClass>
     */
    public function seedingByShipment(int $seedingCampaignId): Collection
    {
        return $this->tenant(DB::table('rollup_seeding_by_shipment'))
            ->where('seeding_campaign_id', $seedingCampaignId)
            ->orderBy('shipped_date')
            ->orderBy('shipment_id')
            ->get();
    }

    /**
     * Aggregate totals for one seeding run across
     * ROLLUP-SeedingByCreatorCampaign. Counts where zero IS a measurement
     * (no rows → no shipments recorded) come back as integers; PUBLIC /
     * ESTIMATED sums stay NULL when nothing was observed (unavailable —
     * never zero), with their tier labels passed through. DERIVED ratios
     * (post rate, CPE, CPM) are recomputed by the consumer — never summed.
     *
     * @return object{shipments: int|null, posted_count: int|null, creators_reached: int, content_count: int|null, total_views: string|null, total_engagement: string|null, total_estimated_reach: string|null, total_estimated_reach_tier: string|null, total_emv: string|null, total_emv_tier: string|null}
     */
    public function seedingCampaignTotals(int $seedingCampaignId): object
    {
        $totals = $this->tenant(DB::table('rollup_seeding_by_creator_campaign'))
            ->where('seeding_campaign_id', $seedingCampaignId)
            ->selectRaw('sum(shipments) as shipments')
            ->selectRaw('sum(posted) as posted_count')
            ->selectRaw('count(creator_id) as creators_reached')
            ->selectRaw('sum(content_count) as content_count')
            ->selectRaw('sum(views) as total_views')
            ->selectRaw('sum(engagement) as total_engagement')
            ->selectRaw('sum(estimated_reach) as total_estimated_reach')
            ->selectRaw('max(estimated_reach_tier) as total_estimated_reach_tier')
            ->selectRaw('sum(emv) as total_emv')
            ->selectRaw('max(emv_tier) as total_emv_tier')
            ->first();

        $totals->shipments = $totals->shipments === null ? null : (int) $totals->shipments;
        $totals->posted_count = $totals->posted_count === null ? null : (int) $totals->posted_count;
        $totals->creators_reached = (int) $totals->creators_reached;
        $totals->content_count = $totals->content_count === null ? null : (int) $totals->content_count;

        return $totals;
    }

    /**
     * Aggregate KPI totals for one campaign across
     * rollup_mention_by_campaign (the campaign half of REQ-M3-009; additive
     * non-canonical view, Step-4 D3). Same discipline as mentionTotals():
     * NULL means unavailable, tier columns pass through untouched.
     *
     * @return object{mention_count: int|null, content_count: int|null, total_views: string|null, total_likes: string|null, total_comments: string|null, total_engagement: string|null, total_estimated_reach: string|null, total_estimated_reach_tier: string|null, total_emv: string|null, total_emv_tier: string|null}
     */
    public function campaignMentionTotals(int $campaignId, ?Carbon $from = null, ?Carbon $to = null): object
    {
        $totals = $this->tenant(DB::table('rollup_mention_by_campaign'))
            ->where('grain', 'week')
            ->where('campaign_id', $campaignId)
            ->when($from, fn ($q) => $q->where('bucket_start', '>=', $from->startOfWeek()->toDateString()))
            ->when($to, fn ($q) => $q->where('bucket_start', '<=', $to->toDateString()))
            ->selectRaw('sum(mention_count) as mention_count')
            ->selectRaw('sum(content_count) as content_count')
            ->selectRaw('sum(total_views) as total_views')
            ->selectRaw('sum(total_likes) as total_likes')
            ->selectRaw('sum(total_comments) as total_comments')
            ->selectRaw('sum(total_engagement) as total_engagement')
            ->selectRaw('sum(total_estimated_reach) as total_estimated_reach')
            ->selectRaw('max(total_estimated_reach_tier) as total_estimated_reach_tier')
            ->selectRaw('sum(total_emv) as total_emv')
            ->selectRaw('max(total_emv_tier) as total_emv_tier')
            ->first();

        $totals->mention_count = $totals->mention_count === null ? null : (int) $totals->mention_count;
        $totals->content_count = $totals->content_count === null ? null : (int) $totals->content_count;

        return $totals;
    }

    /** Timestamp of the last successful rollup refresh (freshness signal). */
    public function lastRefreshedAt(): ?Carbon
    {
        // analytics_refreshes is platform-global telemetry (no tenant_id) —
        // a shared freshness heartbeat, not tenant business data.
        $finishedAt = DB::table('analytics_refreshes')
            ->where('status', 'COMPLETED')
            ->max('finished_at');

        return $finishedAt === null ? null : Carbon::parse($finishedAt);
    }

    /**
     * Apply the active tenant predicate to a rollup/dim query, mirroring
     * ReportBuilder::tenantId(). A no-op in platform context (no bound
     * tenant) — those callers legitimately span tenants.
     */
    private function tenant(Builder $query, string $column = 'tenant_id'): Builder
    {
        $tenantId = $this->context->id();

        return $query->when($tenantId !== null, fn ($q) => $q->where($column, $tenantId));
    }

    private function grain(string $grain): string
    {
        return in_array($grain, self::GRAINS, true) ? $grain : 'month';
    }
}
