<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Human-correction preservation (DP-004; ingestion requirement 17):
     * `human_overrides` holds the list of field names an analyst has
     * corrected on this record. Idempotent re-ingestion skips those fields,
     * so a subsequent poll never clobbers a human correction.
     *
     * FLAGGED DEVIATION: not part of the canonical ENT-ContentItem /
     * ENT-Story field tables — schema-level addition awaiting a data-model
     * doc amendment (same class as external_id).
     */
    public function up(): void
    {
        Schema::table('content_items', function (Blueprint $table) {
            $table->jsonb('human_overrides')->nullable();
        });

        Schema::table('stories', function (Blueprint $table) {
            $table->jsonb('human_overrides')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('content_items', function (Blueprint $table) {
            $table->dropColumn('human_overrides');
        });

        Schema::table('stories', function (Blueprint $table) {
            $table->dropColumn('human_overrides');
        });
    }
};
