<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * ENUM-RecognitionType grows VLM_PRODUCT (sub-project D, ADR-0030):
     * the seeded product confirmed by the Gemini VLM grounding pass over
     * stored keyframes against the tenant's candidate catalog, carrying
     * product_id. Widen the closed-set CHECK to match the PHP enum.
     *
     * Glossary amendment (docs/00-meta/03-glossary.md#enum-recognitiontype),
     * landing with sub-project D's docs task: VLM_PRODUCT — "the seeded
     * product itself was confirmed in the post's keyframes by the Gemini
     * vision-language model, grounded against the tenant's catalog".
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE recognition_detections DROP CONSTRAINT recognition_detections_recognition_type_check');
        DB::statement(<<<'SQL'
            ALTER TABLE recognition_detections ADD CONSTRAINT recognition_detections_recognition_type_check
                CHECK (recognition_type IN (
                    'IMAGE_TEXT_OCR','LOGO','SPOKEN_BRAND','ON_SCREEN_TEXT',
                    'CAPTION_TEXT','MENTION','PRODUCT_TAG','VISUAL_PRODUCT','VLM_PRODUCT'
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
                    'CAPTION_TEXT','MENTION','PRODUCT_TAG','VISUAL_PRODUCT'
                ))
        SQL);
    }
};
