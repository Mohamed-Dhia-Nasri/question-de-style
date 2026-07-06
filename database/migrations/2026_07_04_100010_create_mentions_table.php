<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ENT-Mention (docs/30-data-model/00-data-model.md#ent-mention).
     * Write-owner: Module 1 Monitoring (ownership matrix). A detected
     * occurrence of a MonitoredSubject in content. Inferred classification →
     * mandatory ConfidenceAssessment envelope (DP-003); derived from
     * externally-sourced content → mandatory Provenance envelope (DP-002).
     *
     * mention_type rule (ENUM-MentionType, glossary): PAID/SEEDED only when
     * a record or label proves it; otherwise LIKELY_ORGANIC/UNKNOWN — there
     * is deliberately no CONFIRMED_ORGANIC value (organic is never asserted
     * as fact, ADR-0008).
     *
     * A mention lives in exactly one place: a ContentItem (post/reel) or a
     * Story — enforced by the target CHECK.
     */
    public function up(): void
    {
        Schema::create('mentions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('monitored_subject_id')->index()->constrained();
            $table->foreignId('content_item_id')->nullable()->index()->constrained();
            $table->foreignId('story_id')->nullable()->index()->constrained();
            $table->foreignId('campaign_id')->nullable()->index()->constrained();
            $table->string('mention_type', 20)->index();
            $table->jsonb('classification');
            $table->jsonb('provenance');
            $table->timestamps();
        });

        // ENUM-MentionType — closed set, canonical in docs/00-meta/03-glossary.md#enum-mentiontype.
        DB::statement(<<<'SQL'
            ALTER TABLE mentions ADD CONSTRAINT mentions_mention_type_check
                CHECK (mention_type IN ('PAID','SEEDED','LIKELY_ORGANIC','UNKNOWN'))
        SQL);

        // Exactly one source: content item XOR story.
        DB::statement(<<<'SQL'
            ALTER TABLE mentions ADD CONSTRAINT mentions_target_check
                CHECK (num_nonnulls(content_item_id, story_id) = 1)
        SQL);

        // Review-queue path (DP-004): low-confidence AI classifications are
        // queried by verificationStatus + confidenceLevel inside the
        // ConfidenceAssessment envelope.
        DB::statement(<<<'SQL'
            CREATE INDEX mentions_review_queue_index ON mentions (
                (classification->>'verificationStatus'),
                (classification->>'confidenceLevel')
            )
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('mentions');
    }
};
