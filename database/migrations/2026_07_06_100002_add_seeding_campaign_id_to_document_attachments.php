<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Documents attach to "creators, campaigns, or seeding runs"
     * (module-3 §2.9, AC-M3-016): a nullable `seeding_campaign_id` FK joins
     * the existing nullable creator_id / campaign_id anchors — any
     * combination, no XOR constraint, restrict-on-delete like its siblings.
     *
     * FLAGGED DEVIATION (spec D6): the canonical ENT-DocumentAttachment
     * shape defines creator/campaign anchors only — schema-level addition
     * awaiting a data-model doc amendment.
     */
    public function up(): void
    {
        Schema::table('document_attachments', function (Blueprint $table) {
            $table->foreignId('seeding_campaign_id')->nullable()->index()->constrained();
        });
    }

    public function down(): void
    {
        Schema::table('document_attachments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('seeding_campaign_id');
        });
    }
};
