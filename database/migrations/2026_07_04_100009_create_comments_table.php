<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ENT-Comment (docs/30-data-model/00-data-model.md#ent-comment).
     * Write-owner: Module 1 Monitoring (ownership matrix).
     *
     * SCHEMA ONLY IN V1: comment collection and audience-reaction analysis
     * (REQ-M1-010) is DEFERRED on cost grounds (DEF-005 / ADR-0009). The
     * entity remains defined in the canonical model, so the table exists,
     * but no ingestion writes it and no comment feature is exposed. Consuming
     * surfaces render comment-derived data as "unavailable".
     *
     * author_handle and text are third-party personal data (DP-005) — the
     * model encrypts both at rest via Laravel encrypted casts.
     */
    public function up(): void
    {
        Schema::create('comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('content_item_id')->index()->constrained();
            $table->foreignId('parent_comment_id')->nullable()->index()->constrained('comments');
            $table->text('author_handle')->nullable();
            $table->text('text');
            $table->jsonb('like_count')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->jsonb('provenance');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('comments');
    }
};
