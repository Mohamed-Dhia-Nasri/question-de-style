<?php

namespace Tests\Feature\Enrichment;

use App\Modules\Monitoring\Models\ContentItem;
use App\Modules\Monitoring\Models\ContentTranscript;
use App\Platform\Enrichment\Speech\ChunkTranscript;
use App\Platform\Enrichment\Speech\SpeechTranscriptWriter;
use App\Platform\Ingestion\SourceRegistry;
use App\Shared\ValueObjects\Provenance;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Sub-project D (spec §9): ONE speech transcript row per content item on
 * the narrowed (content_item_id, provider) identity. `language` is
 * MUTABLE metadata — the dominant detected language by chunk duration —
 * while per-chunk languages live in the segments. The sync chunk writes
 * the row first; the extension job appends and re-stitches.
 */
class SpeechTranscriptWriterTest extends TestCase
{
    use RefreshDatabase;

    private function writer(): SpeechTranscriptWriter
    {
        return app(SpeechTranscriptWriter::class);
    }

    private function chunk(int $ordinal, string $text, ?string $language = 'de-DE', int $durationMs = 55000): ChunkTranscript
    {
        return new ChunkTranscript(
            ordinal: $ordinal,
            offsetMs: $ordinal * 55000,
            durationMs: $durationMs,
            text: $text,
            languageCode: $language,
            confidence: 0.9,
        );
    }

    public function test_the_sync_chunk_creates_one_available_speech_row(): void
    {
        $item = ContentItem::factory()->create();

        $row = $this->writer()->apply($item, [$this->chunk(0, 'hallo und willkommen')])->fresh();

        $this->assertSame($item->id, $row->content_item_id);
        $this->assertSame(SourceRegistry::GOOGLE_SPEECH_TO_TEXT, $row->provider);
        $this->assertSame(ContentTranscript::STATUS_AVAILABLE, $row->status);
        $this->assertSame('de-DE', $row->language);
        $this->assertSame('hallo und willkommen', $row->text);
        $this->assertSame(hash('sha256', 'hallo und willkommen'), $row->checksum);
        // Postgres jsonb normalizes object-key order (length, then bytewise),
        // so compare the single segment key-order-independently while keeping
        // strict-type value checks: it is exactly these five key/value pairs.
        $this->assertCount(1, $row->segments);
        $segment = $row->segments[0];
        ksort($segment);
        $expected = [
            'start' => '0.000',
            'dur' => '55.000',
            'text' => 'hallo und willkommen',
            'language' => 'de-DE',
            'chunk' => 0,
        ];
        ksort($expected);
        $this->assertSame($expected, $segment);
        $this->assertSame(SourceRegistry::GOOGLE_SPEECH_TO_TEXT, $row->provenance->source);
        $this->assertSame('google-speech-to-text-v2', $row->provenance->sourceVersion);
    }

    public function test_extension_chunks_append_re_stitch_and_flip_the_dominant_language(): void
    {
        $item = ContentItem::factory()->create();
        $this->writer()->apply($item, [$this->chunk(0, 'hallo und willkommen', 'de-DE')]);

        $row = $this->writer()->apply($item, [
            $this->chunk(1, 'now switching to english', 'en-US'),
            $this->chunk(2, 'still in english here', 'en-US'),
        ])->fresh();

        $this->assertSame(1, ContentTranscript::query()->count());
        $this->assertSame('hallo und willkommen now switching to english still in english here', $row->text);
        $this->assertSame([0, 1, 2], array_column($row->segments, 'chunk'));
        $this->assertSame(['0.000', '55.000', '110.000'], array_column($row->segments, 'start'));
        $this->assertSame(['de-DE', 'en-US', 'en-US'], array_column($row->segments, 'language'));
        // en-US now carries 110 s vs de-DE's 55 s: the dominant language
        // MUTATES on the same row — the reason Task 3 dropped `language`
        // from the unique key (no stale language-keyed duplicate).
        $this->assertSame('en-US', $row->language);
        $this->assertSame(hash('sha256', $row->text), $row->checksum);
    }

    public function test_re_transcribing_an_ordinal_replaces_its_segment_instead_of_duplicating(): void
    {
        $item = ContentItem::factory()->create();
        $this->writer()->apply($item, [$this->chunk(0, 'first pass wording')]);

        $row = $this->writer()->apply($item, [$this->chunk(0, 'second pass wording')])->fresh();

        $this->assertCount(1, $row->segments);
        $this->assertSame('second pass wording', $row->text);
    }

    public function test_dominant_language_ties_break_to_the_smallest_code(): void
    {
        $item = ContentItem::factory()->create();

        $row = $this->writer()->apply($item, [
            $this->chunk(0, 'english part', 'en-US'),
            $this->chunk(1, 'deutscher teil', 'de-DE'),
        ])->fresh();

        // 55 s each: deterministic tie-break to the lexicographically
        // smallest code, never insertion order.
        $this->assertSame('de-DE', $row->language);
    }

    public function test_a_null_language_chunk_lands_as_und(): void
    {
        $item = ContentItem::factory()->create();

        $row = $this->writer()->apply($item, [$this->chunk(0, 'mystery audio', null)])->fresh();

        $this->assertSame('und', $row->language);
        $this->assertSame('und', $row->segments[0]['language']);
    }

    public function test_the_youtube_transcript_row_is_never_touched(): void
    {
        $item = ContentItem::factory()->create();
        $youtube = ContentTranscript::query()->create([
            'content_item_id' => $item->id,
            'language' => 'und',
            'status' => ContentTranscript::STATUS_AVAILABLE,
            'text' => 'youtube captions',
            'segments' => [['start' => '0', 'dur' => '1', 'text' => 'youtube captions']],
            'provider' => SourceRegistry::APIFY_YOUTUBE_TRANSCRIPT,
            'provenance' => new Provenance(SourceRegistry::APIFY_YOUTUBE_TRANSCRIPT, CarbonImmutable::now(), 'youtube-transcript-v1'),
            'checksum' => hash('sha256', 'youtube captions'),
            'fetched_at' => CarbonImmutable::now(),
        ]);

        $speech = $this->writer()->apply($item, [$this->chunk(0, 'spoken words')]);

        $this->assertSame(2, ContentTranscript::query()->count());
        $this->assertNotSame($youtube->id, $speech->id);
        $this->assertSame('youtube captions', $youtube->fresh()->text);
        $this->assertSame('und', $youtube->fresh()->language);
    }

    public function test_a_speech_row_is_found_by_item_and_provider_regardless_of_its_stored_language(): void
    {
        // Narrowed identity (Task 3): the row is matched WITHOUT language,
        // so a dominant-language shift can never strand a stale partial
        // row under the old language value.
        $item = ContentItem::factory()->create();
        $first = $this->writer()->apply($item, [$this->chunk(0, 'deutscher anfang', 'de-DE')]);

        $second = $this->writer()->apply($item, [
            $this->chunk(1, 'long english part', 'en-US', 200_000),
        ]);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, ContentTranscript::query()->where('provider', SourceRegistry::GOOGLE_SPEECH_TO_TEXT)->count());
        $this->assertSame('en-US', $second->fresh()->language);
    }

    public function test_apply_with_no_chunks_is_a_programming_error(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->writer()->apply(ContentItem::factory()->create(), []);
    }
}
