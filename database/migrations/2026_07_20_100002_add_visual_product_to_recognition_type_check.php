<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * ENUM-RecognitionType grows VISUAL_PRODUCT (sub-project C, ADR-0029):
     * a confident keyframe↔reference-photo embedding match, carrying
     * product_id. Widen the closed-set CHECK to match the PHP enum.
     *
     * Glossary amendment (docs/00-meta/03-glossary.md#enum-recognitiontype),
     * landing with sub-project C's docs task: VISUAL_PRODUCT — "the seeded
     * product itself was recognized visually in the post's keyframes via
     * embedding similarity against the tenant's reference photos".
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE recognition_detections DROP CONSTRAINT recognition_detections_recognition_type_check');
        DB::statement(<<<'SQL'
            ALTER TABLE recognition_detections ADD CONSTRAINT recognition_detections_recognition_type_check
                CHECK (recognition_type IN (
                    'IMAGE_TEXT_OCR','LOGO','SPOKEN_BRAND','ON_SCREEN_TEXT',
                    'CAPTION_TEXT','MENTION','PRODUCT_TAG','VISUAL_PRODUCT'
                ))
        SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE recognition_detections DROP CONSTRAINT recognition_detections_recognition_type_check');
        DB::statement(<<<'SQL'
            ALTER TABLE recognition_detections ADD CONSTRAINT recognition_detections_recognition_type_check
                CHECK (recognition_type IN (
                    'IMAGE_TEXT_OCR','LOGO','SPOKEN_BRAND','ON_SCREEN_TEXT',
                    'CAPTION_TEXT','MENTION','PRODUCT_TAG'
                ))
        SQL);
    }
};
