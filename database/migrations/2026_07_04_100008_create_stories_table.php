<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ENT-Story (docs/30-data-model/00-data-model.md#ent-story).
     * Write-owner: Module 1 Monitoring (ownership matrix). Ephemeral story
     * content archived before platform expiry (REQ-M1-004); always its own
     * entity, never a ContentItem. Externally sourced → mandatory Provenance
     * envelope (DP-002).
     *
     * `external_id` is the platform's native story identifier for idempotent
     * archival — schema-level addition flagged for a data-model amendment
     * (same rationale as content_items).
     */
    public function up(): void
    {
        Schema::create('stories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('platform_account_id')->index()->constrained();
            $table->string('platform', 20);
            $table->string('external_id')->nullable();
            $table->text('media_url')->nullable();
            $table->timestamp('captured_at')->index();
            $table->timestamp('expires_at')->nullable()->index();
            $table->jsonb('public_metrics')->nullable();
            $table->jsonb('provenance');
            $table->timestamps();

            $table->unique(['platform', 'external_id']);
            $table->index(['platform_account_id', 'captured_at']);
        });

        // ENUM-Platform — closed set, canonical in docs/00-meta/03-glossary.md#enum-platform.
        DB::statement(<<<'SQL'
            ALTER TABLE stories ADD CONSTRAINT stories_platform_check
                CHECK (platform IN ('INSTAGRAM','TIKTOK','YOUTUBE'))
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('stories');
    }
};
