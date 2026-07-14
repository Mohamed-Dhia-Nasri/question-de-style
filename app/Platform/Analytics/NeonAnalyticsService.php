<?php

namespace App\Platform\Analytics;

use App\Platform\Analytics\Contracts\AnalyticsService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * SVC-Analytics on Neon Postgres (ADR-0010 as amended by ADR-0013).
 *
 * One refresh pass = upsert dimensions from their OLTP sources, load new
 * facts append-only past the per-table high-water mark, then refresh every
 * registered ROLLUP-* materialized view. Driven by the app scheduler via
 * `qds:refresh-rollups` (Neon has no pg_cron).
 *
 * Loaders exist for every canonical fact table:
 *  - FACT-ContentMetric   ← content-level ENT-MetricSnapshot
 *  - FACT-CreatorAccount  ← account-level ENT-MetricSnapshot
 *  - FACT-Mention         ← ENT-Mention, stamped with the linked content's
 *                           latest metrics, sentiment, and EMV
 *  - FACT-Shipment        ← dispatched ENT-Shipment (P3 Step 4)
 *  - FACT-SeedingContent  ← shipment × resulting content × snapshot bucket
 * DIM-Geo stays empty until Module 2 ships ENT-GeoAttribution — consuming
 * surfaces render "Unavailable", never zero.
 *
 * Mutated-source semantics (Step-4 spec D2): facts are stamped ONCE at
 * insert with the then-latest joined state, past a per-source high-water
 * mark; a source that mutates afterwards is NOT re-stamped — time-varying
 * truth arrives as NEW append-only source rows (metric snapshots, link
 * pivots) that become new fact rows, and rollups reduce to the latest.
 * TWO exceptions carry a gated restamp (DELETE + fresh INSERT under a
 * transaction-local GUC, never UPDATE): FACT-Shipment — `posted` must
 * flip on the existing row (analytics-model §6 step 2; the canonical
 * rollups key one row per shipment), see loadShipmentFacts(); and
 * FACT-Mention — late XMC-002 campaign attribution must reach
 * ROLLUP-MentionByCampaign (deep-review C2 fix), see loadMentionFacts().
 * Both gates live in the allow_gated_fact_shipment_restamp migration
 * (documented in analytics-model §2 principle 3).
 *
 * Personal data: only internal ids and the public persona display name
 * enter the star schema — never email/phone/address/notes (DP-005).
 *
 * Multi-tenancy (ADR-0019, analytics slice): every entity-dim upsert and
 * fact INSERT stamps tenant_id from its OLTP source row (metric_snapshots /
 * mentions / shipments / the dim's own source table). The gated restamp
 * DELETEs key on source ids — which never cross tenants — so they carry no
 * tenant predicate. Hard read-side enforcement is a later phase.
 */
class NeonAnalyticsService implements AnalyticsService
{
    /**
     * Rollup catalog — the first seven are canonical in
     * docs/30-data-model/01-analytics-model.md §5; the last two are
     * ADDITIVE, NON-canonical companions (Step-4 spec D3/D5 — flagged
     * analytics-model §5 amendment candidates): the campaign half of
     * REQ-M3-009 and the platform/content-type/country slices of
     * REQ-M3-013 (AC-M3-019 "any time grain and slice").
     */
    public const ROLLUPS = [
        'rollup_seeding_by_shipment',
        'rollup_seeding_by_creator_campaign',
        'rollup_seeding_by_product',
        'rollup_seeding_by_brand',
        'rollup_mention_by_brand',
        'rollup_metric_by_geo',
        'rollup_creator_by_period',
        'rollup_mention_by_campaign',
        'rollup_seeding_by_product_slice',
    ];

    public function refreshRollups(): int
    {
        $startedAt = now();
        $refreshId = DB::table('analytics_refreshes')->insertGetId([
            'status' => 'RUNNING',
            'started_at' => $startedAt,
        ]);

        try {
            $loaded = DB::transaction(function (): array {
                $this->refreshDimensions();

                return [
                    'fact_content_metric' => $this->loadContentMetricFacts(),
                    'fact_creator_account' => $this->loadCreatorAccountFacts(),
                    'fact_mention' => $this->loadMentionFacts(),
                    'fact_shipment' => $this->loadShipmentFacts(),
                    'fact_seeding_content' => $this->loadSeedingContentFacts(),
                ];
            });

            foreach (self::ROLLUPS as $view) {
                DB::statement("REFRESH MATERIALIZED VIEW {$view}");
            }

            DB::table('analytics_refreshes')->where('id', $refreshId)->update([
                'status' => 'COMPLETED',
                'rollups_refreshed' => count(self::ROLLUPS),
                'facts_loaded' => json_encode($loaded),
                'finished_at' => now(),
            ]);

            return count(self::ROLLUPS);
        } catch (Throwable $e) {
            DB::table('analytics_refreshes')->where('id', $refreshId)->update([
                'status' => 'FAILED',
                // Message only — never payloads (privacy-safe logging).
                'error' => mb_substr($e->getMessage(), 0, 2000),
                'finished_at' => now(),
            ]);

            throw $e;
        }
    }

    private function refreshDimensions(): void
    {
        DB::statement(<<<'SQL'
            INSERT INTO dim_creator (creator_id, display_name, tenant_id, updated_at)
            SELECT id, display_name, tenant_id, now() FROM creators
            ON CONFLICT (creator_id) DO UPDATE
                SET display_name = excluded.display_name, tenant_id = excluded.tenant_id,
                    updated_at = excluded.updated_at
        SQL);

        DB::statement(<<<'SQL'
            INSERT INTO dim_client (client_id, name, tenant_id, updated_at)
            SELECT id, name, tenant_id, now() FROM clients
            ON CONFLICT (client_id) DO UPDATE
                SET name = excluded.name, tenant_id = excluded.tenant_id,
                    updated_at = excluded.updated_at
        SQL);

        DB::statement(<<<'SQL'
            INSERT INTO dim_brand (brand_id, client_id, name, sector, tenant_id, updated_at)
            SELECT id, client_id, name, sector, tenant_id, now() FROM brands
            ON CONFLICT (brand_id) DO UPDATE
                SET client_id = excluded.client_id, name = excluded.name,
                    sector = excluded.sector, tenant_id = excluded.tenant_id,
                    updated_at = excluded.updated_at
        SQL);

        DB::statement(<<<'SQL'
            INSERT INTO dim_campaign (campaign_id, brand_id, name, status, tenant_id, updated_at)
            SELECT id, brand_id, name, status, tenant_id, now() FROM campaigns
            ON CONFLICT (campaign_id) DO UPDATE
                SET brand_id = excluded.brand_id, name = excluded.name,
                    status = excluded.status, tenant_id = excluded.tenant_id,
                    updated_at = excluded.updated_at
        SQL);

        // DIM-Product / DIM-SeedingCampaign (P3) and DIM-Geo: loaders
        // activate once their source tables exist (DIM-Geo since ADR-0018's
        // operator-assigned geography; M2 adds automatic attribution in P2).
        if (Schema::hasTable('products')) {
            DB::statement(<<<'SQL'
                INSERT INTO dim_product (product_id, brand_id, name, sku, variant, tenant_id, updated_at)
                SELECT id, brand_id, name, sku, variant, tenant_id, now() FROM products
                ON CONFLICT (product_id) DO UPDATE
                    SET brand_id = excluded.brand_id, name = excluded.name,
                        sku = excluded.sku, variant = excluded.variant,
                        tenant_id = excluded.tenant_id, updated_at = excluded.updated_at
            SQL);
        }

        if (Schema::hasTable('seeding_campaigns')) {
            DB::statement(<<<'SQL'
                INSERT INTO dim_seeding_campaign (seeding_campaign_id, campaign_id, brand_id, name, seeding_type, status, tenant_id, updated_at)
                SELECT id, campaign_id, brand_id, name, seeding_type, status, tenant_id, now() FROM seeding_campaigns
                ON CONFLICT (seeding_campaign_id) DO UPDATE
                    SET campaign_id = excluded.campaign_id, brand_id = excluded.brand_id,
                        name = excluded.name, seeding_type = excluded.seeding_type,
                        status = excluded.status, tenant_id = excluded.tenant_id,
                        updated_at = excluded.updated_at
            SQL);
        }

        // DIM-Geo (REQ-M2-003): fed by ENT-GeoAttribution — operator-assigned
        // rows via the CreatorGeography seam today (ADR-0018), M2's automatic
        // inference later. The envelope's level/status ride along so geo
        // rollups stay confidence-aware.
        if (Schema::hasTable('geo_attributions')) {
            DB::statement(<<<'SQL'
                INSERT INTO dim_geo (geo_id, creator_id, country_code, region, city, confidence_level, verification_status, tenant_id, updated_at)
                SELECT id, creator_id, country_code, region, city,
                       assessment->>'confidenceLevel',
                       assessment->>'verificationStatus',
                       tenant_id,
                       now()
                FROM geo_attributions
                ON CONFLICT (geo_id) DO UPDATE
                    SET creator_id = excluded.creator_id,
                        country_code = excluded.country_code,
                        region = excluded.region,
                        city = excluded.city,
                        confidence_level = excluded.confidence_level,
                        verification_status = excluded.verification_status,
                        tenant_id = excluded.tenant_id,
                        updated_at = excluded.updated_at
            SQL);

            // A cleared assignment must stop slicing immediately: dims are
            // plain lookup tables (not append-only facts), so withdrawn
            // rows are removed rather than left to keep counting.
            DB::statement(<<<'SQL'
                DELETE FROM dim_geo
                WHERE geo_id NOT IN (SELECT id FROM geo_attributions)
            SQL);
        }
    }

    /** Register every distinct fact date in DIM-Date (derived calendar). */
    private function extendDateDimension(): void
    {
        DB::statement(<<<'SQL'
            INSERT INTO dim_date (date_key, week_start, month_start, quarter_start, year_start, iso_week, month, quarter, year)
            SELECT d,
                   date_trunc('week', d)::date,
                   date_trunc('month', d)::date,
                   date_trunc('quarter', d)::date,
                   date_trunc('year', d)::date,
                   extract(week FROM d)::int,
                   extract(month FROM d)::int,
                   extract(quarter FROM d)::int,
                   extract(year FROM d)::int
            FROM (
                SELECT date_key AS d FROM fact_content_metric
                UNION SELECT date_key FROM fact_creator_account
                UNION SELECT date_key FROM fact_mention
                UNION SELECT date_key FROM fact_shipment
                UNION SELECT date_key FROM fact_seeding_content
            ) dates
            ON CONFLICT (date_key) DO NOTHING
        SQL);
    }

    private function loadContentMetricFacts(): int
    {
        $watermark = $this->watermark('fact_content_metric');

        $result = DB::selectOne(<<<'SQL'
            WITH inserted AS (
            INSERT INTO fact_content_metric (
                date_key, metric_snapshot_id, content_item_id, platform_account_id,
                creator_id, brand_id, platform, content_type, published_at,
                captured_at, views, plays, likes, comments, shares, saves, tenant_id
            )
            SELECT ms.captured_at::date,
                   ms.id,
                   ci.id,
                   ci.platform_account_id,
                   pa.creator_id,
                   (
                       SELECT c.brand_id FROM mentions mn
                       JOIN campaigns c ON c.id = mn.campaign_id
                       WHERE mn.content_item_id = ci.id AND mn.campaign_id IS NOT NULL
                       ORDER BY mn.id
                       LIMIT 1
                   ),
                   ci.platform,
                   ci.content_type,
                   ci.published_at,
                   ms.captured_at,
                   qds_public_metric(ms.metrics, 'views'),
                   qds_public_metric(ms.metrics, 'plays'),
                   qds_public_metric(ms.metrics, 'likes'),
                   qds_public_metric(ms.metrics, 'comments'),
                   qds_public_metric(ms.metrics, 'shares'),
                   qds_public_metric(ms.metrics, 'saves'),
                   ms.tenant_id
            FROM metric_snapshots ms
            JOIN content_items ci ON ci.id = ms.content_item_id
            JOIN platform_accounts pa ON pa.id = ci.platform_account_id
            WHERE ms.content_item_id IS NOT NULL AND ms.id > ?
            ORDER BY ms.id
            RETURNING metric_snapshot_id
            )
            SELECT count(*) AS n, coalesce(max(metric_snapshot_id), 0) AS max_id FROM inserted
        SQL, [$watermark]);

        $this->advanceWatermark('fact_content_metric', (int) $result->max_id);
        $this->extendDateDimension();

        return (int) $result->n;
    }

    private function loadCreatorAccountFacts(): int
    {
        $watermark = $this->watermark('fact_creator_account');

        // follower_growth is the difference to the account's previous
        // snapshot (AC-M1-008: growth series from ordered own-DB snapshots).
        $result = DB::selectOne(<<<'SQL'
            WITH inserted AS (
            INSERT INTO fact_creator_account (
                date_key, metric_snapshot_id, platform_account_id, creator_id,
                platform, captured_at, followers, following, total_posts,
                follower_growth, tenant_id
            )
            SELECT ms.captured_at::date,
                   ms.id,
                   pa.id,
                   pa.creator_id,
                   pa.platform,
                   ms.captured_at,
                   qds_public_metric(ms.metrics, 'followers'),
                   qds_public_metric(ms.metrics, 'following'),
                   qds_public_metric(ms.metrics, 'posts'),
                   qds_public_metric(ms.metrics, 'followers') - prev.followers,
                   ms.tenant_id
            FROM metric_snapshots ms
            JOIN platform_accounts pa ON pa.id = ms.platform_account_id
            LEFT JOIN LATERAL (
                SELECT qds_public_metric(p.metrics, 'followers') AS followers
                FROM metric_snapshots p
                WHERE p.platform_account_id = ms.platform_account_id
                  AND (p.captured_at, p.id) < (ms.captured_at, ms.id)
                ORDER BY p.captured_at DESC, p.id DESC
                LIMIT 1
            ) prev ON true
            WHERE ms.platform_account_id IS NOT NULL AND ms.id > ?
            ORDER BY ms.id
            RETURNING metric_snapshot_id
            )
            SELECT count(*) AS n, coalesce(max(metric_snapshot_id), 0) AS max_id FROM inserted
        SQL, [$watermark]);

        $this->advanceWatermark('fact_creator_account', (int) $result->max_id);
        $this->extendDateDimension();

        return (int) $result->n;
    }

    private function loadMentionFacts(): int
    {
        // ENT-Mention mutates after its fact load: XMC-002 sets campaign_id
        // when content matching attributes the mention — ALWAYS later than
        // detection, so a stamp-once row would exclude the mention from
        // ROLLUP-MentionByCampaign forever (deep-review finding C2). New
        // mentions ride the id watermark; already-loaded mentions whose
        // updated_at moved past the restamp watermark (µs-epoch in the
        // shared last_id column, inclusive >= — second-precision timestamps,
        // same scheme as fact_shipment) are re-stamped by DELETE + fresh
        // INSERT through this table's own transaction-local gate.
        $watermark = $this->watermark('fact_mention');
        $restampWatermark = $this->watermark('fact_mention_restamp');

        DB::statement("SELECT set_config('qds.analytics_mention_restamp', 'on', true)");
        DB::delete(<<<'SQL'
            DELETE FROM fact_mention f
            USING mentions mn
            WHERE mn.id = f.mention_id
              AND mn.id <= ?
              AND (extract(epoch FROM mn.updated_at) * 1000000)::bigint >= ?
        SQL, [$watermark, $restampWatermark]);
        DB::statement("SELECT set_config('qds.analytics_mention_restamp', 'off', true)");

        $result = DB::selectOne(<<<'SQL'
            WITH inserted AS (
            INSERT INTO fact_mention (
                date_key, mention_id, monitored_subject_id, content_item_id, story_id,
                creator_id, brand_id, campaign_id, platform, content_type, mention_type,
                sentiment, detected_at, views, likes, comments, shares, saves,
                estimated_reach, estimated_reach_tier, emv, emv_tier, emv_currency,
                tenant_id
            )
            SELECT mn.created_at::date,
                   mn.id,
                   mn.monitored_subject_id,
                   mn.content_item_id,
                   mn.story_id,
                   coalesce(msj.creator_id, pa.creator_id),
                   cam.brand_id,
                   mn.campaign_id,
                   coalesce(ci.platform, st.platform),
                   ci.content_type,
                   mn.mention_type,
                   (
                       SELECT sa.label FROM sentiment_analyses sa
                       WHERE sa.content_item_id = mn.content_item_id
                       ORDER BY sa.id DESC
                       LIMIT 1
                   ),
                   mn.created_at,
                   coalesce(snap.views, qds_public_metric(st.public_metrics, 'views')),
                   snap.likes,
                   snap.comments,
                   snap.shares,
                   snap.saves,
                   reach.amount, reach.tier,
                   emv.amount,
                   emv.tier,
                   emv.currency,
                   mn.tenant_id
            FROM mentions mn
            JOIN monitored_subjects msj ON msj.id = mn.monitored_subject_id
            LEFT JOIN content_items ci ON ci.id = mn.content_item_id
            LEFT JOIN stories st ON st.id = mn.story_id
            LEFT JOIN platform_accounts pa
                ON pa.id = coalesce(ci.platform_account_id, st.platform_account_id)
            LEFT JOIN campaigns cam ON cam.id = mn.campaign_id
            LEFT JOIN LATERAL (
                SELECT qds_public_metric(ms.metrics, 'views') AS views,
                       qds_public_metric(ms.metrics, 'likes') AS likes,
                       qds_public_metric(ms.metrics, 'comments') AS comments,
                       qds_public_metric(ms.metrics, 'shares') AS shares,
                       qds_public_metric(ms.metrics, 'saves') AS saves
                FROM metric_snapshots ms
                WHERE ms.content_item_id = mn.content_item_id
                ORDER BY ms.captured_at DESC, ms.id DESC
                LIMIT 1
            ) snap ON true
            LEFT JOIN LATERAL (
                SELECT (er.value->>'amount')::numeric AS amount,
                       er.value->>'tier' AS tier,
                       er.currency
                FROM emv_results er
                WHERE er.content_item_id = mn.content_item_id
                ORDER BY er.calculated_at DESC, er.id DESC
                LIMIT 1
            ) emv ON true
            LEFT JOIN LATERAL (
                SELECT (rr.value->>'amount')::numeric AS amount,
                       rr.value->>'tier' AS tier
                FROM reach_results rr
                WHERE rr.content_item_id = mn.content_item_id
                ORDER BY rr.calculated_at DESC, rr.id DESC
                LIMIT 1
            ) reach ON true
            WHERE mn.id > ?
               OR (mn.id <= ?
                   AND (extract(epoch FROM mn.updated_at) * 1000000)::bigint >= ?)
            ORDER BY mn.id
            RETURNING mention_id
            )
            SELECT count(*) AS n,
                   coalesce(max(mention_id), 0) AS max_id,
                   coalesce((
                       SELECT max((extract(epoch FROM mn2.updated_at) * 1000000)::bigint)
                       FROM mentions mn2
                       JOIN inserted i ON i.mention_id = mn2.id
                   ), 0) AS max_updated
            FROM inserted
        SQL, [$watermark, $watermark, $restampWatermark]);

        $this->advanceWatermark('fact_mention', max($watermark, (int) $result->max_id));
        $this->advanceWatermark('fact_mention_restamp', max($restampWatermark, (int) $result->max_updated));
        $this->extendDateDimension();

        return (int) $result->n;
    }

    private function loadShipmentFacts(): int
    {
        // Dispatched shipments only (shipped_at NOT NULL); date_key is the
        // dispatch date (canon DIM-Date(shippedAt)). ENT-Shipment MUTATES —
        // `posted` flips when content matching links a reel (analytics-model
        // §6 step 2) — so an id watermark would freeze rows: the watermark
        // is the µs-epoch of shipments.updated_at instead (stored in the
        // shared last_id column), compared inclusively (>=) because Laravel
        // timestamps have second precision and a strict comparison would
        // drop a row updated within the watermark's own second. Re-scanned
        // rows are harmless: mutated shipments are re-stamped by DELETE +
        // fresh INSERT (idempotent), the delete passing the transaction-
        // local gate that is this table's single sanctioned append-only
        // exception (spec D2 — see the class header and the
        // allow_gated_fact_shipment_restamp migration).
        $watermark = $this->watermark('fact_shipment');

        DB::statement("SELECT set_config('qds.analytics_shipment_restamp', 'on', true)");
        DB::delete(<<<'SQL'
            DELETE FROM fact_shipment f
            USING shipments s
            WHERE s.id = f.shipment_id
              AND (extract(epoch FROM s.updated_at) * 1000000)::bigint >= ?
        SQL, [$watermark]);
        DB::statement("SELECT set_config('qds.analytics_shipment_restamp', 'off', true)");

        $result = DB::selectOne(<<<'SQL'
            WITH inserted AS (
            INSERT INTO fact_shipment (
                date_key, shipment_id, creator_id, product_id, brand_id,
                client_id, seeding_campaign_id, campaign_id, shipped, posted,
                quantity, product_value, days_to_post, tenant_id
            )
            SELECT s.shipped_at::date,
                   s.id,
                   s.creator_id,
                   s.product_id,
                   p.brand_id,
                   b.client_id,
                   s.seeding_campaign_id,
                   sc.campaign_id,
                   1,
                   CASE WHEN coalesce(s.posted, false) THEN 1 ELSE 0 END,
                   s.quantity,
                   (s.product_value_at_ship->>'amount')::numeric,
                   -- Deep-review GAP-4: postedAt can PREDATE shippedAt (an
                   -- operator manually links pre-existing content); a
                   -- negative shipped→post interval is unmeasurable, so it
                   -- loads NULL — never a negative that poisons averages.
                   CASE WHEN s.posted_at >= s.shipped_at
                        THEN extract(epoch FROM (s.posted_at - s.shipped_at)) / 86400.0
                   END,
                   s.tenant_id
            FROM shipments s
            JOIN seeding_campaigns sc ON sc.id = s.seeding_campaign_id
            JOIN products p ON p.id = s.product_id
            JOIN brands b ON b.id = p.brand_id
            WHERE s.shipped_at IS NOT NULL
              AND (extract(epoch FROM s.updated_at) * 1000000)::bigint >= ?
            ORDER BY s.id
            RETURNING shipment_id
            )
            SELECT count(*) AS n,
                   coalesce((
                       SELECT max((extract(epoch FROM s2.updated_at) * 1000000)::bigint)
                       FROM shipments s2
                       JOIN inserted i ON i.shipment_id = s2.id
                   ), 0) AS max_id
            FROM inserted
        SQL, [$watermark]);

        $this->advanceWatermark('fact_shipment', max($watermark, (int) $result->max_id));
        $this->extendDateDimension();

        return (int) $result->n;
    }

    private function loadSeedingContentFacts(): int
    {
        // Grain: shipment × resulting content × metric-snapshot bucket.
        // date_key anchors to the content's posting date (canon
        // DIM-Date(postedAt)) so a run's results stay in the period the
        // content was posted while every new snapshot ADDS a row (the
        // rollups reduce to the latest per shipment × content) — falling
        // back to the shipment's postedAt, then the capture date, when the
        // content carries no publish time. Two high-water marks: new
        // snapshots for already-linked content, and new shipment↔content
        // links whose content already has snapshots (REQ-M3-008 matching
        // can link late). estimated_reach stamps the content's latest
        // reach_results value (ESTIMATED tier, ADR-0022) via a LATERAL join
        // mirroring EMV; it is NULL with a NULL tier only when no reach
        // result exists yet (DEF-003 unavailable), never zero. A re-link
        // after an unlink re-scans old snapshots; ON
        // CONFLICT DO NOTHING skips the fact rows that already exist
        // (conflict-skip — facts never mutate, spec D2).
        $snapshotWatermark = $this->watermark('fact_seeding_content');
        $linkWatermark = $this->watermark('fact_seeding_content_link');

        $result = DB::selectOne(<<<'SQL'
            WITH inserted AS (
            INSERT INTO fact_seeding_content (
                date_key, shipment_id, content_item_id, metric_snapshot_id,
                creator_id, product_id, brand_id, client_id, seeding_campaign_id,
                campaign_id, platform, content_type, views, likes, comments,
                shares, saves, estimated_reach, estimated_reach_tier, emv, emv_tier,
                tenant_id
            )
            SELECT coalesce(ci.published_at::date, sh.posted_at::date, ms.captured_at::date),
                   sh.id,
                   ci.id,
                   ms.id,
                   sh.creator_id,
                   sh.product_id,
                   p.brand_id,
                   b.client_id,
                   sh.seeding_campaign_id,
                   sc.campaign_id,
                   ci.platform,
                   ci.content_type,
                   qds_public_metric(ms.metrics, 'views'),
                   qds_public_metric(ms.metrics, 'likes'),
                   qds_public_metric(ms.metrics, 'comments'),
                   qds_public_metric(ms.metrics, 'shares'),
                   qds_public_metric(ms.metrics, 'saves'),
                   reach.amount, reach.tier,
                   emv.amount,
                   emv.tier,
                   sh.tenant_id
            FROM shipment_resulting_content src
            JOIN shipments sh ON sh.id = src.shipment_id
            JOIN seeding_campaigns sc ON sc.id = sh.seeding_campaign_id
            JOIN products p ON p.id = sh.product_id
            JOIN brands b ON b.id = p.brand_id
            JOIN content_items ci ON ci.id = src.content_item_id
            JOIN metric_snapshots ms ON ms.content_item_id = ci.id
            LEFT JOIN LATERAL (
                SELECT (er.value->>'amount')::numeric AS amount,
                       er.value->>'tier' AS tier
                FROM emv_results er
                WHERE er.content_item_id = ci.id
                ORDER BY er.calculated_at DESC, er.id DESC
                LIMIT 1
            ) emv ON true
            LEFT JOIN LATERAL (
                SELECT (rr.value->>'amount')::numeric AS amount,
                       rr.value->>'tier' AS tier
                FROM reach_results rr
                WHERE rr.content_item_id = ci.id
                ORDER BY rr.calculated_at DESC, rr.id DESC
                LIMIT 1
            ) reach ON true
            WHERE ms.id > ? OR src.id > ?
            ORDER BY ms.id, src.id
            ON CONFLICT DO NOTHING
            RETURNING shipment_id, content_item_id, metric_snapshot_id
            )
            SELECT (SELECT count(*) FROM inserted) AS n,
                   (SELECT coalesce(max(metric_snapshot_id), 0) FROM inserted) AS max_snapshot_id,
                   (SELECT coalesce(max(src.id), 0)
                    FROM shipment_resulting_content src
                    JOIN inserted i ON i.shipment_id = src.shipment_id
                                   AND i.content_item_id = src.content_item_id) AS max_link_id
        SQL, [$snapshotWatermark, $linkWatermark]);

        // max() guards against regression: a late link inserts rows for OLD
        // snapshot ids (and vice versa), which must never pull a watermark
        // backwards past sources already processed.
        $this->advanceWatermark('fact_seeding_content', max($snapshotWatermark, (int) $result->max_snapshot_id));
        $this->advanceWatermark('fact_seeding_content_link', max($linkWatermark, (int) $result->max_link_id));
        $this->extendDateDimension();

        return (int) $result->n;
    }

    private function watermark(string $source): int
    {
        return (int) DB::table('analytics_watermarks')
            ->where('source', $source)
            ->value('last_id');
    }

    private function advanceWatermark(string $source, int $maxProcessedId): void
    {
        if ($maxProcessedId <= 0) {
            return;
        }

        DB::table('analytics_watermarks')->upsert([
            'source' => $source,
            'last_id' => $maxProcessedId,
            'updated_at' => now(),
        ], ['source'], ['last_id', 'updated_at']);
    }
}
