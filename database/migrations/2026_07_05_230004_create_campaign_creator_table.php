<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ENT-Campaign.creatorIds (docs/30-data-model/00-data-model.md#ent-campaign)
     * modeled as a pivot for referential integrity and query-ability —
     * the campaign↔creator participation the M1 stub deliberately left to
     * Module 3. Write-owner: Module 3 CRM (ownership matrix, via Campaign).
     *
     * Participation rows are relation rows, not entities: they die with
     * either side (cascade), and the composite unique key prevents a
     * creator being attached to the same campaign twice.
     */
    public function up(): void
    {
        Schema::create('campaign_creator', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->foreignId('creator_id')->index()->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['campaign_id', 'creator_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_creator');
    }
};
