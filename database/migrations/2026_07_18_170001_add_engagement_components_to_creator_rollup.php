<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Expose the engagement COMPONENTS (likes, comments, shares, saves) in the
 * creator rollup so the monitoring overview can show each total on its own,
 * alongside the combined Engagement figure.
 *
 * Built on the tenant-aware definition (2026_07_11_200001). engagement_sum and
 * engagement_rate are left exactly as they were (ENGAGEMENT keeps its canonical
 * meaning); this only ADDS the four per-component sums, each NULL when nothing
 * was observed (DP-001 — never a fabricated zero).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('DROP MATERIALIZED VIEW IF EXISTS rollup_creator_by_period');
        DB::statement(<<<'SQL'
            CREATE MATERIALIZED VIEW rollup_creator_by_period AS
            WITH grains(grain) AS (VALUES ('week'), ('month'), ('quarter'), ('year')),
            account_buckets AS (
                SELECT g.grain,
                       date_trunc(g.grain, f.date_key)::date AS bucket_start,
                       f.tenant_id,
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
                SELECT grain, bucket_start, tenant_id, creator_id,
                       sum(followers) FILTER (WHERE rn_last = 1) AS followers,
                       sum(followers) FILTER (WHERE rn_last = 1)
                           - sum(followers) FILTER (WHERE rn_first = 1) AS follower_growth,
                       count(DISTINCT platform_account_id) AS platform_accounts
                FROM account_buckets
                GROUP BY grain, bucket_start, tenant_id, creator_id
            ),
            content_latest AS (
                SELECT g.grain,
                       date_trunc(g.grain, f.date_key)::date AS bucket_start,
                       f.tenant_id,
                       f.creator_id,
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
                SELECT grain, bucket_start, tenant_id, creator_id,
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
                GROUP BY grain, bucket_start, tenant_id, creator_id
            )
            SELECT grain,
                   bucket_start,
                   tenant_id,
                   creator_id,
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
                   CASE WHEN a.followers > 0
                        THEN c.engagement_sum / a.followers
                   END AS engagement_rate,
                   NULL::numeric AS posting_frequency,
                   c.last_post_at
            FROM account_rollup a
            FULL JOIN content_rollup c USING (grain, bucket_start, tenant_id, creator_id)
        SQL);
        DB::statement('CREATE UNIQUE INDEX rollup_creator_by_period_key ON rollup_creator_by_period (tenant_id, grain, bucket_start, creator_id)');
    }

    public function down(): void
    {
        // Restore the tenant-aware definition (2026_07_11_200001) — combined
        // engagement only, no per-component sums.
        DB::statement('DROP MATERIALIZED VIEW IF EXISTS rollup_creator_by_period');
        DB::statement(<<<'SQL'
            CREATE MATERIALIZED VIEW rollup_creator_by_period AS
            WITH grains(grain) AS (VALUES ('week'), ('month'), ('quarter'), ('year')),
            account_buckets AS (
                SELECT g.grain,
                       date_trunc(g.grain, f.date_key)::date AS bucket_start,
                       f.tenant_id,
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
                SELECT grain, bucket_start, tenant_id, creator_id,
                       sum(followers) FILTER (WHERE rn_last = 1) AS followers,
                       sum(followers) FILTER (WHERE rn_last = 1)
                           - sum(followers) FILTER (WHERE rn_first = 1) AS follower_growth,
                       count(DISTINCT platform_account_id) AS platform_accounts
                FROM account_buckets
                GROUP BY grain, bucket_start, tenant_id, creator_id
            ),
            content_latest AS (
                SELECT g.grain,
                       date_trunc(g.grain, f.date_key)::date AS bucket_start,
                       f.tenant_id,
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
                SELECT grain, bucket_start, tenant_id, creator_id,
                       count(*) AS content_count,
                       sum(views) AS views_sum,
                       avg(views) AS avg_views,
                       sum(engagement) AS engagement_sum,
                       max(published_at) AS last_post_at
                FROM content_latest
                WHERE rn = 1
                GROUP BY grain, bucket_start, tenant_id, creator_id
            )
            SELECT grain,
                   bucket_start,
                   tenant_id,
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
            FULL JOIN content_rollup c USING (grain, bucket_start, tenant_id, creator_id)
        SQL);
        DB::statement('CREATE UNIQUE INDEX rollup_creator_by_period_key ON rollup_creator_by_period (tenant_id, grain, bucket_start, creator_id)');
    }
};
