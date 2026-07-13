<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Tenant ownership for the analytics star schema (ADR-0019 — analytics
 * slice, following the OLTP slice in 2026_07_11_100002).
 *
 *  1. Every FACT-* table and every ENTITY dimension (DIM-Creator, DIM-Client,
 *     DIM-Brand, DIM-Product, DIM-Campaign, DIM-SeedingCampaign, DIM-Geo)
 *     gains NOT NULL tenant_id. DIM-Date and the enum dimensions
 *     (dim_platform, dim_content_type, dim_mention_type, dim_sentiment,
 *     dim_metric_tier, dim_sector) are GLOBAL closed sets and stay untouched.
 *
 *     BACKFILL ASSUMPTION (same as the OLTP slice, explicit — not silent):
 *     rows existing before this migration belong to the single pre-tenancy
 *     install and are assigned to the founding (earliest) tenant. A table
 *     with rows but no tenant to own them aborts loudly rather than
 *     guessing. The backfill uses ADD COLUMN ... DEFAULT <id> then DROP
 *     DEFAULT — a catalog-level fill with no UPDATE statements, so the
 *     append-only row triggers on the facts never fire (ALTER on the
 *     partitioned parent cascades to its partitions). The same uniform
 *     path is used for the (ordinary, mutable) dims for simplicity.
 *
 *     NO FK to tenants is added here: fact tables carry NO foreign keys at
 *     all by design (loaders stamp ids; referential truth lives in OLTP),
 *     and the dims are conformed copies with the same rule — tenant_id is
 *     an ownership stamp, enforced at the OLTP source it is copied from.
 *
 *     Each fact table gets a plain tenant_id index (<table>_tenant_index);
 *     dims are small lookup tables and get none.
 *
 *  2. All 9 ROLLUP-* materialized views are rebuilt (a matview cannot be
 *     altered in place) with tenant_id added to the SELECT and GROUP BY —
 *     sourced from the DRIVING FACT table (dim_geo for rollup_metric_by_geo,
 *     whose grouping key IS the geo dimension). No other change: grain
 *     rules, tier-honesty and NULL-not-zero semantics are preserved
 *     verbatim from 2026_07_05_200003 / 2026_07_06_110001 /
 *     2026_07_08_210001. The one grouping consequence rides along: the
 *     share_of_voice window in rollup_mention_by_brand partitions per
 *     tenant, because GL-ShareOfVoice's competitive set is one tenant's
 *     monitored world — never the whole platform's. Every unique index is
 *     recreated with tenant_id prepended; rollup_metric_by_geo keeps its
 *     NON-unique index with tenant_id as leading column.
 *
 *  3. qds_analytics_append_only() needs NO change: its gates are per-table
 *     transaction-local GUCs, orthogonal to tenancy.
 */
