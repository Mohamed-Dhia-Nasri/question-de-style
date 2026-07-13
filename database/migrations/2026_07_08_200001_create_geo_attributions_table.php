<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ENT-GeoAttribution (docs/30-data-model/00-data-model.md#ent-geoattribution,
     * REQ-M2-003). Write-owner: Module 2 Discovery (ownership matrix) — CRM
     * assigns operator geography ONLY through the Discovery-side
     * CreatorGeography seam (ADR-0018).
     *
     * v1 (ADR-0018): operator-assigned geography ahead of Module 2's
     * automatic inference — ONE current row per creator (unique), updated in
     * place with the audit trail carrying changes. M2's signal-based
     * attribution (AC-M2-003a) may later relax the uniqueness to per-account
     * rows; that is P2 work.
     *
     * FLAGGED DEVIATIONS: `city` is not in the canonical entity shape, but
     * DIM-Geo and ROLLUP-MetricByGeo already model city — entity-shape
     * amendment candidate. FK cascades: the assessment is lifecycle-coupled
     * config about the creator (roster-enrollment precedent), so it never
     * blocks creator deletion.
     */
    public function up(): void
    {
        Schema::create('geo_attributions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('creator_id')->unique()->constrained()->cascadeOnDelete();
            $table->char('country_code', 2)->nullable();
            $table->string('region')->nullable();
            $table->string('city')->nullable();
            // Location is never a fact (DP-003) — mandatory envelope even
            // for operator entry (value = assessed country, signals name
            // the entry surface, verificationStatus = HUMAN_REVIEWED).
            $table->jsonb('assessment');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('geo_attributions');
    }
};
