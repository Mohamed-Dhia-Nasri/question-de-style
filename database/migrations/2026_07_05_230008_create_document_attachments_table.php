<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ENT-DocumentAttachment (docs/30-data-model/00-data-model.md#ent-documentattachment).
     * Write-owner: Module 3 CRM (ownership matrix); no reader modules.
     * Manual/internal entity — no Provenance envelope.
     *
     * `creator_id` / `campaign_id` are BOTH nullable by canonical shape —
     * either, both, or neither may be set; no XOR constraint is defined
     * (unlike the exactly-one-target rules on M1 analysis entities).
     */
    public function up(): void
    {
        Schema::create('document_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('creator_id')->nullable()->index()->constrained();
            $table->foreignId('campaign_id')->nullable()->index()->constrained();
            $table->string('file_name');
            $table->string('storage_url');
            $table->timestamp('uploaded_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_attachments');
    }
};
