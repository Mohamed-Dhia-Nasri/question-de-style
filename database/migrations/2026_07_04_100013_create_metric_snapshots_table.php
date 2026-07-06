<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ENT-MetricSnapshot (docs/30-data-model/00-data-model.md#ent-metricsnapshot).
     * Write-owner: Module 1 Monitoring; physically written by
     * SVC-SnapshotScheduler on a recurring schedule (ownership matrix).
     * The sole substrate for historical growth — there is no external
     * history API (ADR-0003, REQ-M1-007).
     *
     * Account-level snapshots set platform_account_id; content-level
     * snapshots set content_item_id — exactly one (target CHECK).
     * Externally sourced values → mandatory Provenance (DP-002); each
     * element of `metrics` is a MetricValue carrying its own ENUM-MetricTier
     * (DP-001).
     *
     * APPEND-ONLY: history is the accumulation of snapshots, never mutation.
     * Enforced at the database level by the trigger below (UPDATE/DELETE
     * raise) and at the model level. No updated_at column by design.
     *
     * Engine note (ADR-0013 amending ADR-0010): the database is Neon
     * Postgres — the TimescaleDB extension is NOT available and hypertables
     * are NOT used. Time-series access is served by the composite
     * (target, captured_at) indexes; native declarative partitioning is
     * reserved for the FACT-* analytics layer (SVC-Analytics), not this
     * OLTP table.
     */
    public function up(): void
    {
        Schema::create('metric_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('platform_account_id')->nullable()->index()->constrained();
            $table->foreignId('content_item_id')->nullable()->index()->constrained();
            $table->timestamp('captured_at')->index();
            $table->jsonb('metrics');
            $table->jsonb('provenance');
            $table->timestamp('created_at');

            $table->index(['platform_account_id', 'captured_at']);
            $table->index(['content_item_id', 'captured_at']);
        });

        // Exactly one snapshot target: account-level XOR content-level.
        DB::statement(<<<'SQL'
            ALTER TABLE metric_snapshots ADD CONSTRAINT metric_snapshots_target_check
                CHECK (num_nonnulls(platform_account_id, content_item_id) = 1)
        SQL);

        // Append-only enforcement: snapshots are immutable history.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION qds_metric_snapshots_append_only() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'metric_snapshots is append-only: % is not permitted (ADR-0003)', TG_OP;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER metric_snapshots_append_only
                BEFORE UPDATE OR DELETE ON metric_snapshots
                FOR EACH ROW EXECUTE FUNCTION qds_metric_snapshots_append_only();
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('metric_snapshots');
        DB::unprepared('DROP FUNCTION IF EXISTS qds_metric_snapshots_append_only()');
    }
};
