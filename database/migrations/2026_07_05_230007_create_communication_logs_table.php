<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ENT-CommunicationLog (docs/30-data-model/00-data-model.md#ent-communicationlog).
     * Write-owner: Module 3 CRM (ownership matrix); no reader modules.
     * Manual/internal entity — no Provenance envelope. `channel` and
     * `direction` are canonical STRING fields (glossary defines no closed
     * enum for them) — no CHECK constraint, per Rule 4 no invented set.
     *
     * Operational CRM record: mutable, no append-only trigger (unlike
     * metric_snapshots).
     */
    public function up(): void
    {
        Schema::create('communication_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('creator_id')->index()->constrained();
            $table->foreignId('campaign_id')->nullable()->index()->constrained();
            $table->string('channel');
            $table->string('direction');
            $table->text('summary');
            $table->timestamp('occurred_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('communication_logs');
    }
};
