<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ENT-PlatformAccount (docs/30-data-model/00-data-model.md#ent-platformaccount).
     * Write-owner: Module 3 CRM (ownership matrix). Module 1 reads it as the
     * author anchor for ContentItem / Story / MetricSnapshot and never writes
     * it. Externally sourced → mandatory Provenance envelope (DP-002).
     *
     * The (platform, handle) pair is treated as the account's external
     * platform identifier — unique so the same handle on the same platform
     * cannot be ingested twice (satisfies the "uniqueness on external
     * platform identifiers" requirement + AC-M1-001 duplicate prevention).
     *
     * FLAGGED DEVIATION (doc-amendment needed, same class as content_items /
     * stories external_id): the canonical ENT-PlatformAccount shape defines
     * `handle` without a uniqueness rule, so this invariant is not yet in the
     * data model. Caveat for the M3 owner of this table: platform handles are
     * mutable and reusable (a creator renaming frees the handle), so the
     * long-term key should be a platform-native immutable account id — add
     * that field and re-key when ENT-PlatformAccount is amended.
     */
    public function up(): void
    {
        Schema::create('platform_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('creator_id')->nullable()->index()->constrained();
            $table->string('platform', 20);
            $table->string('handle');
            $table->text('bio')->nullable();
            $table->jsonb('external_links')->nullable();
            $table->jsonb('follower_count')->nullable();
            $table->jsonb('provenance');
            $table->timestamps();

            $table->unique(['platform', 'handle']);
        });

        // ENUM-Platform — closed set, canonical in docs/00-meta/03-glossary.md#enum-platform.
        DB::statement(<<<'SQL'
            ALTER TABLE platform_accounts ADD CONSTRAINT platform_accounts_platform_check
                CHECK (platform IN ('INSTAGRAM','TIKTOK','YOUTUBE'))
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_accounts');
    }
};
