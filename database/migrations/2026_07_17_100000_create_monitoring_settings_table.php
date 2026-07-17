<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Per-tenant monitoring settings (ADR-0025): the operator-chosen gift-link
 * (shipment attribution) window, engagement-trend window, and the two
 * retention periods (story media, communication logs). Append-only —
 * every save inserts a NEW row and the latest row per tenant wins
 * (mirrors monitoring_plan_settings), so setting history is auditable.
 * 0 means "keep forever" for the retention columns only; the attribution
 * and trend windows have no off-state.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monitoring_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            // Posts within this many days after delivery/shipping can be
            // attributed to the gift (ADR-0025; consumed by MentionClassifier).
            $table->unsignedSmallInteger('shipment_window_days');
            // Rolling window N for the engagement trend (ADR-0024):
            // last N days vs the N days before.
            $table->unsignedSmallInteger('engagement_trend_window_days');
            // Archived story media older than this is deleted (0 = keep forever).
            $table->unsignedSmallInteger('story_retention_days');
            // Communication logs older than this are deleted (0 = keep forever).
            $table->unsignedInteger('communication_retention_days');
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'id']);
        });

        // Mirror the page validation at the DB layer (project convention:
        // closed rules get CHECK constraints).
        DB::statement(<<<'SQL'
            ALTER TABLE monitoring_settings ADD CONSTRAINT monitoring_settings_ranges_check CHECK (
                shipment_window_days BETWEEN 1 AND 365
                AND engagement_trend_window_days BETWEEN 7 AND 90
                AND story_retention_days BETWEEN 0 AND 3650
                AND communication_retention_days BETWEEN 0 AND 3650
            )
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('monitoring_settings');
    }
};
