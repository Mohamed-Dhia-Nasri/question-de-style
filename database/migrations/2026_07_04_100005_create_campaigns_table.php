<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ENT-Campaign (docs/30-data-model/00-data-model.md#ent-campaign).
     * Write-owner: Module 3 CRM (ownership matrix). Read-only FK anchor for
     * Module 1 (Mention.campaignId / MonitoredSubject.campaignId); Module 1
     * never writes this table. The campaign↔creator participation pivot is
     * Module 3 scope and is not created here.
     */
    public function up(): void
    {
        Schema::create('campaigns', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->foreignId('brand_id')->index()->constrained();
            $table->string('status', 20);
            $table->timestamp('start_at')->nullable();
            $table->timestamp('end_at')->nullable();
            $table->timestamps();
        });

        // ENUM-CampaignStatus — closed set, canonical in docs/00-meta/03-glossary.md#enum-campaignstatus.
        DB::statement(<<<'SQL'
            ALTER TABLE campaigns ADD CONSTRAINT campaigns_status_check
                CHECK (status IN ('DRAFT','PLANNED','ACTIVE','PAUSED','COMPLETED','CANCELLED'))
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('campaigns');
    }
};
