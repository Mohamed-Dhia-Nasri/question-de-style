<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sub-project D audit trail (spec §8.1/§8.2): one vlm_verification_runs
     * row per verification attempt-set (append-only; single-row lifecycle
     * pending → terminal, never re-opened) plus per-candidate
     * vlm_candidate_verdicts — sub-project E's "Gemini agreement" input.
     *
     * Consumption bookkeeping lives in the DB: the partial unique
     * (visual_match_run_id, model_version) means one verification per
     * anchor per VLM model — a model_version bump re-opens old anchors,
     * catalog changes ride new C runs (new anchor ids). DEF-021 discovery
     * rows ('unverifiable', anchor NULL from birth, never sent to Gemini)
     * get their own per-owner dedup so the daily sweep can never duplicate
     * them. Tenant-owned (ADR-0019/0020) with composite (col, tenant_id)
     * FKs per the visual_match_* pattern. Erased with the creator
     * (CreatorEraser); verdicts cascade from runs at the DB.
     */
    public function up(): void
    {
        Schema::create('vlm_verification_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->index()->constrained();
            $table->foreignId('content_item_id')->nullable()->constrained();
            $table->foreignId('story_id')->nullable()->constrained();
            // The anchor C run this verification consumes. Nullable on
            // purpose, no plain FK: the composite FK below nulls ONLY this
            // column when the anchor is deleted — the audit row survives.
            // NULL from birth identifies a DEF-021 discovery row.
            $table->unsignedBigInteger('visual_match_run_id')->nullable()->index();
            $table->string('correlation_id', 64);
            $table->string('model_version', 64);
            $table->string('trigger_reason', 40);
            $table->string('priority', 10);
            $table->smallInteger('frames_sent');
            // usageMetadata token counts; null until a response arrived.
            $table->integer('prompt_tokens')->nullable();
            $table->integer('output_tokens')->nullable();
            $table->integer('thinking_tokens')->nullable();
            // Billed calls — the crash-safe ledger (spec §10): incremented
            // and committed BEFORE each provider call, so a worker crash
            // can never forget a billed attempt.
            $table->smallInteger('attempts')->default(0);
            $table->string('outcome', 30);
            $table->string('rejection_reason', 100)->nullable();
            // Snapshot {auto, review, margin} — reproducibility across
            // threshold recalibrations (sub-project E).
            $table->jsonb('thresholds');
            // Wall-clock across attempts.
            $table->integer('latency_ms');
            // attempts × price constant (governance estimate, not billing truth).
            $table->integer('estimated_cost_micro_usd');
            $table->timestamps();

            // Latest-run-per-post lookups (authoritative row = max id).
            $table->index(['content_item_id', 'id']);
            $table->index(['story_id', 'id']);
        });

        DB::statement('ALTER TABLE vlm_verification_runs ADD CONSTRAINT vlm_verification_runs_id_tenant_unique UNIQUE (id, tenant_id)');
        // Exactly one target: content item XOR story (visual_match_runs precedent).
        DB::statement('ALTER TABLE vlm_verification_runs ADD CONSTRAINT vlm_verification_runs_target_check CHECK (num_nonnulls(content_item_id, story_id) = 1)');
        DB::statement("ALTER TABLE vlm_verification_runs ADD CONSTRAINT vlm_verification_runs_priority_check CHECK (priority IN ('high', 'medium'))");
        DB::statement(<<<'SQL'
            ALTER TABLE vlm_verification_runs ADD CONSTRAINT vlm_verification_runs_outcome_check
                CHECK (outcome IN (
                    'pending', 'confirmed', 'absent', 'inconclusive', 'unverifiable',
                    'failed_malformed', 'skipped_provider', 'skipped_safety_block',
                    'skipped_payload_guard', 'skipped_no_frames'
                ))
        SQL);
        DB::statement(<<<'SQL'
            ALTER TABLE vlm_verification_runs ADD CONSTRAINT vlm_verification_runs_trigger_reason_check
                CHECK (trigger_reason IN (
                    'review-band', 'no-band-shipment', 'sweep-catchup',
                    'unverifiable:no-run', 'unverifiable:skipped-run'
                ))
        SQL);
        DB::statement('ALTER TABLE vlm_verification_runs ADD CONSTRAINT vlm_verification_runs_content_item_tenant_fk FOREIGN KEY (content_item_id, tenant_id) REFERENCES content_items (id, tenant_id)');
        DB::statement('ALTER TABLE vlm_verification_runs ADD CONSTRAINT vlm_verification_runs_story_tenant_fk FOREIGN KEY (story_id, tenant_id) REFERENCES stories (id, tenant_id)');
        // Anchor delete nulls ONLY visual_match_run_id (PostgreSQL 15+
        // column-scoped SET NULL — pg17 everywhere): the verification audit
        // row survives; MATCH SIMPLE skips the FK once the column is null.
        DB::statement('ALTER TABLE vlm_verification_runs ADD CONSTRAINT vlm_verification_runs_visual_match_run_tenant_fk FOREIGN KEY (visual_match_run_id, tenant_id) REFERENCES visual_match_runs (id, tenant_id) ON DELETE SET NULL (visual_match_run_id)');
        // Consumption bookkeeping (spec §4/§8.1): one verification per
        // anchor per VLM model — a model_version bump re-opens old anchors.
        DB::statement('CREATE UNIQUE INDEX vlm_runs_anchor_model_unique ON vlm_verification_runs (visual_match_run_id, model_version) WHERE visual_match_run_id IS NOT NULL');
        // DEF-021 discovery dedup: at most one anchorless row per owner per
        // trigger_reason — the daily sweep can never duplicate them.
        DB::statement('CREATE UNIQUE INDEX vlm_runs_discovery_content_unique ON vlm_verification_runs (content_item_id, trigger_reason) WHERE visual_match_run_id IS NULL AND content_item_id IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX vlm_runs_discovery_story_unique ON vlm_verification_runs (story_id, trigger_reason) WHERE visual_match_run_id IS NULL AND story_id IS NOT NULL');

        Schema::create('vlm_candidate_verdicts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->index()->constrained();
            $table->foreignId('vlm_verification_run_id')->constrained()->cascadeOnDelete();
            // Nullable on purpose: the audit survives catalog edits — the
            // composite FK below nulls ONLY this column on product delete.
            $table->unsignedBigInteger('product_id')->nullable()->index();
            $table->string('product_label', 255);
            $table->string('brand_label', 255);
            $table->smallInteger('rank');
            $table->boolean('visible');
            $table->boolean('spoken');
            $table->boolean('gifting_cue');
            $table->decimal('confidence', 5, 4);
            // Validated frame timestamps (ms); null entries for unstamped
            // frames (carousel images / thumbnails).
            $table->jsonb('frame_timestamps');
            $table->text('rationale');
            // Null until the band mapper ran (pending-ledger rows carry none).
            $table->string('band', 15)->nullable();
            $table->string('rejection_reason', 100)->nullable();
            $table->timestamp('created_at');

            $table->index(['vlm_verification_run_id', 'rank']);
        });

        DB::statement("ALTER TABLE vlm_candidate_verdicts ADD CONSTRAINT vlm_candidate_verdicts_band_check CHECK (band IN ('auto', 'review', 'reject'))");
        // Explicit CASCADE (house pattern) even though the sibling
        // single-column FK above already cascades the row: two FKs on the
        // same child referencing the same parent must not disagree on intent.
        DB::statement('ALTER TABLE vlm_candidate_verdicts ADD CONSTRAINT vlm_candidate_verdicts_vlm_verification_run_tenant_fk FOREIGN KEY (vlm_verification_run_id, tenant_id) REFERENCES vlm_verification_runs (id, tenant_id) ON DELETE CASCADE');
        // Catalog edits never rewrite the audit trail: product delete nulls
        // ONLY product_id (column-scoped SET NULL), the labels survive.
        DB::statement('ALTER TABLE vlm_candidate_verdicts ADD CONSTRAINT vlm_candidate_verdicts_product_tenant_fk FOREIGN KEY (product_id, tenant_id) REFERENCES products (id, tenant_id) ON DELETE SET NULL (product_id)');
    }

    public function down(): void
    {
        Schema::dropIfExists('vlm_candidate_verdicts');
        Schema::dropIfExists('vlm_verification_runs');
    }
};
