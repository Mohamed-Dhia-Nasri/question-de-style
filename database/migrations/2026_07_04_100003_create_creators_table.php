<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ENT-Creator (docs/30-data-model/00-data-model.md#ent-creator).
     * Write-owner: Module 3 CRM — the system of record for creator identity
     * and cross-platform merge. Module 1 reads it (roster subjects) and
     * proposes new creators via the cross-module contract; it never writes
     * this table (ownership matrix).
     *
     * `mergedAccountIds` is intentionally NOT a column: merged accounts are
     * the platform_accounts.creator_id relation — storing an id list here
     * would duplicate creator identity linkage.
     *
     * No personal contact data lives here (ENT-Contact is a separate
     * M3-owned entity, manual entry only per ADR-0005 — not created in this
     * phase).
     */
    public function up(): void
    {
        Schema::create('creators', function (Blueprint $table) {
            $table->id();
            $table->string('display_name');
            $table->string('primary_language', 10)->nullable();
            $table->string('relationship_status', 30)->nullable();
            // ENT-Creator lists createdAt/updatedAt as Required=Yes — the only
            // in-scope entity that does — so these are NOT NULL (not the
            // nullable columns $table->timestamps() would produce). Eloquent
            // still maintains them; useCurrent() covers raw inserts.
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent();
        });

        // ENUM-RelationshipStatus — closed set, canonical in docs/00-meta/03-glossary.md#enum-relationshipstatus.
        DB::statement(<<<'SQL'
            ALTER TABLE creators ADD CONSTRAINT creators_relationship_status_check CHECK (
                relationship_status IN (
                    'NONE','PROSPECT','CONTACTED','IN_CONVERSATION','ACTIVE',
                    'COLLABORATED','PAUSED','DECLINED','BLOCKLISTED'
                )
            )
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('creators');
    }
};
