<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('keyframes', function (Blueprint $table): void {
            $table->id();
            // [seam audit 14] tenant-owned table convention (ADR-0019).
            $table->foreignId('tenant_id')->index()->constrained('tenants')->cascadeOnDelete();
            // Polymorphic owner: ContentItem or Story (and future media roots).
            $table->string('owner_type');
            $table->unsignedBigInteger('owner_id');
            $table->unsignedInteger('ordinal');
            // Position in the source video; null for thumbnails/source images.
            $table->unsignedInteger('timestamp_ms')->nullable();
            $table->string('storage_disk', 50);
            $table->string('storage_path', 500);
            // Best-effort image metadata (getimagesize; null when undecodable).
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->string('kind', 20);
            // sha256 of the stored frame file.
            $table->char('checksum', 64);
            // sha256 of the SOURCE media the frame was derived from — ties a
            // frame to exact input bytes (reproducibility for tiers C/D).
            $table->char('source_checksum', 64);
            $table->jsonb('provenance');
            $table->timestamps();

            // Extract-once identity: a re-run may never duplicate or renumber
            // frames (tier C FKs embeddings to keyframes.id). The unique index
            // also serves (owner_type, owner_id) prefix lookups — no separate
            // composite index needed [seam audit 14].
            $table->unique(['owner_type', 'owner_id', 'ordinal']);
        });

        DB::statement("ALTER TABLE keyframes ADD CONSTRAINT keyframes_kind_check CHECK (kind IN ('video_sample', 'thumbnail', 'source_image'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('keyframes');
    }
};
