<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Fix double-counted content measures in the mention rollups (H4).
 *
 * The mentions unique index is (monitored_subject_id, content_item_id), so a
 * single content item legitimately produces MANY fact_mention rows (two
 * monitored subjects tied to the same brand/campaign). loadMentionFacts stamps
 * each of those rows with the SAME content-level views / likes / comments /
 * reach / EMV, so rollup_mention_by_brand and rollup_mention_by_campaign — which
 * sum those measures over every fact row — inflate audience and EMV N-fold while
 * content_count (count(DISTINCT content_item_id)) stays correct.
 *
 * The fix mirrors the dedupe rollup_seeding_by_product_slice already uses: rank
 * fact rows per (bucket, content) and sum each content-level measure only for the
 * first row of each content (FILTER (WHERE content_rn = 1)). mention_count stays
 * count(*) (a content mentioned twice IS two mentions); content_count stays
 * count(DISTINCT content_item_id). Story mentions carry NULL measures and are
 * partitioned by story_id so distinct stories are never collapsed.
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

    public function down(): void
    {
        // Restore the pre-fix (double-counting) definitions from
        // 2026_07_11_200001_add_tenant_to_analytics.
        DB::statement('DROP MATERIALIZED VIEW IF EXISTS rollup_mention_by_brand');
        DB::statement(<<<'SQL'
            CREATE MATERIALIZED VIEW rollup_mention_by_brand AS
            WITH grains(grain) AS (VALUES ('week'), ('month'), ('quarter'), ('year')),
            brand_buckets AS (
                SELECT g.grain,
                       date_trunc(g.grain, f.date_key)::date AS bucket_start,
                       f.tenant_id,
                       f.brand_id,
                       count(*) AS mention_count,
                       sum(f.views) AS total_views,
                       sum(f.estimated_reach) AS total_estimated_reach,
                       sum(f.emv) AS total_emv
                FROM fact_mention f
                CROSS JOIN grains g
                WHERE f.brand_id IS NOT NULL
                GROUP BY g.grain, date_trunc(g.grain, f.date_key), f.tenant_id, f.brand_id
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
            campaign_buckets AS (
                SELECT g.grain,
                       date_trunc(g.grain, f.date_key)::date AS bucket_start,
                       f.tenant_id,
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
                GROUP BY g.grain, date_trunc(g.grain, f.date_key), f.tenant_id, f.campaign_id
            )
            SELECT grain,
                   bucket_start,
                   tenant_id,
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
        DB::statement('CREATE UNIQUE INDEX rollup_mention_by_campaign_key ON rollup_mention_by_campaign (tenant_id, grain, bucket_start, campaign_id)');
    }
};
