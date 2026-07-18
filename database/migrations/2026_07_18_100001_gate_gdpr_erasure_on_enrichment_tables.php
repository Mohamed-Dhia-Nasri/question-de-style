<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * GDPR data-subject erasure gate for the ENRICHMENT append-only tables
 * (P4 hardening follow-up, DP-005).
 *
 * allow_gated_gdpr_erasure (2026_07_08) opened the DELETE gate on the
 * metric_snapshots and analytics-fact append-only triggers so CreatorEraser
 * could purge a data subject's history — but it missed the THIRD append-only
 * function, qds_enrichment_append_only(), which guards review_actions and
 * emv_results (harden_enrichment_tables, 2026_07_05). CreatorEraser deletes
 * from both of those tables, so any creator carrying an EMV figure or a human
 * review correction hit an unconditional RAISE EXCEPTION and the whole erasure
 * transaction rolled back — GDPR deletion was impossible for them.
 *
 * This applies the SAME narrow, transaction-local gate the other two guards
 * already use:
 *  - DELETE only — UPDATE still always raises (corrections/EMV stay immutable);
 *  - only while the transaction-local `qds.gdpr_erasure` setting is 'on',
 *    which ONLY CreatorEraser sets, inside its single erasure transaction.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION qds_enrichment_append_only() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE'
                    AND current_setting('qds.gdpr_erasure', true) = 'on' THEN
                    RETURN OLD;
                END IF;

                RAISE EXCEPTION '% is append-only: % is not permitted (DP-004 / AC-M1-011)', TG_TABLE_NAME, TG_OP;
            END;
            $$ LANGUAGE plpgsql;
        SQL);
    }

    public function down(): void
    {
        // Restore the original unconditional guard (harden_enrichment_tables).
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION qds_enrichment_append_only() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION '% is append-only: % is not permitted (DP-004 / AC-M1-011)', TG_TABLE_NAME, TG_OP;
            END;
            $$ LANGUAGE plpgsql;
        SQL);
    }
};
