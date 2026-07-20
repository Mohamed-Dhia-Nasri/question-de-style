<?php

namespace Tests\Feature\Enrichment;

use App\Modules\CRM\Models\Creator;
use App\Modules\CRM\Models\PlatformAccount;
use App\Modules\Monitoring\Models\ContentItem;
use App\Modules\Monitoring\Models\ContentTranscript;
use App\Platform\Ingestion\SourceRegistry;
use App\Shared\ValueObjects\Provenance;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Sub-project D identity fix (spec §9): transcript identity narrows from
 * (content_item_id, language, provider) to (content_item_id, provider) so
 * the dominant language shifting after extended chunks arrive (German
 * intro, English rest) can never strand a stale partial row under the old
 * language value. language becomes mutable transcript metadata. Safe for
 * the existing YouTube provider — one 'und' row per content item, ever.
 */
class ContentTranscriptIdentityTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'migrations/2026_07_20_110003_narrow_content_transcripts_unique_to_item_provider.php';

    private function makeContentItem(): ContentItem
    {
        $account = PlatformAccount::factory()->for(Creator::factory())->create();

        return ContentItem::factory()->for($account, 'platformAccount')->create();
    }

    /** @return array<string, mixed> */
    private function speechAttributes(ContentItem $item, string $language = 'de-DE'): array
    {
        return [
            'content_item_id' => $item->id,
            'language' => $language,
            'status' => ContentTranscript::STATUS_AVAILABLE,
            'text' => 'danke an Glossier für das PR Paket',
            'segments' => [['start' => '0.0', 'dur' => '4.2', 'text' => 'danke an Glossier für das PR Paket', 'language' => $language, 'chunk' => 0]],
            'provider' => SourceRegistry::GOOGLE_SPEECH_TO_TEXT,
            'provenance' => new Provenance(SourceRegistry::GOOGLE_SPEECH_TO_TEXT, CarbonImmutable::now(), 'google-speech-to-text-v2'),
            'checksum' => hash('sha256', 'danke an Glossier für das PR Paket'),
            'fetched_at' => CarbonImmutable::now(),
        ];
    }

    public function test_a_second_language_row_under_the_same_provider_is_rejected(): void
    {
        $item = $this->makeContentItem();
        ContentTranscript::query()->create($this->speechAttributes($item, 'de-DE'));

        try {
            ContentTranscript::query()->create($this->speechAttributes($item, 'en-US'));
            $this->fail('The narrowed (content_item_id, provider) identity must reject a second row under another language.');
        } catch (UniqueConstraintViolationException $e) {
            $this->assertStringContainsString('content_transcripts_item_provider_unique', $e->getMessage());
        }
    }

    public function test_rows_under_different_providers_coexist_for_one_item(): void
    {
        $item = $this->makeContentItem();
        // The YouTube consume-only row ('und', ADR-0028) …
        ContentTranscript::query()->create([
            ...$this->speechAttributes($item),
            'language' => 'und',
            'provider' => SourceRegistry::APIFY_YOUTUBE_TRANSCRIPT,
            'provenance' => new Provenance(SourceRegistry::APIFY_YOUTUBE_TRANSCRIPT, CarbonImmutable::now(), 'youtube-transcript-v1'),
        ]);
        // … and D's speech row live side by side under the narrowed key.
        ContentTranscript::query()->create($this->speechAttributes($item));

        $this->assertSame(2, ContentTranscript::query()->where('content_item_id', $item->id)->count());
    }

    public function test_language_is_mutable_transcript_metadata(): void
    {
        $item = $this->makeContentItem();
        $row = ContentTranscript::query()->create($this->speechAttributes($item, 'de-DE'));

        // The dominant detected language shifted once extended chunks arrived.
        $row->update(['language' => 'en-US']);

        $this->assertSame('en-US', $row->fresh()->language);
    }

    public function test_duplicate_guard_keeps_the_highest_id_row_per_pair(): void
    {
        // Rebuild the pre-migration state: wide key on, narrowed key off.
        DB::statement('ALTER TABLE content_transcripts DROP CONSTRAINT content_transcripts_item_provider_unique');
        DB::statement('ALTER TABLE content_transcripts ADD CONSTRAINT content_transcripts_content_item_id_language_provider_unique UNIQUE (content_item_id, language, provider)');

        $item = $this->makeContentItem();
        $stale = ContentTranscript::query()->create($this->speechAttributes($item, 'de-DE'));
        $fresh = ContentTranscript::query()->create($this->speechAttributes($item, 'en-US'));
        $unrelated = ContentTranscript::query()->create($this->speechAttributes($this->makeContentItem(), 'fr-FR'));

        $migration = require database_path(self::MIGRATION);
        $migration->up();

        // Highest id per (content_item_id, provider) survives; unrelated
        // rows are untouched; the narrowed constraint is back in force.
        $this->assertDatabaseMissing('content_transcripts', ['id' => $stale->id]);
        $this->assertDatabaseHas('content_transcripts', ['id' => $fresh->id]);
        $this->assertDatabaseHas('content_transcripts', ['id' => $unrelated->id]);
        $this->expectException(UniqueConstraintViolationException::class);
        ContentTranscript::query()->create($this->speechAttributes($item, 'es-ES'));
    }
}
