<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ENT-SeedingCampaign (docs/30-data-model/00-data-model.md#ent-seedingcampaign)
     * plus its creatorIds pivot. Write-owner: Module 3 CRM (ownership
     * matrix); M1 reads for reporting. Manual/internal entity — no
     * Provenance envelope.
     *
     * `seeding_type` encodes the four canonical seeding variants of
     * module-3 §2.5 (REQ-M3-006 / AC-M3-010) with the product-owner
     * confirmed tokens (spec D1). FLAGGED DEVIATION: the variants are
     * canonical prose, not yet a glossary ENUM-SeedingType — tokens await
     * a glossary amendment (see App\Shared\Enums\SeedingType).
     *
     * `product_id` is the PRIMARY product only; the authoritative per-unit
     * product is on each ENT-Shipment (canonical shape note).
     */
    public function up(): void
    {
        Schema::create('seeding_campaigns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->nullable()->index()->constrained();
            $table->string('name');
            $table->string('seeding_type', 30);
            $table->foreignId('brand_id')->index()->constrained();
            $table->foreignId('product_id')->nullable()->index()->constrained();
            $table->string('status', 20);
            $table->timestamps();
        });

        // Four seeding variants — canonical prose in module-3 §2.5; tokens per spec D1.
        DB::statement(<<<'SQL'
            ALTER TABLE seeding_campaigns ADD CONSTRAINT seeding_campaigns_seeding_type_check
                CHECK (seeding_type IN ('GIFTING','GIFTING_WITH_POST','PAID_PLUS_PRODUCT','ORGANIC'))
        SQL);

        // ENUM-SeedingCampaignStatus — closed set, canonical in docs/00-meta/03-glossary.md#enum-seedingcampaignstatus.
        DB::statement(<<<'SQL'
            ALTER TABLE seeding_campaigns ADD CONSTRAINT seeding_campaigns_status_check
                CHECK (status IN ('DRAFT','PLANNED','ACTIVE','SHIPPING','COMPLETED','CANCELLED'))
        SQL);

        Schema::create('seeding_campaign_creator', function (Blueprint $table) {
            $table->id();
            $table->foreignId('seeding_campaign_id')->constrained()->cascadeOnDelete();
            $table->foreignId('creator_id')->index()->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['seeding_campaign_id', 'creator_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seeding_campaign_creator');
        Schema::dropIfExists('seeding_campaigns');
    }
};
