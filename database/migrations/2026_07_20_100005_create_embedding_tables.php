<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Derived vector storage for visual matching (sub-project C, spec
     * §4.2/§4.3). Immutable per (parent, model_version): a replaced photo is
     * a NEW photo row; a model upgrade backfills NEW rows — never in-place
     * mutation. vector(3072) is deliberate DDL: request dimensionality and
     * column width must agree, so a different-width model is a schema
     * migration, not a config flip. Exact scan only — no HNSW/IVFFlat
     * (3072 also exceeds pgvector's 2000-dim index limit for the vector
     * type; the sanctioned ANN paths — halfvec expression index or
     * Matryoshka truncation — are documented in the spec §4.2). The
     * DB-level ON DELETE CASCADEs keep CreatorEraser's in-transaction bulk
     * deletes and qds:prune-keyframes correct with zero code changes.
     */
    public function up(): void
    {
        Schema::create('product_photo_embeddings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->index()->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('product_reference_photo_id')->constrained('product_reference_photos')->cascadeOnDelete();
            $table->string('model_version', 64);
            $table->timestamp('created_at');

            // T9's idempotency key; its prefix also serves per-photo lookups.
            $table->unique(['product_reference_photo_id', 'model_version'], 'product_photo_embeddings_photo_model_unique');
        });
        // Blueprint has no vector type — raw DDL. Width must match the
        // qds.enrichment.visual_match.dimensions request knob (spec §4.2).
        DB::statement('ALTER TABLE product_photo_embeddings ADD COLUMN embedding vector(3072) NOT NULL');
        // Embeddings must live in their photo's tenant; CASCADE on the
        // composite too so photo/product deletes are order-independent.
        DB::statement('ALTER TABLE product_photo_embeddings ADD CONSTRAINT product_photo_embeddings_product_reference_photo_id_tenant_fk FOREIGN KEY (product_reference_photo_id, tenant_id) REFERENCES product_reference_photos (id, tenant_id) ON DELETE CASCADE');

        Schema::create('keyframe_embeddings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->index()->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('keyframe_id')->constrained('keyframes')->cascadeOnDelete();
            $table->string('model_version', 64);
            $table->timestamp('created_at');

            // T13's cache key: one embedding per frame per model_version.
            $table->unique(['keyframe_id', 'model_version'], 'keyframe_embeddings_keyframe_model_unique');
        });
        DB::statement('ALTER TABLE keyframe_embeddings ADD COLUMN embedding vector(3072) NOT NULL');
        // Composite FK target (id, tenant_id) on keyframes arrives in
        // 2026_07_20_100003 (runs earlier by filename order).
        DB::statement('ALTER TABLE keyframe_embeddings ADD CONSTRAINT keyframe_embeddings_keyframe_id_tenant_fk FOREIGN KEY (keyframe_id, tenant_id) REFERENCES keyframes (id, tenant_id) ON DELETE CASCADE');
    }

    public function down(): void
    {
        Schema::dropIfExists('keyframe_embeddings');
        Schema::dropIfExists('product_photo_embeddings');
    }
};
