<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sub-project C audit trail (spec §4.4/§4.5): one visual_match_runs row
     * per analysis run (append-only usage — the latest run per post is
     * authoritative, history kept for calibration in E and debugging) plus
     * ranked visual_match_candidates carrying candidate-source evidence
     * (why was this product considered) and visibility evidence.
     * Tenant-owned (ADR-0019/0020) with composite (col, tenant_id) FKs per
     * the reach_results pattern. needs_verification is sub-project D's poll
     * flag. Erased with the creator (CreatorEraser); candidates cascade
     * from runs at the DB.
     */
    public function up(): void
    {
        Schema::create('visual_match_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->index()->constrained();
            $table->foreignId('content_item_id')->nullable()->constrained();
            $table->foreignId('story_id')->nullable()->constrained();
            $table->string('correlation_id', 64);
            $table->string('model_version', 64);
            // low never produces a run: empty candidate set skips pre-spend (§10).
            $table->string('priority', 10);
            // Coverage accounting: stored vs actually embedded, plus why the
            // difference (unsupported format / quality filter / near-dupes).
            $table->smallInteger('frames_available');
            $table->smallInteger('frames_processed');
            $table->smallInteger('frames_skipped_format');
            $table->smallInteger('frames_skipped_quality');
            $table->smallInteger('frames_deduped');
            $table->smallInteger('cache_hits');
            $table->integer('processing_ms');
            $table->smallInteger('candidates_checked');
            $table->decimal('best_score', 5, 4)->nullable();
            $table->string('outcome', 30);
            $table->string('rejection_reason', 100)->nullable();
            // Snapshot {category_map_used, auto, review, margin} per candidate
            // category — reproducibility across threshold recalibrations (E).
            $table->jsonb('thresholds');
            // Billed calls this run (cache hits excluded) × list price.
            $table->smallInteger('embedding_calls');
            $table->integer('estimated_cost_micro_usd');
            // Sub-project D's poll flag (§11).
            $table->boolean('needs_verification')->default(false);
            $table->timestamp('created_at');

            // Latest-run-per-post lookups (authoritative row = max id).
            $table->index(['content_item_id', 'id']);
            $table->index(['story_id', 'id']);
        });

        DB::statement('ALTER TABLE visual_match_runs ADD CONSTRAINT visual_match_runs_id_tenant_unique UNIQUE (id, tenant_id)');
        // Exactly one target: content item XOR story (recognition_detections precedent).
        DB::statement('ALTER TABLE visual_match_runs ADD CONSTRAINT visual_match_runs_target_check CHECK (num_nonnulls(content_item_id, story_id) = 1)');
        DB::statement("ALTER TABLE visual_match_runs ADD CONSTRAINT visual_match_runs_priority_check CHECK (priority IN ('high', 'medium'))");
        DB::statement(<<<'SQL'
            ALTER TABLE visual_match_runs ADD CONSTRAINT visual_match_runs_outcome_check
                CHECK (outcome IN ('matched', 'review', 'no_match', 'inconclusive', 'skipped_budget', 'skipped_read_only', 'skipped_provider'))
        SQL);
        DB::statement('ALTER TABLE visual_match_runs ADD CONSTRAINT visual_match_runs_content_item_tenant_fk FOREIGN KEY (content_item_id, tenant_id) REFERENCES content_items (id, tenant_id)');
        DB::statement('ALTER TABLE visual_match_runs ADD CONSTRAINT visual_match_runs_story_tenant_fk FOREIGN KEY (story_id, tenant_id) REFERENCES stories (id, tenant_id)');

        Schema::create('visual_match_candidates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->index()->constrained();
            $table->foreignId('visual_match_run_id')->constrained()->cascadeOnDelete();
            // Nullable on purpose: the audit survives catalog edits — the
            // composite FK below nulls ONLY this column on product delete.
            $table->unsignedBigInteger('product_id')->nullable()->index();
            $table->string('product_label', 255);
            $table->string('category', 50)->nullable();
            $table->smallInteger('rank');
            $table->decimal('best_similarity', 5, 4);
            $table->decimal('margin_to_runner_up', 5, 4)->nullable();
            // list of {ordinal, timestamp_ms, similarity, photo_id, represented_frames}.
            $table->jsonb('supporting_frames');
            $table->string('band', 15);
            $table->string('rejection_reason', 100)->nullable();
            // Candidate-source evidence: WHY was this product considered (§4.5).
            $table->string('source', 20);
            $table->boolean('shipment_in_window');
            $table->foreignId('seeding_campaign_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('shipment_anchor_at')->nullable();
            $table->smallInteger('shipment_age_days')->nullable();
            // Visibility evidence (§8): null when frames carry no timestamps.
            $table->integer('first_support_ms')->nullable();
            $table->integer('last_support_ms')->nullable();
            $table->integer('estimated_visible_ms')->nullable();
            $table->timestamp('created_at');

            $table->index(['visual_match_run_id', 'rank']);
        });

        DB::statement("ALTER TABLE visual_match_candidates ADD CONSTRAINT visual_match_candidates_band_check CHECK (band IN ('auto', 'review', 'reject'))");
        DB::statement("ALTER TABLE visual_match_candidates ADD CONSTRAINT visual_match_candidates_source_check CHECK (source IN ('shipment', 'roster'))");
        DB::statement('ALTER TABLE visual_match_candidates ADD CONSTRAINT visual_match_candidates_visual_match_run_tenant_fk FOREIGN KEY (visual_match_run_id, tenant_id) REFERENCES visual_match_runs (id, tenant_id)');
        // Catalog edits never rewrite the audit trail: product delete nulls
        // ONLY product_id (PostgreSQL 15+ column-scoped SET NULL — pg17
        // everywhere: pgvector/pgvector:pg17-bookworm locally, Neon PG17),
        // product_label survives. The composite reference keeps the pair
        // tenant-coherent while set (MATCH SIMPLE skips rows once nulled).
        DB::statement('ALTER TABLE visual_match_candidates ADD CONSTRAINT visual_match_candidates_product_tenant_fk FOREIGN KEY (product_id, tenant_id) REFERENCES products (id, tenant_id) ON DELETE SET NULL (product_id)');
    }

    public function down(): void
    {
        Schema::dropIfExists('visual_match_candidates');
        Schema::dropIfExists('visual_match_runs');
    }
};