return new class extends Migration
{
    /** Partitioned append-only facts — tenant index added. */
    private const FACT_TABLES = [
        'fact_content_metric',
        'fact_creator_account',
        'fact_mention',
        'fact_shipment',
        'fact_seeding_content',
    ];

    /** Entity dims — tenant column only (small lookup tables, no index). */
    private const ENTITY_DIMS = [
        'dim_creator',
        'dim_client',
        'dim_brand',
        'dim_product',
        'dim_campaign',
        'dim_seeding_campaign',
        'dim_geo',
    ];

    private const ROLLUPS = [
        'rollup_creator_by_period',
        'rollup_mention_by_brand',
        'rollup_metric_by_geo',
        'rollup_seeding_by_shipment',
        'rollup_seeding_by_creator_campaign',
        'rollup_seeding_by_product',
        'rollup_seeding_by_brand',
        'rollup_mention_by_campaign',
        'rollup_seeding_by_product_slice',
    ];

    public function up(): void
    {
        $foundingTenantId = DB::table('tenants')->orderBy('id')->value('id');

        foreach ([...self::FACT_TABLES, ...self::ENTITY_DIMS] as $table) {
            $this->addTenantColumn($table, $foundingTenantId === null ? null : (int) $foundingTenantId);
        }

        foreach (self::FACT_TABLES as $table) {
            DB::statement("CREATE INDEX {$table}_tenant_index ON {$table} (tenant_id)");
        }

        $this->dropRollups();
        $this->createTenantRollups();
    }

    public function down(): void
    {
        $this->dropRollups();

        foreach (self::FACT_TABLES as $table) {
            DB::statement("DROP INDEX IF EXISTS {$table}_tenant_index");
        }

        foreach ([...self::FACT_TABLES, ...self::ENTITY_DIMS] as $table) {
            DB::statement("ALTER TABLE {$table} DROP COLUMN IF EXISTS tenant_id");
        }

        $this->createOriginalRollups();
    }

    private function addTenantColumn(string $table, ?int $foundingTenantId): void
    {
        $hasRows = DB::table($table)->exists();

        if ($hasRows && $foundingTenantId === null) {
            throw new RuntimeException(
                "Table [{$table}] contains rows but no tenant exists to own them. "
                .'Refusing to guess ownership (ADR-0019): create the tenant and backfill explicitly.'
            );
        }

        if ($hasRows) {
            // Catalog-level default fill: no UPDATEs, so the append-only
            // triggers never fire; the default is dropped immediately —
            // new rows must state their owner.
            DB::statement("ALTER TABLE {$table} ADD COLUMN tenant_id bigint NOT NULL DEFAULT {$foundingTenantId}");
            DB::statement("ALTER TABLE {$table} ALTER COLUMN tenant_id DROP DEFAULT");
        } else {
            DB::statement("ALTER TABLE {$table} ADD COLUMN tenant_id bigint NOT NULL");
        }
    }

    private function dropRollups(): void
    {
        foreach (array_reverse(self::ROLLUPS) as $view) {
            DB::statement("DROP MATERIALIZED VIEW IF EXISTS {$view}");
        }
    }

    /**
     * The 9 rollups with tenant_id — SQL copied verbatim from
     * 2026_07_05_200003 (seven canonical), 2026_07_06_110001
     * (rollup_mention_by_campaign) and 2026_07_08_210001
     * (rollup_seeding_by_product_slice, city version); the ONLY change is
     * tenant_id in SELECT / GROUP BY (+ the SoV window partition).
     */
    private function createTenantRollups(): void
    {
        // ROLLUP-CreatorByPeriod — creator × period. Serves overall
        // influencer-account monitoring (REQ-M1-005/007, AC-M1-021).
        // tenant_id: fact_creator_account / fact_content_metric.
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

        // ROLLUP-MentionByBrand — brand × period (brand monitoring
        // dashboards; share_of_voice per GL-ShareOfVoice over the monitored
        // competitive set = all brand-attributed mentions in the bucket —
        // per tenant: one tenant's monitored world, never the platform's).
        // tenant_id: fact_mention.
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

        // ROLLUP-MetricByGeo — country/city × platform × period. DIM-Geo is
        // confidence-based and unpopulated until Module 2 ships
        // ENT-GeoAttribution; the rollup exists (schema-complete) and stays
        // empty — geo panels render "Unavailable", never zero.
        // tenant_id: dim_geo (the grouping key IS the geo dimension).
        DB::statement(<<<'SQL'
            CREATE MATERIALIZED VIEW rollup_metric_by_geo AS
            WITH grains(grain) AS (VALUES ('week'), ('month'), ('quarter'), ('year'))
            SELECT g.grain,
                   date_trunc(g.grain, f.date_key)::date AS bucket_start,
                   geo.tenant_id,
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
            GROUP BY g.grain, date_trunc(g.grain, f.date_key), geo.tenant_id, geo.country_code, geo.city, f.platform
        SQL);

        DB::statement('CREATE INDEX rollup_metric_by_geo_key ON rollup_metric_by_geo (tenant_id, grain, bucket_start, country_code, platform)');

        // ROLLUP-SeedingByShipment — per shipment: what was sent, did they
        // post, when, how did it perform. Facts arrive with the P3 loaders
        // (ENT-Shipment / ENT-Product ship in P3); structure is P0-complete.
        // tenant_id: fact_shipment.
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
            SELECT s.tenant_id,
                   s.shipment_id,
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

        DB::statement('CREATE UNIQUE INDEX rollup_seeding_by_shipment_key ON rollup_seeding_by_shipment (tenant_id, shipment_id)');

        // ROLLUP-SeedingByCreatorCampaign — creator × seeding campaign.
        // Deep-review fixes: distinct_content dedupes a content item linked
        // to several shipments of the same run so it counts once (M2); the
        // engagement sum is NULL — never a fabricated zero — when no
        // component was observed (H1, DP-001; matches ROLLUP-MentionByCampaign).
        // tenant_id: fact_shipment / fact_seeding_content.
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
                SELECT tenant_id, creator_id, seeding_campaign_id,
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
                GROUP BY tenant_id, creator_id, seeding_campaign_id
            ),
            shipment_rollup AS (
                SELECT tenant_id, creator_id, seeding_campaign_id,
                       count(*) AS shipments,
                       sum(posted) AS posted
                FROM fact_shipment
                GROUP BY tenant_id, creator_id, seeding_campaign_id
            )
            SELECT tenant_id,
                   creator_id,
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
            FULL JOIN content_rollup c USING (tenant_id, creator_id, seeding_campaign_id)
        SQL);

        DB::statement('CREATE UNIQUE INDEX rollup_seeding_by_creator_campaign_key ON rollup_seeding_by_creator_campaign (tenant_id, creator_id, seeding_campaign_id)');

        // ROLLUP-SeedingByProduct — product (all creators) × period. The
        // "product seeded to N influencers → one total" rollup
        // (REQ-M3-013). post_rate is DERIVED — recomputed at this grain.
        // tenant_id: fact_shipment / fact_seeding_content.
        DB::statement(<<<'SQL'
            CREATE MATERIALIZED VIEW rollup_seeding_by_product AS
            WITH grains(grain) AS (VALUES ('week'), ('month'), ('quarter'), ('year')),
            shipment_rollup AS (
                SELECT g.grain,
                       date_trunc(g.grain, s.date_key)::date AS bucket_start,
                       s.tenant_id,
                       s.product_id,
                       count(*) AS shipments,
                       sum(s.posted) AS posted_count,
                       count(DISTINCT s.creator_id) AS creators_reached
                FROM fact_shipment s
                CROSS JOIN grains g
                GROUP BY g.grain, date_trunc(g.grain, s.date_key), s.tenant_id, s.product_id
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
                       d.tenant_id,
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
                           c.tenant_id,
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
                GROUP BY d.grain, d.bucket_start, d.tenant_id, d.product_id
            )
            SELECT grain,
                   bucket_start,
                   tenant_id,
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
            FULL JOIN content_rollup c USING (grain, bucket_start, tenant_id, product_id)
        SQL);

        DB::statement('CREATE UNIQUE INDEX rollup_seeding_by_product_key ON rollup_seeding_by_product (tenant_id, grain, bucket_start, product_id)');

        // ROLLUP-SeedingByBrand — brand × period.
        // tenant_id: fact_shipment / fact_seeding_content.
        DB::statement(<<<'SQL'
            CREATE MATERIALIZED VIEW rollup_seeding_by_brand AS
            WITH grains(grain) AS (VALUES ('week'), ('month'), ('quarter'), ('year')),
            shipment_rollup AS (
                SELECT g.grain,
                       date_trunc(g.grain, s.date_key)::date AS bucket_start,
                       s.tenant_id,
                       s.brand_id,
                       count(*) AS shipments,
                       sum(s.posted) AS posted_count
                FROM fact_shipment s
                CROSS JOIN grains g
                WHERE s.brand_id IS NOT NULL
                GROUP BY g.grain, date_trunc(g.grain, s.date_key), s.tenant_id, s.brand_id
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
                       d.tenant_id,
                       d.brand_id,
                       count(*) AS content_count,
                       sum(d.views) AS total_views,
                       sum(d.estimated_reach) AS total_estimated_reach,
                       sum(d.emv) AS total_emv
                FROM (
                    SELECT g.grain,
                           date_trunc(g.grain, c.date_key)::date AS bucket_start,
                           c.tenant_id,
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
                GROUP BY d.grain, d.bucket_start, d.tenant_id, d.brand_id
            )
            SELECT grain,
                   bucket_start,
                   tenant_id,
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
            FULL JOIN content_rollup c USING (grain, bucket_start, tenant_id, brand_id)
        SQL);

        DB::statement('CREATE UNIQUE INDEX rollup_seeding_by_brand_key ON rollup_seeding_by_brand (tenant_id, grain, bucket_start, brand_id)');

        // rollup_mention_by_campaign — the campaign half of REQ-M3-009
        // (additive, NON-canonical — Step-4 D3). Engagement sum is NULL when
        // NO component was observed (DP-001).
        // tenant_id: fact_mention.
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

        // rollup_seeding_by_product_slice — content-side measures per
        // platform / content type / country / city (additive, NON-canonical
        // — Step-4 D5; city added by 2026_07_08_210001). country/city ride
        // the creator's highest-confidence DIM-Geo row; creators without
        // geography stay NULL and render "Unavailable" — never zero.
        // tenant_id: fact_seeding_content (the FACT side, not dim_geo).
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
                   d.tenant_id,
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
                       c.tenant_id,
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
            GROUP BY d.grain, d.bucket_start, d.tenant_id, d.product_id,
                     d.platform, d.content_type, d.country, d.city
        SQL);

        DB::statement('CREATE UNIQUE INDEX rollup_seeding_by_product_slice_key ON rollup_seeding_by_product_slice (tenant_id, grain, bucket_start, product_id, platform, content_type, country, city)');
    }

    /**
     * The pre-tenancy definitions, copied verbatim from 2026_07_05_200003,
     * 2026_07_06_110001 and 2026_07_08_210001 (city version) for rollback.
     */
    private function createOriginalRollups(): void
    {
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
};
