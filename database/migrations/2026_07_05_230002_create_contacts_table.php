<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ENT-Contact (docs/30-data-model/00-data-model.md#ent-contact).
     * Write-owner: Module 3 CRM (ownership matrix); no reader modules.
     * Manual entry ONLY (REQ-M3-002) — contact auto-extraction is DEF-002
     * and is not built; no Provenance envelope (nothing here is
     * externally sourced).
     *
     * GDPR (DP-005): this row MUST remain hard-deletable — no append-only
     * trigger, no soft deletes, no dependent FK that would block a DELETE.
     */
    public function up(): void
    {
        Schema::create('contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('creator_id')->index()->constrained();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->text('postal_address')->nullable();
            $table->string('preferred_channel')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contacts');
    }
};
