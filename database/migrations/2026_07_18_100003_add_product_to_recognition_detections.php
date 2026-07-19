<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recognition_detections', function (Blueprint $table): void {
            $table->string('detected_product')->nullable()->after('detected_brand');
            $table->foreignId('product_id')->nullable()->after('detected_product')->constrained()->nullOnDelete();
        });

        // ENUM-RecognitionType grows CAPTION_TEXT / MENTION / PRODUCT_TAG —
        // widen the closed-set CHECK constraint from the create-table
        // migration to match (docs/00-meta/03-glossary.md#enum-recognitiontype).
        DB::statement('ALTER TABLE recognition_detections DROP CONSTRAINT recognition_detections_recognition_type_check');
        DB::statement(<<<'SQL'
            ALTER TABLE recognition_detections ADD CONSTRAINT recognition_detections_recognition_type_check
                CHECK (recognition_type IN (
                    'IMAGE_TEXT_OCR','LOGO','SPOKEN_BRAND','ON_SCREEN_TEXT',
                    'CAPTION_TEXT','MENTION','PRODUCT_TAG'
                ))
        SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE recognition_detections DROP CONSTRAINT recognition_detections_recognition_type_check');
        DB::statement(<<<'SQL'
            ALTER TABLE recognition_detections ADD CONSTRAINT recognition_detections_recognition_type_check
                CHECK (recognition_type IN ('IMAGE_TEXT_OCR','LOGO','SPOKEN_BRAND','ON_SCREEN_TEXT'))
        SQL);

        Schema::table('recognition_detections', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('product_id');
            $table->dropColumn('detected_product');
        });
    }
};
