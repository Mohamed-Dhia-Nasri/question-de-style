<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Narrow, gated exception to the append-only trigger (Step-4 spec D2 —
     * FLAGGED deviation, reviewer to confirm).
     *
     * ENT-Shipment is the one MUTABLE fact source in the star schema: the
     * canonical flow (analytics-model §6 step 2) requires that when content
     * matching links a reel, "FACT-Shipment.posted flips; days_to_post
     * computed". FACT-Shipment's UNIQUE (shipment_id, date_key) plus the
     * canonical rollups' one-row-per-shipment shape leave no append-only
     * way to express the flip as an accumulated row — so the shipment
     * loader re-stamps a mutated shipment by DELETE + fresh INSERT inside
     * the refresh transaction (NeonAnalyticsService::loadShipmentFacts()).
     *
     * ENT-Mention mutates the same way (deep-review finding C2):
     * mentions.campaign_id is set by XMC-002 AFTER the mention is created —
     * always later than its fact load — so a stamp-once fact_mention row
     * permanently excludes the mention from ROLLUP-MentionByCampaign and
     * the campaign panel systematically undercounts. fact_mention therefore
     * gets the SAME gated restamp, behind its own setting.
     *
     * The gates stay as tight as possible:
     *  - DELETE only, and only on the named table (and its partitions —
     *    cloned row triggers fire with the partition's TG_TABLE_NAME);
     *  - each table only while ITS OWN transaction-local setting is 'on'
     *    (`qds.analytics_shipment_restamp` / `qds.analytics_mention_restamp`,
     *    set/cleared by the respective loader around its purge statement;
     *    unset everywhere else) — one gate never loosens the other table;
     *  - every other operation on every fact table still raises, exactly
     *    as before (ADR-0010 — history is accumulation, not mutation).
     */
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION qds_analytics_append_only() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE'
                    AND TG_TABLE_NAME LIKE 'fact_shipment%'
                    AND current_setting('qds.analytics_shipment_restamp', true) = 'on' THEN
                    RETURN OLD;
                END IF;

                IF TG_OP = 'DELETE'
                    AND TG_TABLE_NAME LIKE 'fact_mention%'
                    AND current_setting('qds.analytics_mention_restamp', true) = 'on' THEN
                    RETURN OLD;
                END IF;

                RAISE EXCEPTION 'analytics facts are append-only: % on % is not permitted (ADR-0010)', TG_OP, TG_TABLE_NAME;
            END;
            $$ LANGUAGE plpgsql;
        SQL);
    }

    public function down(): void
    {
        // Restore the original unconditional guard (P0 facts migration).
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION qds_analytics_append_only() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'analytics facts are append-only: % on % is not permitted (ADR-0010)', TG_OP, TG_TABLE_NAME;
            END;
            $$ LANGUAGE plpgsql;
        SQL);
    }
};
