<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_transcripts', function (Blueprint $table): void {
            $table->id();
            // [seam audit 14] tenant-owned table convention (ADR-0019).
            $table->foreignId('tenant_id')->index()->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('content_item_id')->constrained()->cascadeOnDelete();
            // 'und' (BCP-47 undetermined) when the provider names no language —
            // NOT NULL so the unique key below has no NULL-duplicate hole.
            $table->string('language', 20);
            // 'available' = captions text present; 'unavailable' = the provider
            // was successfully asked and this video HAS no captions (negative
            // cache — never re-billed). Transport failures persist NOTHING.
            $table->string('status', 20);
            $table->text('text')->nullable();
            $table->jsonb('segments')->nullable();
            $table->string('provider', 100);
            $table->jsonb('provenance');
            $table->char('checksum', 64)->nullable();
            $table->timestamp('fetched_at');
            $table->timestamps();

            $table->unique(['content_item_id', 'language', 'provider']);
        });

        DB::statement("ALTER TABLE content_transcripts ADD CONSTRAINT content_transcripts_status_check CHECK (status IN ('available', 'unavailable'))");
        DB::statement("ALTER TABLE content_transcripts ADD CONSTRAINT content_transcripts_available_has_text_check CHECK (status <> 'available' OR text IS NOT NULL)");
    }

    public function down(): void
    {
        Schema::dropIfExists('content_transcripts');
    }
};
