<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * GDPR data-subject erasure gate (P4 hardening, DP-005).
 *
 * DP-005 makes data-subject deletion a MUST, but two append-only guards
 * (correctly) block it: the metric_snapshots trigger (ADR-0003) and the
 * analytics fact triggers (ADR-0010). Erasing a creator therefore gets the
 * same narrow, transaction-local gate the fact restamp loaders already use
 * (allow_gated_fact_shipment_restamp precedent):
 *
 *  - DELETE only — UPDATE still always raises;
 *  - only while the transaction-local setting `qds.gdpr_erasure` is 'on',
 *    which ONLY CreatorEraser sets, inside its single erasure transaction;
 *  - everywhere else both triggers behave exactly as before: history is
 *    accumulation, not mutation.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION qds_metric_snapshots_append_only() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE'
                    AND current_setting('qds.gdpr_erasure', true) = 'on' THEN
                    RETURN OLD;
                END IF;

                RAISE EXCEPTION 'metric_snapshots is append-only: % is not permitted (ADR-0003)', TG_OP;
            END;
            $$ LANGUAGE plpgsql;
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION qds_analytics_append_only() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE'
                    AND current_setting('qds.gdpr_erasure', true) = 'on' THEN
                    RETURN OLD;
                END IF;

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
        // Restore the pre-GDPR guards: original snapshots trigger and the
        // restamp-only analytics trigger.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION qds_metric_snapshots_append_only() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'metric_snapshots is append-only: % is not permitted (ADR-0003)', TG_OP;
            END;
            $$ LANGUAGE plpgsql;
        SQL);

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
};
