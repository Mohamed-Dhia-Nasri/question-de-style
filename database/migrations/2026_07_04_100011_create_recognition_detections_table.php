<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ENT-RecognitionDetection (docs/30-data-model/00-data-model.md#ent-recognitiondetection).
     * Write-owner: Module 1 Monitoring (ownership matrix). A brand-recognition
     * hit (OCR / logo / spoken-brand / on-screen text, REQ-M1-008). Inferred →
     * mandatory ConfidenceAssessment (DP-003); produced from AI sources
     * (SRC-google-*) → mandatory Provenance (DP-002). Low-confidence
     * detections route to the human review queue (DP-004).
     */
    public function up(): void
    {
        Schema::create('recognition_detections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('content_item_id')->nullable()->index()->constrained();
            $table->foreignId('story_id')->nullable()->index()->constrained();
            $table->string('recognition_type', 20)->index();
            $table->text('detected_text')->nullable();
            $table->string('detected_brand')->nullable()->index();
            $table->jsonb('assessment');
            $table->jsonb('provenance');
            $table->timestamps();
        });

        // ENUM-RecognitionType — closed set, canonical in docs/00-meta/03-glossary.md#enum-recognitiontype.
        DB::statement(<<<'SQL'
            ALTER TABLE recognition_detections ADD CONSTRAINT recognition_detections_recognition_type_check
                CHECK (recognition_type IN ('IMAGE_TEXT_OCR','LOGO','SPOKEN_BRAND','ON_SCREEN_TEXT'))
        SQL);

        // Exactly one source: content item XOR story.
        DB::statement(<<<'SQL'
            ALTER TABLE recognition_detections ADD CONSTRAINT recognition_detections_target_check
                CHECK (num_nonnulls(content_item_id, story_id) = 1)
        SQL);

        // Review-queue path (DP-004 / AC-M1-009).
        DB::statement(<<<'SQL'
            CREATE INDEX recognition_detections_review_queue_index ON recognition_detections (
                (assessment->>'verificationStatus'),
                (assessment->>'confidenceLevel')
            )
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('recognition_detections');
    }
};
