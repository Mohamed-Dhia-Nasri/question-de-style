<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ENT-ContentItem (docs/30-data-model/00-data-model.md#ent-contentitem).
     * Write-owner: Module 1 Monitoring (ownership matrix). Externally
     * sourced → mandatory Provenance envelope (DP-002).
     *
     * STORY is never a ContentItem: the content_type CHECK below encodes the
     * closed ENUM-ContentType set, which deliberately excludes STORY (rule
     * F8; ephemeral stories are ENT-Story).
     *
     * `external_id` is the platform's native content identifier, kept for
     * idempotent ingestion (AC-M1-001: "no duplicate is created within one
     * cycle"). It is a schema-level addition not present in the canonical
     * field table — flagged for a data-model doc amendment.
     */
    public function up(): void
    {
        Schema::create('content_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('platform_account_id')->index()->constrained();
            $table->string('platform', 20);
            $table->string('content_type', 20);
            $table->string('external_id')->nullable();
            $table->text('caption')->nullable();
            $table->jsonb('media_urls')->nullable();
            $table->timestamp('published_at')->nullable()->index();
            $table->jsonb('public_metrics')->nullable();
            $table->jsonb('provenance');
            $table->timestamps();

            $table->unique(['platform', 'external_id']);
            $table->index(['platform_account_id', 'published_at']);
        });

        // ENUM-Platform / ENUM-ContentType — closed sets, canonical in
        // docs/00-meta/03-glossary.md (STORY is deliberately absent).
        DB::statement(<<<'SQL'
            ALTER TABLE content_items ADD CONSTRAINT content_items_platform_check
                CHECK (platform IN ('INSTAGRAM','TIKTOK','YOUTUBE'))
        SQL);
        DB::statement(<<<'SQL'
            ALTER TABLE content_items ADD CONSTRAINT content_items_content_type_check
                CHECK (content_type IN ('IMAGE_POST','CAROUSEL','REEL','VIDEO','SHORT','LIVE'))
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('content_items');
    }
};
