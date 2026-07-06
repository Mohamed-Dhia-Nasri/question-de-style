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
 * Loaders exist for the Module-1 sources that exist today:
 *  - FACT-ContentMetric   ← content-level ENT-MetricSnapshot
 *  - FACT-CreatorAccount  ← account-level ENT-MetricSnapshot
 *  - FACT-Mention         ← ENT-Mention, stamped with the linked content's
 *                           latest metrics, sentiment, and EMV
 * FACT-Shipment / FACT-SeedingContent load in P3 when ENT-Shipment /
 * ENT-Product ship; DIM-Product / DIM-SeedingCampaign / DIM-Geo likewise
 * (P3 / P2). Their structures are P0-complete and stay empty — consuming
 * surfaces render "Unavailable", never zero.
 *
 * Personal data: only internal ids and the public persona display name
 * enter the star schema — never email/phone/address/notes (DP-005).
 */
class NeonAnalyticsService implements AnalyticsService
{
    /** Rollup catalog — canonical in docs/30-data-model/01-analytics-model.md §5. */
    public const ROLLUPS = [
        'rollup_seeding_by_shipment',
        'rollup_seeding_by_creator_campaign',
        'rollup_seeding_by_product',
        'rollup_seeding_by_brand',
        'rollup_mention_by_brand',
        'rollup_metric_by_geo',
        'rollup_creator_by_period',
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
            INSERT INTO dim_creator (creator_id, display_name, updated_at)
            SELECT id, display_name, now() FROM creators
            ON CONFLICT (creator_id) DO UPDATE
                SET display_name = excluded.display_name, updated_at = excluded.updated_at
        SQL);

        DB::statement(<<<'SQL'
            INSERT INTO dim_client (client_id, name, updated_at)
            SELECT id, name, now() FROM clients
            ON CONFLICT (client_id) DO UPDATE
                SET name = excluded.name, updated_at = excluded.updated_at
        SQL);

        DB::statement(<<<'SQL'
            INSERT INTO dim_brand (brand_id, client_id, name, sector, updated_at)
            SELECT id, client_id, name, sector, now() FROM brands
            ON CONFLICT (brand_id) DO UPDATE
                SET client_id = excluded.client_id, name = excluded.name,
                    sector = excluded.sector, updated_at = excluded.updated_at
        SQL);

        DB::statement(<<<'SQL'
            INSERT INTO dim_campaign (campaign_id, brand_id, name, status, updated_at)
            SELECT id, brand_id, name, status, now() FROM campaigns
            ON CONFLICT (campaign_id) DO UPDATE
                SET brand_id = excluded.brand_id, name = excluded.name,
                    status = excluded.status, updated_at = excluded.updated_at
        SQL);

        // DIM-Product / DIM-SeedingCampaign (P3) and DIM-Geo (P2): source
        // entities do not exist yet — loaders activate with their phase.
        if (Schema::hasTable('products')) {
            DB::statement(<<<'SQL'
                INSERT INTO dim_product (product_id, brand_id, name, sku, variant, updated_at)
                SELECT id, brand_id, name, sku, variant, now() FROM products
                ON CONFLICT (product_id) DO UPDATE
                    SET brand_id = excluded.brand_id, name = excluded.name,
                        sku = excluded.sku, variant = excluded.variant,
                        updated_at = excluded.updated_at
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
                captured_at, views, plays, likes, comments, shares, saves
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
                   qds_public_metric(ms.metrics, 'saves')
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
                platform, captured_at, followers, following, total_posts, follower_growth
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
                   qds_public_metric(ms.metrics, 'followers') - prev.followers
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
        $watermark = $this->watermark('fact_mention');

        $result = DB::selectOne(<<<'SQL'
            WITH inserted AS (
            INSERT INTO fact_mention (
                date_key, mention_id, monitored_subject_id, content_item_id, story_id,
                creator_id, brand_id, campaign_id, platform, content_type, mention_type,
                sentiment, detected_at, views, likes, comments, shares, saves,
                estimated_reach, estimated_reach_tier, emv, emv_tier, emv_currency
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
                   NULL, NULL,
                   emv.amount,
                   emv.tier,
                   emv.currency
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
            WHERE mn.id > ?
            ORDER BY mn.id
            RETURNING mention_id
            )
            SELECT count(*) AS n, coalesce(max(mention_id), 0) AS max_id FROM inserted
        SQL, [$watermark]);

        $this->advanceWatermark('fact_mention', (int) $result->max_id);
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
