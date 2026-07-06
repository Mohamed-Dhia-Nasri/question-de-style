<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Hardening pass over the enrichment write paths (deep-review findings):
     *
     *  - Partial unique indexes behind the mention / recognition upserts so
     *    two concurrent enrichment passes can never insert duplicate rows
     *    (the ShouldBeUnique jobs prevent the common case; this is the DB
     *    backstop the services catch on).
     *  - `provider_label` on recognition_detections: the IMMUTABLE raw
     *    provider-detected label, so a human correction of `detected_brand`
     *    is never re-detected as a fresh AI row (DP-004). FLAGGED DEVIATION:
     *    not in the canonical ENT-RecognitionDetection shape.
     *  - Append-only DB triggers on review_actions and emv_results mirroring
     *    the metric_snapshots trigger, so the immutability the docs promise
     *    (DP-004 correction history "never rewritten"; AC-M1-011 EMV
     *    reproducibility) holds even against query-builder / bulk writes that
     *    bypass Eloquent model events.
     */
    public function up(): void
    {
        Schema::table('recognition_detections', function (Blueprint $table): void {
            $table->string('provider_label')->nullable()->after('detected_brand');
        });

        // Backfill: existing AI rows keyed on detected_brand adopt it as the
        // provider label (no human corrections exist yet at this migration).
        DB::statement('UPDATE recognition_detections SET provider_label = detected_brand WHERE provider_label IS NULL');

        // One mention per (subject, content) and per (subject, story).
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX mentions_subject_content_unique
                ON mentions (monitored_subject_id, content_item_id)
                WHERE content_item_id IS NOT NULL
        SQL);
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX mentions_subject_story_unique
                ON mentions (monitored_subject_id, story_id)
                WHERE story_id IS NOT NULL
        SQL);

        // One recognition per (target, type, raw provider label).
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX recognition_detections_content_identity_unique
                ON recognition_detections (content_item_id, recognition_type, provider_label)
                WHERE content_item_id IS NOT NULL
        SQL);
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX recognition_detections_story_identity_unique
                ON recognition_detections (story_id, recognition_type, provider_label)
                WHERE story_id IS NOT NULL
        SQL);

        // Append-only enforcement at the DB layer (independent of Eloquent).
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION qds_enrichment_append_only() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION '% is append-only: % is not permitted (DP-004 / AC-M1-011)', TG_TABLE_NAME, TG_OP;
            END;
            $$ LANGUAGE plpgsql;
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER review_actions_append_only
                BEFORE UPDATE OR DELETE ON review_actions
                FOR EACH ROW EXECUTE FUNCTION qds_enrichment_append_only();
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER emv_results_append_only
                BEFORE UPDATE OR DELETE ON emv_results
                FOR EACH ROW EXECUTE FUNCTION qds_enrichment_append_only();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS emv_results_append_only ON emv_results');
        DB::unprepared('DROP TRIGGER IF EXISTS review_actions_append_only ON review_actions');
        DB::unprepared('DROP FUNCTION IF EXISTS qds_enrichment_append_only()');

        DB::statement('DROP INDEX IF EXISTS recognition_detections_story_identity_unique');
        DB::statement('DROP INDEX IF EXISTS recognition_detections_content_identity_unique');
        DB::statement('DROP INDEX IF EXISTS mentions_subject_story_unique');
        DB::statement('DROP INDEX IF EXISTS mentions_subject_content_unique');

        Schema::table('recognition_detections', function (Blueprint $table): void {
            $table->dropColumn('provider_label');
        });
    }
};
