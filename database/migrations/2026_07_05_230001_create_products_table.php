<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ENT-Product (docs/30-data-model/00-data-model.md#ent-product).
     * Write-owner: Module 3 CRM (ownership matrix); M1/M2 read only. The
     * product is the key that aggregates seeding results across creators
     * (REQ-M3-013). Manual/internal entity — no Provenance envelope.
     *
     * `unit_value` is a MetricValue envelope (jsonb via AsValueObject),
     * tier CONFIRMED: the agency knows its own product's price; envelope
     * shape is enforced by the value-object layer, not a jsonb CHECK
     * (same as every other envelope column).
     */
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('brand_id')->index()->constrained();
            $table->string('name');
            $table->string('sku')->nullable();
            $table->string('variant')->nullable();
            $table->jsonb('unit_value')->nullable();
            $table->string('category', 50)->nullable();
            $table->timestamps();
        });

        // ENUM-SectorLabel — closed set, canonical in docs/00-meta/03-glossary.md#enum-sectorlabel.
        DB::statement(<<<'SQL'
            ALTER TABLE products ADD CONSTRAINT products_category_check CHECK (
                category IN (
                    'FASHION','BEAUTY','FITNESS','FOOD_BEVERAGE','TRAVEL','LIFESTYLE','TECH','GAMING',
                    'PARENTING_FAMILY','HOME_INTERIOR','HEALTH_WELLNESS','FINANCE','AUTOMOTIVE',
                    'ENTERTAINMENT','SPORTS','EDUCATION','BUSINESS','ART_DESIGN','MUSIC','OTHER'
                )
            )
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
