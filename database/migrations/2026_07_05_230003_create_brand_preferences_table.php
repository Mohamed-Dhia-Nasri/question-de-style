<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ENT-BrandPreference (docs/30-data-model/00-data-model.md#ent-brandpreference).
     * Write-owner: Module 3 CRM (ownership matrix); no reader modules.
     * Manual/internal entity — no Provenance envelope.
     *
     * `preferred_brands` / `restricted_brands` are jsonb lists of STRING
     * (the canonical shape is "list of string", not FK ids — brand names /
     * sectors a creator prefers or will not work with). Restrictions are
     * enforced as hard filters when a creator joins a campaign/seeding run
     * (module-3 §2.3) — that enforcement is Step-3 behaviour, not schema.
     */
    public function up(): void
    {
        Schema::create('brand_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('creator_id')->index()->constrained();
            $table->jsonb('preferred_brands')->nullable();
            $table->jsonb('restricted_brands')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('brand_preferences');
    }
};
