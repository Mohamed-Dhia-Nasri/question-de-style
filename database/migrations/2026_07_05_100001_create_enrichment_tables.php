<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * SVC-EnrichmentAI support tables (module-1-monitoring.md, REQ-M1-002 /
     * REQ-M1-008 / DP-004).
     *
     * FLAGGED DEVIATION: none of these four tables is a canonical ENT-* —
     * the data model defines no hashtag entity, no review-queue/correction
     * entity, and no enrichment telemetry shape. They follow the same
     * amendment path as the ingestion operational tables (see
     * reviews/REVIEW-module1-ingestion-pipeline-2026-07-04.md §4):
     *
     * - hashtag_lists: configured campaign/brand/product/agency hashtag
     *   registry used as ATTRIBUTION EVIDENCE (a hashtag may strengthen
     *   attribution but never proves it alone — ADR-0008 doctrine).
     * - content_hashtags: hashtags extracted from ContentItem captions
     *   (original preserved verbatim + Unicode-normalized form) with their
     *   list matches; ambiguous matches route to human review (DP-004).
     * - review_actions: append-only DP-004 correction history — the
     *   original AI output snapshot, the decision, the correction payload,
     *   and the reviewer identity. The queue itself remains the canonical
     *   envelope query (mentions/recognition/sentiment review indexes).
     * - enrichment_runs: operational telemetry (not an ENT-*) — one row per
     *   enrichment pass over a ContentItem or Story.
     */
    public function up(): void
    {
        Schema::create('hashtag_lists', function (Blueprint $table) {
            $table->id();
            $table->string('scope', 20)->index();
            $table->foreignId('campaign_id')->nullable()->index()->constrained();
            $table->foreignId('brand_id')->nullable()->index()->constrained();
            // ENT-Product is Module 3 / P3 scope and not yet migrated; until
            // then a product-scoped hashtag names its product textually
            // under its brand. FLAGGED DEVIATION (P3 will FK this).
            $table->string('product_label')->nullable();
            $table->string('hashtag');
            $table->string('normalized')->index();
            $table->boolean('active')->default(true)->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // Internal operational vocabulary — not a canonical ENUM-*.
        DB::statement(<<<'SQL'
            ALTER TABLE hashtag_lists ADD CONSTRAINT hashtag_lists_scope_check
                CHECK (scope IN ('CAMPAIGN','BRAND','PRODUCT','AGENCY'))
        SQL);

        // Each scope names its owner; AGENCY hashtags belong to QDS itself.
        DB::statement(<<<'SQL'
            ALTER TABLE hashtag_lists ADD CONSTRAINT hashtag_lists_scope_target_check
                CHECK (
                    (scope <> 'CAMPAIGN' OR campaign_id IS NOT NULL)
                AND (scope <> 'BRAND' OR brand_id IS NOT NULL)
                AND (scope <> 'PRODUCT' OR (brand_id IS NOT NULL AND product_label IS NOT NULL))
                AND (scope <> 'AGENCY' OR (campaign_id IS NULL AND brand_id IS NULL AND product_label IS NULL))
                )
        SQL);

        // One list entry per (normalized hashtag, scope target).
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX hashtag_lists_entry_unique ON hashtag_lists (
                normalized,
                scope,
                COALESCE(campaign_id, 0),
                COALESCE(brand_id, 0),
                COALESCE(product_label, '')
            )
        SQL);

        Schema::create('content_hashtags', function (Blueprint $table) {
            $table->id();
            $table->foreignId('content_item_id')->index()->constrained();
            $table->string('original');
            $table->string('normalized')->index();
            $table->unsignedInteger('first_position');
            $table->unsignedSmallInteger('occurrences')->default(1);
            $table->jsonb('matches')->nullable();
            $table->boolean('is_ambiguous')->default(false)->index();
            // Human resolution of an ambiguous match survives re-extraction
            // (DP-004 — a later AI run never overwrites a human decision).
            $table->foreignId('resolved_hashtag_list_id')->nullable()->constrained('hashtag_lists')->nullOnDelete();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->unique(['content_item_id', 'normalized']);
        });

        Schema::create('review_actions', function (Blueprint $table) {
            $table->id();
            $table->string('reviewable_type');
            $table->unsignedBigInteger('reviewable_id');
            $table->string('action', 20);
            $table->jsonb('original');
            $table->jsonb('correction')->nullable();
            $table->text('reason')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            // Snapshot of the reviewer id — survives GDPR user deletion
            // (same convention as audit_logs.context.actor_id).
            $table->unsignedBigInteger('actor_id');
            $table->timestamp('created_at')->index();

            $table->index(['reviewable_type', 'reviewable_id']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE review_actions ADD CONSTRAINT review_actions_action_check
                CHECK (action IN ('APPROVE','CORRECT','REJECT','UNRESOLVED'))
        SQL);

        Schema::create('enrichment_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('content_item_id')->nullable()->index()->constrained();
            $table->foreignId('story_id')->nullable()->index()->constrained();
            $table->string('correlation_id', 64)->index();
            $table->string('status', 10);
            $table->jsonb('stages')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'started_at']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE enrichment_runs ADD CONSTRAINT enrichment_runs_status_check
                CHECK (status IN ('RUNNING','COMPLETED','PARTIAL','FAILED'))
        SQL);

        // Exactly one target: content item XOR story.
        DB::statement(<<<'SQL'
            ALTER TABLE enrichment_runs ADD CONSTRAINT enrichment_runs_target_check
                CHECK (num_nonnulls(content_item_id, story_id) = 1)
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('enrichment_runs');
        Schema::dropIfExists('review_actions');
        Schema::dropIfExists('content_hashtags');
        Schema::dropIfExists('hashtag_lists');
    }
};
