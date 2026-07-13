<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * ROLLUP-* — the pre-aggregated rollup catalog
     * (docs/30-data-model/01-analytics-model.md §5), as materialized views
     * refreshed on a schedule by SVC-Analytics (ADR-0013: Neon has no
     * pg_cron; the app scheduler drives `qds:refresh-rollups`).
     *
     * Dashboards and SVC-Export read ONLY these — never FACT-* directly.
     *
     * Tier honesty (DP-001):
     *  - total_estimated_reach / total_emv are ESTIMATED aggregates and
     *    carry a fixed *_tier label column so every surface can (and must)
     *    label them as estimates;
     *  - DERIVED ratios (engagement_rate, avg_views, post_rate,
     *    share_of_voice) are RECOMPUTED here from summed PUBLIC components
     *    at the rollup grain — never summed directly;
     *  - posting_frequency has NO canonical formula (flagged missing
     *    decision) and is always NULL → renders "Unavailable".
     *
     * Period rollups keep every {week, month, quarter, year} bucket so
     * over-time trends (follower growth, before/after) stay reconstructable.
     */
    public function up(): void
    {
        // ROLLUP-CreatorByPeriod — creator × period. Serves overall
        // influencer-account monitoring (REQ-M1-005/007, AC-M1-021).
        DB::statement(<<<'SQL'
            CREATE MATERIALIZED VIEW rollup_creator_by_period AS
            WITH grains(grain) AS (VALUES ('week'), ('month'), ('quarter'), ('year')),
            account_buckets AS (
                SELECT g.grain,
                       date_trunc(g.grain, f.date_key)::date AS bucket_start,
                       f.creator_id,
                       f.platform_account_id,
                       f.followers,
                       row_number() OVER (
                           PARTITION BY g.grain, date_trunc(g.grain, f.date_key), f.platform_account_id
                           ORDER BY f.captured_at DESC, f.metric_snapshot_id DESC
                       ) AS rn_last,
                       row_number() OVER (
                           PARTITION BY g.grain, date_trunc(g.grain, f.date_key), f.platform_account_id
                           ORDER BY f.captured_at ASC, f.metric_snapshot_id ASC
                       ) AS rn_first
                FROM fact_creator_account f
                CROSS JOIN grains g
                WHERE f.creator_id IS NOT NULL
            ),
            account_rollup AS (
                SELECT grain, bucket_start, creator_id,
                       sum(followers) FILTER (WHERE rn_last = 1) AS followers,
                       sum(followers) FILTER (WHERE rn_last = 1)
                           - sum(followers) FILTER (WHERE rn_first = 1) AS follower_growth,
                       count(DISTINCT platform_account_id) AS platform_accounts
                FROM account_buckets
                GROUP BY grain, bucket_start, creator_id
            ),
            content_latest AS (
                SELECT g.grain,
                       date_trunc(g.grain, f.date_key)::date AS bucket_start,
                       f.creator_id,
                       f.content_item_id,
                       f.published_at,
                       coalesce(f.views, f.plays) AS views,
                       coalesce(f.likes, 0) + coalesce(f.comments, 0)
                           + coalesce(f.shares, 0) + coalesce(f.saves, 0) AS engagement,
                       row_number() OVER (
                           PARTITION BY g.grain, date_trunc(g.grain, f.date_key), f.content_item_id
                           ORDER BY f.captured_at DESC, f.metric_snapshot_id DESC
                       ) AS rn
                FROM fact_content_metric f
                CROSS JOIN grains g
                WHERE f.creator_id IS NOT NULL
            ),
            content_rollup AS (
                SELECT grain, bucket_start, creator_id,
                       count(*) AS content_count,
                       sum(views) AS views_sum,
                       avg(views) AS avg_views,
                       sum(engagement) AS engagement_sum,
                       max(published_at) AS last_post_at
                FROM content_latest
                WHERE rn = 1
                GROUP BY grain, bucket_start, creator_id
            )
            SELECT grain,
                   bucket_start,
                   creator_id,
                   a.followers,
                   a.follower_growth,
                   a.platform_accounts,
                   c.content_count,
                   c.views_sum,
                   c.avg_views,
                   c.engagement_sum,
                   CASE WHEN a.followers > 0
                        THEN c.engagement_sum / a.followers
                   END AS engagement_rate,
                   NULL::numeric AS posting_frequency,
                   c.last_post_at
            FROM account_rollup a
            FULL JOIN content_rollup c USING (grain, bucket_start, creator_id)
        SQL);

        DB::statement('CREATE UNIQUE INDEX rollup_creator_by_period_key ON rollup_creator_by_period (grain, bucket_start, creator_id)');

        // ROLLUP-MentionByBrand — brand × period (brand monitoring
        // dashboards; share_of_voice per GL-ShareOfVoice over the monitored
        // competitive set = all brand-attributed mentions in the bucket).
        DB::statement(<<<'SQL'
            CREATE MATERIALIZED VIEW rollup_mention_by_brand AS
            WITH grains(grain) AS (VALUES ('week'), ('month'), ('quarter'), ('year')),
            brand_buckets AS (
                SELECT g.grain,
                       date_trunc(g.grain, f.date_key)::date AS bucket_start,
                       f.brand_id,
                       count(*) AS mention_count,
                       sum(f.views) AS total_views,
                       sum(f.estimated_reach) AS total_estimated_reach,
                       sum(f.emv) AS total_emv
                FROM fact_mention f
                CROSS JOIN grains g
                WHERE f.brand_id IS NOT NULL
                GROUP BY g.grain, date_trunc(g.grain, f.date_key), f.brand_id
            )
            SELECT grain,
                   bucket_start,
                   brand_id,
                   mention_count,
                   total_views,
                   total_estimated_reach,
                   'ESTIMATED'::text AS total_estimated_reach_tier,
                   total_emv,
                   'ESTIMATED'::text AS total_emv_tier,
                   mention_count::numeric
                       / nullif(sum(mention_count) OVER (PARTITION BY grain, bucket_start), 0)
                       AS share_of_voice
            FROM brand_buckets
        SQL);

        DB::statement('CREATE UNIQUE INDEX rollup_mention_by_brand_key ON rollup_mention_by_brand (grain, bucket_start, brand_id)');

        // ROLLUP-MetricByGeo — country/city × platform × period. DIM-Geo is
        // confidence-based and unpopulated until Module 2 ships
        // ENT-GeoAttribution; the rollup exists (schema-complete) and stays
        // empty — geo panels render "Unavailable", never zero.
        DB::statement(<<<'SQL'
            CREATE MATERIALIZED VIEW rollup_metric_by_geo AS
            WITH grains(grain) AS (VALUES ('week'), ('month'), ('quarter'), ('year'))
            SELECT g.grain,
                   date_trunc(g.grain, f.date_key)::date AS bucket_start,
                   geo.country_code,
                   geo.city,
                   f.platform,
                   count(DISTINCT f.content_item_id) AS content_count,
                   sum(f.views) AS views,
                   sum(f.estimated_reach) AS estimated_reach,
                   'ESTIMATED'::text AS estimated_reach_tier,
                   sum(f.emv) AS emv,
                   'ESTIMATED'::text AS emv_tier
            FROM fact_mention f
            CROSS JOIN grains g
            JOIN dim_geo geo ON geo.creator_id = f.creator_id
            GROUP BY g.grain, date_trunc(g.grain, f.date_key), geo.country_code, geo.city, f.platform
        SQL);

        DB::statement('CREATE INDEX rollup_metric_by_geo_key ON rollup_metric_by_geo (grain, bucket_start, country_code, platform)');

        // ROLLUP-SeedingByShipment — per shipment: what was sent, did they
        // post, when, how did it perform. Facts arrive with the P3 loaders
        // (ENT-Shipment / ENT-Product ship in P3); structure is P0-complete.
        DB::statement(<<<'SQL'
            CREATE MATERIALIZED VIEW rollup_seeding_by_shipment AS
            WITH latest_content AS (
                SELECT f.*,
                       row_number() OVER (
                           PARTITION BY f.shipment_id, f.content_item_id
                           ORDER BY f.metric_snapshot_id DESC
                       ) AS rn
                FROM fact_seeding_content f
            ),
            content_rollup AS (
                SELECT shipment_id,
                       count(*) AS content_count,
                       sum(views) AS views,
                       sum(likes) AS likes,
                       sum(comments) AS comments,
                       sum(shares) AS shares,
                       sum(saves) AS saves,
                       sum(estimated_reach) AS estimated_reach,
                       sum(emv) AS emv
                FROM latest_content
                WHERE rn = 1
                GROUP BY shipment_id
            )
            SELECT s.shipment_id,
                   s.date_key AS shipped_date,
                   s.creator_id,
                   s.product_id,
                   s.brand_id,
                   s.client_id,
                   s.seeding_campaign_id,
                   s.campaign_id,
                   s.shipped,
                   s.posted,
                   s.days_to_post,
                   coalesce(c.content_count, 0) AS content_count,
                   c.views,
                   c.likes,
                   c.comments,
                   c.shares,
                   c.saves,
                   c.estimated_reach,
                   'ESTIMATED'::text AS estimated_reach_tier,
                   c.emv,
                   'ESTIMATED'::text AS emv_tier
            FROM fact_shipment s
            LEFT JOIN content_rollup c USING (shipment_id)
        SQL);

        DB::statement('CREATE UNIQUE INDEX rollup_seeding_by_shipment_key ON rollup_seeding_by_shipment (shipment_id)');

        // ROLLUP-SeedingByCreatorCampaign — creator × seeding campaign.
        // Deep-review fixes: distinct_content dedupes a content item linked
        // to several shipments of the same run so it counts once (M2); the
        // engagement sum is NULL — never a fabricated zero — when no
        // component was observed (H1, DP-001; matches ROLLUP-MentionByCampaign).
        DB::statement(<<<'SQL'
            CREATE MATERIALIZED VIEW rollup_seeding_by_creator_campaign AS
            WITH latest_content AS (
                SELECT f.*,
                       row_number() OVER (
                           PARTITION BY f.shipment_id, f.content_item_id
                           ORDER BY f.metric_snapshot_id DESC
                       ) AS rn
                FROM fact_seeding_content f
            ),
            distinct_content AS (
                SELECT c.*,
                       row_number() OVER (
                           PARTITION BY c.creator_id, c.seeding_campaign_id, c.content_item_id
                           ORDER BY c.metric_snapshot_id DESC, c.id DESC
                       ) AS crn
                FROM latest_content c
                WHERE c.rn = 1
            ),
            content_rollup AS (
                SELECT creator_id, seeding_campaign_id,
                       min(date_key) AS first_posted_at,
                       count(*) AS content_count,
                       sum(views) AS views,
                       sum(CASE WHEN likes IS NULL AND comments IS NULL
                                 AND shares IS NULL AND saves IS NULL
                                THEN NULL
                                ELSE coalesce(likes, 0) + coalesce(comments, 0)
                                     + coalesce(shares, 0) + coalesce(saves, 0)
                           END) AS engagement,
                       sum(estimated_reach) AS estimated_reach,
                       sum(emv) AS emv
                FROM distinct_content
                WHERE crn = 1
                GROUP BY creator_id, seeding_campaign_id
            ),
            shipment_rollup AS (
                SELECT creator_id, seeding_campaign_id,
                       count(*) AS shipments,
                       sum(posted) AS posted
                FROM fact_shipment
                GROUP BY creator_id, seeding_campaign_id
            )
            SELECT creator_id,
                   seeding_campaign_id,
                   s.shipments,
                   s.posted,
                   c.first_posted_at,
                   coalesce(c.content_count, 0) AS content_count,
                   c.views,
                   c.engagement,
                   c.estimated_reach,
                   'ESTIMATED'::text AS estimated_reach_tier,
                   c.emv,
                   'ESTIMATED'::text AS emv_tier
            FROM shipment_rollup s
            FULL JOIN content_rollup c USING (creator_id, seeding_campaign_id)
        SQL);

        DB::statement('CREATE UNIQUE INDEX rollup_seeding_by_creator_campaign_key ON rollup_seeding_by_creator_campaign (creator_id, seeding_campaign_id)');

        // ROLLUP-SeedingByProduct — product (all creators) × period. The
        // "product seeded to N influencers → one total" rollup
        // (REQ-M3-013). post_rate is DERIVED — recomputed at this grain.
        DB::statement(<<<'SQL'
            CREATE MATERIALIZED VIEW rollup_seeding_by_product AS
            WITH grains(grain) AS (VALUES ('week'), ('month'), ('quarter'), ('year')),
            shipment_rollup AS (
                SELECT g.grain,
                       date_trunc(g.grain, s.date_key)::date AS bucket_start,
                       s.product_id,
                       count(*) AS shipments,
                       sum(s.posted) AS posted_count,
                       count(DISTINCT s.creator_id) AS creators_reached
                FROM fact_shipment s
                CROSS JOIN grains g
                GROUP BY g.grain, date_trunc(g.grain, s.date_key), s.product_id
            ),
            latest_content AS (
                SELECT f.*,
                       row_number() OVER (
                           PARTITION BY f.shipment_id, f.content_item_id
                           ORDER BY f.metric_snapshot_id DESC
                       ) AS rn
                FROM fact_seeding_content f
            ),
            content_rollup AS (
                -- Deep-review fixes: the inner dedupe keeps ONE row per
                -- content item per product bucket, so content linked to two
                -- shipments of the same product is not double-counted (M2);
                -- the engagement sum is NULL when no component was observed
                -- (H1, DP-001; matches ROLLUP-MentionByCampaign).
                SELECT d.grain,
                       d.bucket_start,
                       d.product_id,
                       count(*) AS content_count,
                       sum(d.views) AS total_views,
                       sum(CASE WHEN d.likes IS NULL AND d.comments IS NULL
                                 AND d.shares IS NULL AND d.saves IS NULL
                                THEN NULL
                                ELSE coalesce(d.likes, 0) + coalesce(d.comments, 0)
                                     + coalesce(d.shares, 0) + coalesce(d.saves, 0)
                           END) AS total_engagement,
                       sum(d.estimated_reach) AS total_estimated_reach,
                       sum(d.emv) AS total_emv
                FROM (
                    SELECT g.grain,
                           date_trunc(g.grain, c.date_key)::date AS bucket_start,
                           c.product_id,
                           c.content_item_id,
                           c.views, c.likes, c.comments, c.shares, c.saves,
                           c.estimated_reach, c.emv,
                           row_number() OVER (
                               PARTITION BY g.grain, date_trunc(g.grain, c.date_key),
                                            c.product_id, c.content_item_id
                               ORDER BY c.metric_snapshot_id DESC, c.id DESC
                           ) AS crn
                    FROM latest_content c
                    CROSS JOIN grains g
                    WHERE c.rn = 1
                ) d
                WHERE d.crn = 1
                GROUP BY d.grain, d.bucket_start, d.product_id
            )
            SELECT grain,
                   bucket_start,
                   product_id,
                   s.shipments,
                   s.posted_count,
                   CASE WHEN s.shipments > 0
                        THEN s.posted_count::numeric / s.shipments
                   END AS post_rate,
                   s.creators_reached,
                   coalesce(c.content_count, 0) AS content_count,
                   c.total_views,
                   c.total_estimated_reach,
                   'ESTIMATED'::text AS total_estimated_reach_tier,
                   c.total_engagement,
                   c.total_emv,
                   'ESTIMATED'::text AS total_emv_tier
            FROM shipment_rollup s
            FULL JOIN content_rollup c USING (grain, bucket_start, product_id)
        SQL);

        DB::statement('CREATE UNIQUE INDEX rollup_seeding_by_product_key ON rollup_seeding_by_product (grain, bucket_start, product_id)');

        // ROLLUP-SeedingByBrand — brand × period.
        DB::statement(<<<'SQL'
            CREATE MATERIALIZED VIEW rollup_seeding_by_brand AS
            WITH grains(grain) AS (VALUES ('week'), ('month'), ('quarter'), ('year')),
            shipment_rollup AS (
                SELECT g.grain,
                       date_trunc(g.grain, s.date_key)::date AS bucket_start,
                       s.brand_id,
                       count(*) AS shipments,
                       sum(s.posted) AS posted_count
                FROM fact_shipment s
                CROSS JOIN grains g
                WHERE s.brand_id IS NOT NULL
                GROUP BY g.grain, date_trunc(g.grain, s.date_key), s.brand_id
            ),
            latest_content AS (
                SELECT f.*,
                       row_number() OVER (
                           PARTITION BY f.shipment_id, f.content_item_id
                           ORDER BY f.metric_snapshot_id DESC
                       ) AS rn
                FROM fact_seeding_content f
            ),
            content_rollup AS (
                -- Same M2-class dedupe as ROLLUP-SeedingByProduct: one row
                -- per content item per brand bucket, so multi-shipment links
                -- do not double-count views/EMV.
                SELECT d.grain,
                       d.bucket_start,
                       d.brand_id,
                       count(*) AS content_count,
                       sum(d.views) AS total_views,
                       sum(d.estimated_reach) AS total_estimated_reach,
                       sum(d.emv) AS total_emv
                FROM (
                    SELECT g.grain,
                           date_trunc(g.grain, c.date_key)::date AS bucket_start,
                           c.brand_id,
                           c.content_item_id,
                           c.views, c.estimated_reach, c.emv,
                           row_number() OVER (
                               PARTITION BY g.grain, date_trunc(g.grain, c.date_key),
                                            c.brand_id, c.content_item_id
                               ORDER BY c.metric_snapshot_id DESC, c.id DESC
                           ) AS crn
                    FROM latest_content c
                    CROSS JOIN grains g
                    WHERE c.rn = 1 AND c.brand_id IS NOT NULL
                ) d
                WHERE d.crn = 1
                GROUP BY d.grain, d.bucket_start, d.brand_id
            )
            SELECT grain,
                   bucket_start,
                   brand_id,
                   s.shipments,
                   s.posted_count,
                   coalesce(c.content_count, 0) AS content_count,
                   c.total_views,
                   c.total_estimated_reach,
                   'ESTIMATED'::text AS total_estimated_reach_tier,
                   c.total_emv,
                   'ESTIMATED'::text AS total_emv_tier
            FROM shipment_rollup s
            FULL JOIN content_rollup c USING (grain, bucket_start, brand_id)
        SQL);

        DB::statement('CREATE UNIQUE INDEX rollup_seeding_by_brand_key ON rollup_seeding_by_brand (grain, bucket_start, brand_id)');
    }

    public function down(): void
    {
        foreach ([
            'rollup_seeding_by_brand',
            'rollup_seeding_by_product',
            'rollup_seeding_by_creator_campaign',
            'rollup_seeding_by_shipment',
            'rollup_metric_by_geo',
            'rollup_mention_by_brand',
            'rollup_creator_by_period',
        ] as $view) {
            DB::statement("DROP MATERIALIZED VIEW IF EXISTS {$view}");
        }
    }
};
