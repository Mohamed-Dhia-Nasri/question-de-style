<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Sub-project D identity fix (spec §9): one transcript per
     * (content_item_id, provider) — language becomes MUTABLE transcript
     * metadata (the dominant detected language by billed seconds;
     * per-chunk languages live in segments). Under the old
     * (content_item_id, language, provider) key, the dominant language
     * shifting after extended chunks arrive (German intro, English rest)
     * would strand a stale partial row under the old language value.
     *
     * Safe for the existing YouTube provider: its enricher only ever
     * writes ONE 'und' row per content item, so the narrowed key changes
     * nothing for it. The duplicate-guard runs first (keep the highest-id
     * row per pair) — defensive only: no code path can have produced
     * same-item-same-provider rows under different languages yet.
     */
    public function up(): void
    {
        // Duplicate-guard BEFORE the narrowed constraint: keep the
        // highest-id (freshest) row per (content_item_id, provider).
        DB::statement(<<<'SQL'
            DELETE FROM content_transcripts a
            USING content_transcripts b
            WHERE a.content_item_id = b.content_item_id
              AND a.provider = b.provider
              AND a.id < b.id
        SQL);

        DB::statement('ALTER TABLE content_transcripts DROP CONSTRAINT content_transcripts_content_item_id_language_provider_unique');
        DB::statement('ALTER TABLE content_transcripts ADD CONSTRAINT content_transcripts_item_provider_unique UNIQUE (content_item_id, provider)');
    }

    public function down(): void
    {
        // The constraint swap reverses cleanly; rows the duplicate-guard
        // deleted are gone for good (data migration, accepted).
        DB::statement('ALTER TABLE content_transcripts DROP CONSTRAINT content_transcripts_item_provider_unique');
        DB::statement('ALTER TABLE content_transcripts ADD CONSTRAINT content_transcripts_content_item_id_language_provider_unique UNIQUE (content_item_id, language, provider)');
    }
};
