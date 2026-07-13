<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Adds the CITY dimension to rollup_seeding_by_product_slice so /crm/results
 * can report per country AND per city (extends module-3 §2.11 / AC-M3-019
 * "any time grain and slice" — same additive, NON-canonical class as the
 * view itself, Step-4 D5).
 *
 * city rides the SAME highest-confidence DIM-Geo row per creator that the
 * country slice uses (one row per creator — no measure fan-out), so a
 * country+city pair is always internally consistent. Rows whose creator has
 * no geography keep NULL country/city and render "Unavailable", never zero
 * (DP-001). A materialized view cannot be altered in place — drop/recreate.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('DROP MATERIALIZED VIEW IF EXISTS rollup_seeding_by_product_slice');

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
            creator_geo AS (
                SELECT DISTINCT ON (creator_id) creator_id, country_code, city
                FROM dim_geo
                WHERE country_code IS NOT NULL OR city IS NOT NULL
                ORDER BY creator_id,
                         CASE confidence_level
                             WHEN 'HIGH' THEN 0 WHEN 'MEDIUM' THEN 1 WHEN 'LOW' THEN 2 ELSE 3
                         END,
                         geo_id
            )
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
                       geo.city AS city,
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
                LEFT JOIN creator_geo geo ON geo.creator_id = c.creator_id
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

        // Original city-less definition (2026_07_06_110001).
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
                SELECT DISTINCT ON (creator_id) creator_id, country_code
                FROM dim_geo
                WHERE country_code IS NOT NULL
                ORDER BY creator_id,
                         CASE confidence_level
                             WHEN 'HIGH' THEN 0 WHEN 'MEDIUM' THEN 1 WHEN 'LOW' THEN 2 ELSE 3
                         END,
                         geo_id
            )
            SELECT d.grain,
                   d.bucket_start,
                   d.product_id,
                   d.platform,
                   d.content_type,
                   d.country,
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
                     d.platform, d.content_type, d.country
        SQL);

        DB::statement('CREATE UNIQUE INDEX rollup_seeding_by_product_slice_key ON rollup_seeding_by_product_slice (grain, bucket_start, product_id, platform, content_type, country)');
    }
};
