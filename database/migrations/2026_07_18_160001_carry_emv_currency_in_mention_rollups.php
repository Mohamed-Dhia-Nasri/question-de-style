<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Carry EMV currency through the mention rollups and refuse to sum across
 * currencies (M24).
 *
 * fact_mention.emv_currency was dropped by rollup_mention_by_brand and
 * rollup_mention_by_campaign, which summed emv regardless of currency — so a
 * tenant whose EMV configuration currency changed over time saw EUR and USD
 * added into one meaningless number presented with no label. This redefines
 * both views (on top of the H4 dedup, 2026_07_18_140001) to add
 * total_emv_currency and to null total_emv/currency when a bucket mixes
 * currencies, so a mixed total reads as Unavailable (DP-001) rather than a
 * false figure. Single-currency buckets carry their ISO code through.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('DROP MATERIALIZED VIEW IF EXISTS rollup_mention_by_brand');
        DB::statement(<<<'SQL'
            CREATE MATERIALIZED VIEW rollup_mention_by_brand AS
            WITH grains(grain) AS (VALUES ('week'), ('month'), ('quarter'), ('year')),
            ranked AS (
                SELECT g.grain,
                       date_trunc(g.grain, f.date_key)::date AS bucket_start,
                       f.tenant_id,
                       f.brand_id,
                       f.views,
                       f.estimated_reach,
                       f.emv,
                       f.emv_currency,
                       row_number() OVER (
                           PARTITION BY g.grain, date_trunc(g.grain, f.date_key),
                                        f.tenant_id, f.brand_id, f.content_item_id, f.story_id
                           ORDER BY f.id DESC
                       ) AS content_rn
                FROM fact_mention f
                CROSS JOIN grains g
                WHERE f.brand_id IS NOT NULL
            ),
            brand_buckets AS (
                SELECT grain, bucket_start, tenant_id, brand_id,
                       count(*) AS mention_count,
                       sum(views) FILTER (WHERE content_rn = 1) AS total_views,
                       sum(estimated_reach) FILTER (WHERE content_rn = 1) AS total_estimated_reach,
                       sum(emv) FILTER (WHERE content_rn = 1) AS emv_sum,
                       count(DISTINCT emv_currency) FILTER (WHERE content_rn = 1 AND emv IS NOT NULL) AS emv_currency_variants,
                       max(emv_currency) FILTER (WHERE content_rn = 1 AND emv IS NOT NULL) AS emv_currency_any
                FROM ranked
                GROUP BY grain, bucket_start, tenant_id, brand_id
            )
            SELECT grain,
                   bucket_start,
                   tenant_id,
                   brand_id,
                   mention_count,
                   total_views,
                   total_estimated_reach,
                   'ESTIMATED'::text AS total_estimated_reach_tier,
                   CASE WHEN emv_currency_variants > 1 THEN NULL ELSE emv_sum END AS total_emv,
                   'ESTIMATED'::text AS total_emv_tier,
                   CASE WHEN emv_currency_variants > 1 THEN NULL ELSE emv_currency_any END AS total_emv_currency,
                   mention_count::numeric
                       / nullif(sum(mention_count) OVER (PARTITION BY tenant_id, grain, bucket_start), 0)
                       AS share_of_voice
            FROM brand_buckets
        SQL);
        DB::statement('CREATE UNIQUE INDEX rollup_mention_by_brand_key ON rollup_mention_by_brand (tenant_id, grain, bucket_start, brand_id)');

        DB::statement('DROP MATERIALIZED VIEW IF EXISTS rollup_mention_by_campaign');
        DB::statement(<<<'SQL'
            CREATE MATERIALIZED VIEW rollup_mention_by_campaign AS
            WITH grains(grain) AS (VALUES ('week'), ('month'), ('quarter'), ('year')),
            ranked AS (
                SELECT g.grain,
                       date_trunc(g.grain, f.date_key)::date AS bucket_start,
                       f.tenant_id,
                       f.campaign_id,
                       f.content_item_id,
                       f.views, f.likes, f.comments, f.shares, f.saves,
                       f.estimated_reach, f.emv, f.emv_currency,
                       row_number() OVER (
                           PARTITION BY g.grain, date_trunc(g.grain, f.date_key),
                                        f.tenant_id, f.campaign_id, f.content_item_id, f.story_id
                           ORDER BY f.id DESC
                       ) AS content_rn
                FROM fact_mention f
                CROSS JOIN grains g
                WHERE f.campaign_id IS NOT NULL
            )
            SELECT grain,
                   bucket_start,
                   tenant_id,
                   campaign_id,
                   count(*) AS mention_count,
                   count(DISTINCT content_item_id) AS content_count,
                   sum(views) FILTER (WHERE content_rn = 1) AS total_views,
                   sum(likes) FILTER (WHERE content_rn = 1) AS total_likes,
                   sum(comments) FILTER (WHERE content_rn = 1) AS total_comments,
                   sum(CASE WHEN likes IS NULL AND comments IS NULL
                             AND shares IS NULL AND saves IS NULL
                            THEN NULL
                            ELSE coalesce(likes, 0) + coalesce(comments, 0)
                                 + coalesce(shares, 0) + coalesce(saves, 0)
                       END) FILTER (WHERE content_rn = 1) AS total_engagement,
                   sum(estimated_reach) FILTER (WHERE content_rn = 1) AS total_estimated_reach,
                   'ESTIMATED'::text AS total_estimated_reach_tier,
                   CASE WHEN count(DISTINCT emv_currency) FILTER (WHERE content_rn = 1 AND emv IS NOT NULL) > 1
                        THEN NULL ELSE sum(emv) FILTER (WHERE content_rn = 1) END AS total_emv,
                   'ESTIMATED'::text AS total_emv_tier,
                   CASE WHEN count(DISTINCT emv_currency) FILTER (WHERE content_rn = 1 AND emv IS NOT NULL) > 1
                        THEN NULL ELSE max(emv_currency) FILTER (WHERE content_rn = 1 AND emv IS NOT NULL) END AS total_emv_currency
            FROM ranked
            GROUP BY grain, bucket_start, tenant_id, campaign_id
        SQL);
        DB::statement('CREATE UNIQUE INDEX rollup_mention_by_campaign_key ON rollup_mention_by_campaign (tenant_id, grain, bucket_start, campaign_id)');
    }

    public function down(): void
    {
        // Restore the H4 definitions (2026_07_18_140001) — content-deduped but
        // currency-blind.
        DB::statement('DROP MATERIALIZED VIEW IF EXISTS rollup_mention_by_brand');
        DB::statement(<<<'SQL'
            CREATE MATERIALIZED VIEW rollup_mention_by_brand AS
            WITH grains(grain) AS (VALUES ('week'), ('month'), ('quarter'), ('year')),
            ranked AS (
                SELECT g.grain,
                       date_trunc(g.grain, f.date_key)::date AS bucket_start,
                       f.tenant_id,
                       f.brand_id,
                       f.views,
                       f.estimated_reach,
                       f.emv,
                       row_number() OVER (
                           PARTITION BY g.grain, date_trunc(g.grain, f.date_key),
                                        f.tenant_id, f.brand_id, f.content_item_id, f.story_id
                           ORDER BY f.id DESC
                       ) AS content_rn
                FROM fact_mention f
                CROSS JOIN grains g
                WHERE f.brand_id IS NOT NULL
            ),
            brand_buckets AS (
                SELECT grain, bucket_start, tenant_id, brand_id,
                       count(*) AS mention_count,
                       sum(views) FILTER (WHERE content_rn = 1) AS total_views,
                       sum(estimated_reach) FILTER (WHERE content_rn = 1) AS total_estimated_reach,
                       sum(emv) FILTER (WHERE content_rn = 1) AS total_emv
                FROM ranked
                GROUP BY grain, bucket_start, tenant_id, brand_id
            )
            SELECT grain,
                   bucket_start,
                   tenant_id,
                   brand_id,
                   mention_count,
                   total_views,
                   total_estimated_reach,
                   'ESTIMATED'::text AS total_estimated_reach_tier,
                   total_emv,
                   'ESTIMATED'::text AS total_emv_tier,
                   mention_count::numeric
                       / nullif(sum(mention_count) OVER (PARTITION BY tenant_id, grain, bucket_start), 0)
                       AS share_of_voice
            FROM brand_buckets
        SQL);
        DB::statement('CREATE UNIQUE INDEX rollup_mention_by_brand_key ON rollup_mention_by_brand (tenant_id, grain, bucket_start, brand_id)');

        DB::statement('DROP MATERIALIZED VIEW IF EXISTS rollup_mention_by_campaign');
        DB::statement(<<<'SQL'
            CREATE MATERIALIZED VIEW rollup_mention_by_campaign AS
            WITH grains(grain) AS (VALUES ('week'), ('month'), ('quarter'), ('year')),
            ranked AS (
                SELECT g.grain,
                       date_trunc(g.grain, f.date_key)::date AS bucket_start,
                       f.tenant_id,
                       f.campaign_id,
                       f.content_item_id,
                       f.views, f.likes, f.comments, f.shares, f.saves,
                       f.estimated_reach, f.emv,
                       row_number() OVER (
                           PARTITION BY g.grain, date_trunc(g.grain, f.date_key),
                                        f.tenant_id, f.campaign_id, f.content_item_id, f.story_id
                           ORDER BY f.id DESC
                       ) AS content_rn
                FROM fact_mention f
                CROSS JOIN grains g
                WHERE f.campaign_id IS NOT NULL
            )
            SELECT grain,
                   bucket_start,
                   tenant_id,
                   campaign_id,
                   count(*) AS mention_count,
                   count(DISTINCT content_item_id) AS content_count,
                   sum(views) FILTER (WHERE content_rn = 1) AS total_views,
                   sum(likes) FILTER (WHERE content_rn = 1) AS total_likes,
                   sum(comments) FILTER (WHERE content_rn = 1) AS total_comments,
                   sum(CASE WHEN likes IS NULL AND comments IS NULL
                             AND shares IS NULL AND saves IS NULL
                            THEN NULL
                            ELSE coalesce(likes, 0) + coalesce(comments, 0)
                                 + coalesce(shares, 0) + coalesce(saves, 0)
                       END) FILTER (WHERE content_rn = 1) AS total_engagement,
                   sum(estimated_reach) FILTER (WHERE content_rn = 1) AS total_estimated_reach,
                   'ESTIMATED'::text AS total_estimated_reach_tier,
                   sum(emv) FILTER (WHERE content_rn = 1) AS total_emv,
                   'ESTIMATED'::text AS total_emv_tier
            FROM ranked
            GROUP BY grain, bucket_start, tenant_id, campaign_id
        SQL);
        DB::statement('CREATE UNIQUE INDEX rollup_mention_by_campaign_key ON rollup_mention_by_campaign (tenant_id, grain, bucket_start, campaign_id)');
    }
};
