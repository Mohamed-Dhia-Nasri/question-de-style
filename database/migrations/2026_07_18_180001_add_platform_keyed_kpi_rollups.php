<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Platform-keyed KPI rollups so the monitoring overview can filter its
 * headline totals by platform.
 *
 * The existing rollup_creator_by_period / rollup_mention_by_brand aggregate
 * per creator / brand across ALL platforms and feed many consumers (the
 * creator-detail trend, exports, campaign results). Rather than add a platform
 * dimension there and re-aggregate every consumer, this adds two purpose-built
 * variants keyed additionally by platform. Only RollupReader::creatorTotals /
 * mentionTotals read them — summed with no platform filter they equal the
 * agnostic totals; filtered to one platform they answer "…on YouTube".
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('DROP MATERIALIZED VIEW IF EXISTS rollup_creator_by_period_platform');
        DB::statement(<<<'SQL'
            CREATE MATERIALIZED VIEW rollup_creator_by_period_platform AS
            WITH grains(grain) AS (VALUES ('week'), ('month'), ('quarter'), ('year')),
            account_buckets AS (
                SELECT g.grain,
                       date_trunc(g.grain, f.date_key)::date AS bucket_start,
                       f.tenant_id,
                       f.creator_id,
                       f.platform,
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
                SELECT grain, bucket_start, tenant_id, creator_id, platform,
                       sum(followers) FILTER (WHERE rn_last = 1) AS followers,
                       sum(followers) FILTER (WHERE rn_last = 1)
                           - sum(followers) FILTER (WHERE rn_first = 1) AS follower_growth,
                       count(DISTINCT platform_account_id) AS platform_accounts
                FROM account_buckets
                GROUP BY grain, bucket_start, tenant_id, creator_id, platform
            ),
            content_latest AS (
                SELECT g.grain,
                       date_trunc(g.grain, f.date_key)::date AS bucket_start,
                       f.tenant_id,
                       f.creator_id,
                       f.platform,
                       f.content_item_id,
                       f.published_at,
                       coalesce(f.views, f.plays) AS views,
                       f.likes, f.comments, f.shares, f.saves,
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
                SELECT grain, bucket_start, tenant_id, creator_id, platform,
                       count(*) AS content_count,
                       sum(views) AS views_sum,
                       avg(views) AS avg_views,
                       sum(likes) AS likes_sum,
                       sum(comments) AS comments_sum,
                       sum(shares) AS shares_sum,
                       sum(saves) AS saves_sum,
                       sum(engagement) AS engagement_sum,
                       max(published_at) AS last_post_at
                FROM content_latest
                WHERE rn = 1
                GROUP BY grain, bucket_start, tenant_id, creator_id, platform
            )
            SELECT grain,
                   bucket_start,
                   tenant_id,
                   creator_id,
                   platform,
                   a.followers,
                   a.follower_growth,
                   a.platform_accounts,
                   c.content_count,
                   c.views_sum,
                   c.avg_views,
                   c.likes_sum,
                   c.comments_sum,
                   c.shares_sum,
                   c.saves_sum,
                   c.engagement_sum,
                   c.last_post_at
            FROM account_rollup a
            FULL JOIN content_rollup c USING (grain, bucket_start, tenant_id, creator_id, platform)
        SQL);
        DB::statement('CREATE UNIQUE INDEX rollup_creator_by_period_platform_key ON rollup_creator_by_period_platform (tenant_id, grain, bucket_start, creator_id, platform)');

        DB::statement('DROP MATERIALIZED VIEW IF EXISTS rollup_mention_by_brand_platform');
        DB::statement(<<<'SQL'
            CREATE MATERIALIZED VIEW rollup_mention_by_brand_platform AS
            WITH grains(grain) AS (VALUES ('week'), ('month'), ('quarter'), ('year')),
            ranked AS (
                SELECT g.grain,
                       date_trunc(g.grain, f.date_key)::date AS bucket_start,
                       f.tenant_id,
                       f.brand_id,
                       f.platform,
                       f.views,
                       f.estimated_reach,
                       f.emv,
                       f.emv_currency,
                       row_number() OVER (
                           PARTITION BY g.grain, date_trunc(g.grain, f.date_key),
                                        f.tenant_id, f.brand_id, f.platform, f.content_item_id, f.story_id
                           ORDER BY f.id DESC
                       ) AS content_rn
                FROM fact_mention f
                CROSS JOIN grains g
                WHERE f.brand_id IS NOT NULL
            ),
            brand_buckets AS (
                SELECT grain, bucket_start, tenant_id, brand_id, platform,
                       count(*) AS mention_count,
                       sum(views) FILTER (WHERE content_rn = 1) AS total_views,
                       sum(estimated_reach) FILTER (WHERE content_rn = 1) AS total_estimated_reach,
                       sum(emv) FILTER (WHERE content_rn = 1) AS emv_sum,
                       count(DISTINCT emv_currency) FILTER (WHERE content_rn = 1 AND emv IS NOT NULL) AS emv_currency_variants,
                       max(emv_currency) FILTER (WHERE content_rn = 1 AND emv IS NOT NULL) AS emv_currency_any
                FROM ranked
                GROUP BY grain, bucket_start, tenant_id, brand_id, platform
            )
            SELECT grain,
                   bucket_start,
                   tenant_id,
                   brand_id,
                   platform,
                   mention_count,
                   total_views,
                   total_estimated_reach,
                   'ESTIMATED'::text AS total_estimated_reach_tier,
                   CASE WHEN emv_currency_variants > 1 THEN NULL ELSE emv_sum END AS total_emv,
                   'ESTIMATED'::text AS total_emv_tier,
                   CASE WHEN emv_currency_variants > 1 THEN NULL ELSE emv_currency_any END AS total_emv_currency
            FROM brand_buckets
        SQL);
        DB::statement('CREATE UNIQUE INDEX rollup_mention_by_brand_platform_key ON rollup_mention_by_brand_platform (tenant_id, grain, bucket_start, brand_id, platform)');
    }

    public function down(): void
    {
        DB::statement('DROP MATERIALIZED VIEW IF EXISTS rollup_creator_by_period_platform');
        DB::statement('DROP MATERIALIZED VIEW IF EXISTS rollup_mention_by_brand_platform');
    }
};
