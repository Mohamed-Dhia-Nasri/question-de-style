<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ENT-Brand (docs/30-data-model/00-data-model.md#ent-brand).
     * Write-owner: Module 3 CRM (ownership matrix). Read-only FK anchor for
     * Campaign; Module 1 never writes this table.
     */
    public function up(): void
    {
        Schema::create('brands', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->index()->constrained();
            $table->string('name');
            $table->string('sector', 50)->nullable();
            $table->jsonb('aliases')->nullable();
            $table->timestamps();
        });

        // ENUM-SectorLabel — closed set, canonical in docs/00-meta/03-glossary.md#enum-sectorlabel.
        DB::statement(<<<'SQL'
            ALTER TABLE brands ADD CONSTRAINT brands_sector_check CHECK (
                sector IN (
                    'FASHION','BEAUTY','FITNESS','FOOD_BEVERAGE','TRAVEL','LIFESTYLE','TECH','GAMING',
                    'PARENTING_FAMILY','HOME_INTERIOR','HEALTH_WELLNESS','FINANCE','AUTOMOTIVE',
                    'ENTERTAINMENT','SPORTS','EDUCATION','BUSINESS','ART_DESIGN','MUSIC','OTHER'
                )
            )
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('brands');
    }
};
