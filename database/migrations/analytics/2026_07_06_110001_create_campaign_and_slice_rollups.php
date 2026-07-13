<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Two ADDITIVE rollups for Module 3 Step 4 results (REQ-M3-009 /
     * REQ-M3-013). Both are NON-canonical companions to the §5 catalog in
     * docs/30-data-model/01-analytics-model.md — FLAGGED amendment
     * candidates (Step-4 spec D3 / D5); the seven canonical views are
     * untouched. Same engine rules as the P0 rollups (ADR-0010/ADR-0013):
     * materialized views refreshed on a schedule, read only through
     * RollupReader, ESTIMATED aggregates carry fixed *_tier label columns.
     *
     *  - rollup_mention_by_campaign — the campaign half of REQ-M3-009.
     *    Mirrors rollup_mention_by_brand (grain × bucket, non-null
     *    grouping key) but grouped by fact_mention.campaign_id, and adds
     *    the results measures the campaign panel needs: content_count,
     *    likes/comments, and the GL-Engagement component sum. The
     *    engagement sum is NULL when NO component was observed for any
     *    mention in the bucket (story-only mentions carry no per-metric
     *    breakdown) — a fabricated zero would present absence as fact
     *    (DP-001); partially observed rows sum their observed components.
     *
     *  - rollup_seeding_by_product_slice — rollup_seeding_by_product's
     *    content-side measures re-grouped by platform / content_type /
     *    country (module-3 §2.11 "sliceable by platform, content type,
     *    and country"; AC-M3-019 "any time grain and slice"). Content
     *    measures ONLY: shipments carry no platform, so shipment counts
     *    stay on the canonical unsliced view and the dashboard combines.
     *    creators_reached here therefore counts creators who POSTED
     *    within the slice (from FACT-SeedingContent), not creators
     *    shipped to. country + city come from DIM-Geo (one highest-
     *    confidence row per creator to avoid measure fan-out), fed today
     *    by ADR-0018 operator-assigned geography and later by Module 2's
     *    automatic attribution; creators without an assignment stay NULL
     *    and render "Unavailable" — never zero (DEF register).
     */
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE MATERIALIZED VIEW rollup_mention_by_campaign AS
            WITH grains(grain) AS (VALUES ('week'), ('month'), ('quarter'), ('year')),
            campaign_buckets AS (
                SELECT g.grain,
                       date_trunc(g.grain, f.date_key)::date AS bucket_start,
                       f.campaign_id,
                       count(*) AS mention_count,
                       count(DISTINCT f.content_item_id) AS content_count,
                       sum(f.views) AS total_views,
                       sum(f.likes) AS total_likes,
                       sum(f.comments) AS total_comments,
                       sum(CASE WHEN f.likes IS NULL AND f.comments IS NULL
                                 AND f.shares IS NULL AND f.saves IS NULL
                                THEN NULL
                                ELSE coalesce(f.likes, 0) + coalesce(f.comments, 0)
                                     + coalesce(f.shares, 0) + coalesce(f.saves, 0)
                           END) AS total_engagement,
                       sum(f.estimated_reach) AS total_estimated_reach,
                       sum(f.emv) AS total_emv
                FROM fact_mention f
                CROSS JOIN grains g
                WHERE f.campaign_id IS NOT NULL
                GROUP BY g.grain, date_trunc(g.grain, f.date_key), f.campaign_id
            )
            SELECT grain,
                   bucket_start,
                   campaign_id,
                   mention_count,
                   content_count,
                   total_views,
                   total_likes,
                   total_comments,
                   total_engagement,
                   total_estimated_reach,
                   'ESTIMATED'::text AS total_estimated_reach_tier,
                   total_emv,
                   'ESTIMATED'::text AS total_emv_tier
            FROM campaign_buckets
        SQL);

        DB::statement('CREATE UNIQUE INDEX rollup_mention_by_campaign_key ON rollup_mention_by_campaign (grain, bucket_start, campaign_id)');

        DB::statement(<<<'SQL'
            CREATE MATERIALIZED VIEW rollup_seeding_by_product_slice AS
            WITH grains(grain) AS (VALUES ('week'), ('month'), ('quarter'), ('year')),
            latest_content AS (
                SELECT f.*,
                       row_number() OVER (
                           PARTITION BY f.shipment_id, f.content_item_id
                           ORDER BY f.metric_snapshot_id DESC
                       ) AS rn
                FROM fact_seeding_content f
            ),
            creator_country AS (
                SELECT DISTINCT ON (creator_id) creator_id, country_code, city
                FROM dim_geo
                WHERE country_code IS NOT NULL
                ORDER BY creator_id,
                         CASE confidence_level
                             WHEN 'HIGH' THEN 0 WHEN 'MEDIUM' THEN 1 WHEN 'LOW' THEN 2 ELSE 3
                         END,
                         geo_id
            )
            -- Deep-review fixes, mirroring the (fixed) canonical product
            -- view so slices reconcile with it: one row per content item
            -- per product bucket (M2 — multi-shipment links don't double-
            -- count) and a NULL — never fabricated-zero — engagement sum
            -- when no component was observed (H1, DP-001).
            SELECT d.grain,
                   d.bucket_start,
                   d.product_id,
                   d.platform,
                   d.content_type,
                   d.country,
                   d.city,
                   count(DISTINCT d.creator_id) AS creators_reached,
                   count(*) AS content_count,
                   sum(d.views) AS total_views,
                   sum(CASE WHEN d.likes IS NULL AND d.comments IS NULL
                             AND d.shares IS NULL AND d.saves IS NULL
                            THEN NULL
                            ELSE coalesce(d.likes, 0) + coalesce(d.comments, 0)
                                 + coalesce(d.shares, 0) + coalesce(d.saves, 0)
                       END) AS total_engagement,
                   sum(d.estimated_reach) AS total_estimated_reach,
                   'ESTIMATED'::text AS total_estimated_reach_tier,
                   sum(d.emv) AS total_emv,
                   'ESTIMATED'::text AS total_emv_tier
            FROM (
                SELECT g.grain,
                       date_trunc(g.grain, c.date_key)::date AS bucket_start,
                       c.product_id,
                       c.platform,
                       c.content_type,
                       geo.country_code AS country,
                       geo.city,
                       c.creator_id,
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
                LEFT JOIN creator_country geo ON geo.creator_id = c.creator_id
                WHERE c.rn = 1
            ) d
            WHERE d.crn = 1
            GROUP BY d.grain, d.bucket_start, d.product_id,
                     d.platform, d.content_type, d.country, d.city
        SQL);

        DB::statement('CREATE UNIQUE INDEX rollup_seeding_by_product_slice_key ON rollup_seeding_by_product_slice (grain, bucket_start, product_id, platform, content_type, country, city)');
    }

    public function down(): void
    {
        DB::statement('DROP MATERIALIZED VIEW IF EXISTS rollup_seeding_by_product_slice');
        DB::statement('DROP MATERIALIZED VIEW IF EXISTS rollup_mention_by_campaign');
    }
};
