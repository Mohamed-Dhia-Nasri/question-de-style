<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ENT-SentimentAnalysis (docs/30-data-model/00-data-model.md#ent-sentimentanalysis).
     * Write-owner: Module 1 Monitoring (ownership matrix). Sentiment +
     * context for a ContentItem or Comment (REQ-M1-009). Inferred →
     * mandatory ConfidenceAssessment (DP-003). Internal AI output — the
     * canonical shape carries NO Provenance envelope.
     *
     * comment_id stays unused in v1: comment analysis (REQ-M1-010) is
     * DEFERRED (DEF-005 / ADR-0009); sentiment runs on captions/transcripts.
     */
    public function up(): void
    {
        Schema::create('sentiment_analyses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('content_item_id')->nullable()->index()->constrained();
            $table->foreignId('comment_id')->nullable()->index()->constrained();
            $table->string('label', 20)->index();
            $table->text('context_summary')->nullable();
            $table->jsonb('assessment');
            $table->timestamps();
        });

        // ENUM-SentimentLabel — closed set, canonical in docs/00-meta/03-glossary.md#enum-sentimentlabel.
        DB::statement(<<<'SQL'
            ALTER TABLE sentiment_analyses ADD CONSTRAINT sentiment_analyses_label_check
                CHECK (label IN ('POSITIVE','NEUTRAL','NEGATIVE','MIXED','UNKNOWN'))
        SQL);

        // Exactly one subject: content item XOR comment.
        DB::statement(<<<'SQL'
            ALTER TABLE sentiment_analyses ADD CONSTRAINT sentiment_analyses_target_check
                CHECK (num_nonnulls(content_item_id, comment_id) = 1)
        SQL);

        // Review-queue path (DP-004 / AC-M1-010).
        DB::statement(<<<'SQL'
            CREATE INDEX sentiment_analyses_review_queue_index ON sentiment_analyses (
                (assessment->>'verificationStatus'),
                (assessment->>'confidenceLevel')
            )
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('sentiment_analyses');
    }
};
