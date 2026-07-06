<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Configurable EMV (REQ-M1-011, MET-EMV, GL-EMV).
     *
     * The canonical formula structure is fixed by the metrics catalog —
     * MET-EMV: "Σ (metric_i × rate_i) over content using a configurable,
     * transparent rate card". These tables hold the configuration (which
     * metrics participate, the rate card, currency, versions) and the
     * calculated results with full disclosure (AC-M1-011: every surface
     * shows the model, the rates, and the tier of every input).
     *
     * FLAGGED DEVIATION: the data model defines no EMV configuration or
     * result entity — the rate-card/versioning schema is an undocumented
     * persisted shape awaiting a doc amendment (same class as the ingestion
     * operational tables).
     *
     * Rules encoded here:
     * - at most ONE configuration is ACTIVE at a time (partial unique index);
     * - results are APPEND-ONLY and reference the exact configuration,
     *   formula version, rate-card version, inputs, and tiers used, so a
     *   configuration change never alters previously calculated values.
     */
    public function up(): void
    {
        Schema::create('emv_configurations', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('formula_version', 64);
            $table->string('rate_card_version', 64);
            $table->char('currency', 3);
            $table->jsonb('formula');
            $table->jsonb('rates');
            $table->date('effective_from');
            $table->string('status', 10)->default('DRAFT')->index();
            $table->text('notes')->nullable();
            $table->jsonb('assumptions')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('activated_at')->nullable();
            $table->foreignId('activated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['formula_version', 'rate_card_version']);
        });

        // Internal operational vocabulary — not a canonical ENUM-*.
        DB::statement(<<<'SQL'
            ALTER TABLE emv_configurations ADD CONSTRAINT emv_configurations_status_check
                CHECK (status IN ('DRAFT','ACTIVE','INACTIVE','ARCHIVED'))
        SQL);

        // EMV is unavailable until an authorized user activates a valid
        // configuration; at most one may be active.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX emv_configurations_one_active_index
                ON emv_configurations ((1)) WHERE status = 'ACTIVE'
        SQL);

        Schema::create('emv_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('content_item_id')->index()->constrained();
            $table->foreignId('emv_configuration_id')->index()->constrained();
            $table->string('formula_version', 64);
            $table->string('rate_card_version', 64);
            $table->char('currency', 3);
            // MET-EMV is a "modeled monetary estimate — treated as ESTIMATED"
            // (metrics catalog); the value is stored as a MetricValue
            // envelope so the tier travels with the number (DP-001).
            $table->jsonb('value');
            $table->jsonb('inputs');
            $table->jsonb('assumptions')->nullable();
            $table->timestamp('calculated_at');
            $table->timestamp('created_at');

            $table->index(['content_item_id', 'calculated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('emv_results');
        Schema::dropIfExists('emv_configurations');
    }
};
