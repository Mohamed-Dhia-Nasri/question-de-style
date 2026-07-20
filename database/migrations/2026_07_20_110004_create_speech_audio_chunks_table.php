<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sub-project D (spec §8.3): persisted extension-chunk artifacts —
     * mono 16 kHz FLAC slices of a candidate-bearing post's audio, written
     * during the pipeline while the video temp file still exists, consumed
     * asynchronously by TranscribeExtendedAudioJob. Ordinals are 1-based:
     * chunk 0 is the in-pipeline sync pass and is never persisted (CHECK
     * enforced). Rows + blobs are deleted by the job after successful
     * transcription; the daily orphan prune (chunk_orphan_days) and
     * CreatorEraser are the backstops (GDPR). Polymorphic owner per the
     * keyframes pattern; tenant-owned (ADR-0019).
     */
    public function up(): void
    {
        Schema::create('speech_audio_chunks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->index()->constrained('tenants')->cascadeOnDelete();
            // Polymorphic owner: ContentItem or Story (keyframes pattern).
            $table->string('owner_type');
            $table->unsignedBigInteger('owner_id');
            $table->smallInteger('ordinal');
            $table->unsignedInteger('offset_ms');
            $table->unsignedInteger('duration_ms');
            $table->string('storage_disk', 50);
            // tenants/{tenant}/audio-chunks/{platform}/{owner_id}/{ordinal}.flac
            $table->string('storage_path', 500);
            $table->unsignedInteger('byte_size');
            // sha256 of the stored FLAC bytes.
            $table->char('checksum', 64);
            $table->string('status', 15);
            $table->timestamps();

            // Chunk identity: a re-run may never duplicate or renumber
            // chunks. Also serves (owner_type, owner_id) prefix lookups.
            $table->unique(['owner_type', 'owner_id', 'ordinal']);
        });

        DB::statement("ALTER TABLE speech_audio_chunks ADD CONSTRAINT speech_audio_chunks_status_check CHECK (status IN ('pending', 'transcribed', 'failed'))");
        // 1-based: chunk 0 (the sync pass) must never land here.
        DB::statement('ALTER TABLE speech_audio_chunks ADD CONSTRAINT speech_audio_chunks_ordinal_check CHECK (ordinal >= 1)');
    }

    public function down(): void
    {
        Schema::dropIfExists('speech_audio_chunks');
    }
};
