<?php

namespace Tests\Feature\Enrichment;

use App\Modules\Monitoring\Models\ContentItem;
use App\Modules\Monitoring\Models\SpeechAudioChunk;
use App\Modules\Monitoring\Models\Story;
use App\Platform\Enrichment\Speech\SpeechAudioChunkWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Persisted extension-chunk artifacts (sub-project D, spec §8.3): the
 * async TranscribeExtendedAudioJob outlives the pipeline's video temp
 * file, so chunks 1..N are written to the private media disk while the
 * video still exists. Chunk 0 (the in-pipeline sync pass) is NEVER
 * persisted — ordinals are 1-based by contract. Rows + blobs are deleted
 * by the job after successful transcription; qds:prune-audio-chunks and
 * the CreatorEraser (Task 23) are the backstops.
 */
class SpeechAudioChunkWriterTest extends TestCase
{
    use RefreshDatabase;

    private SpeechAudioChunkWriter $writer;

    protected function setUp(): void
    {
        parent::setUp();

        config(['qds.ingestion.media_disk' => 'media']);
        Storage::fake('media');

        $this->writer = new SpeechAudioChunkWriter;
    }

    public function test_persists_a_pending_chunk_row_and_blob_under_the_tenant_path(): void
    {
        $item = ContentItem::factory()->create();
        $bytes = 'fLaC-fake-chunk-bytes';

        $chunk = $this->writer->persist($item, 1, 55_000, 55_000, $bytes);

        $expectedPath = sprintf(
            'tenants/%d/audio-chunks/%s/%d/1.flac',
            $item->tenant_id,
            strtolower($item->platform->value),
            $item->id,
        );

        $this->assertSame($expectedPath, $chunk->storage_path);
        $this->assertSame('media', $chunk->storage_disk);
        $this->assertSame('pending', $chunk->status);
        $this->assertSame($item->getMorphClass(), $chunk->owner_type);
        $this->assertSame($item->id, $chunk->owner_id);
        $this->assertSame(1, $chunk->ordinal);
        $this->assertSame(55_000, $chunk->offset_ms);
        $this->assertSame(55_000, $chunk->duration_ms);
        $this->assertSame(strlen($bytes), $chunk->byte_size);
        $this->assertSame(hash('sha256', $bytes), $chunk->checksum);
        $this->assertSame($item->tenant_id, $chunk->tenant_id);

        Storage::disk('media')->assertExists($expectedPath);
        $this->assertSame($bytes, Storage::disk('media')->get($expectedPath));
    }

    public function test_persist_is_idempotent_on_owner_and_ordinal(): void
    {
        $item = ContentItem::factory()->create();

        $first = $this->writer->persist($item, 1, 55_000, 55_000, 'first-bytes');
        $second = $this->writer->persist($item, 1, 55_000, 55_000, 'second-bytes');

        // The existing row wins — no second row, and the ALREADY-persisted
        // blob is not overwritten.
        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, SpeechAudioChunk::query()->count());
        $this->assertSame('first-bytes', Storage::disk('media')->get($first->storage_path));
    }

    public function test_distinct_ordinals_persist_side_by_side(): void
    {
        $item = ContentItem::factory()->create();

        $one = $this->writer->persist($item, 1, 55_000, 55_000, 'chunk-one');
        $two = $this->writer->persist($item, 2, 110_000, 55_000, 'chunk-two');

        $this->assertNotSame($one->id, $two->id);
        $this->assertSame(2, SpeechAudioChunk::query()->count());
        $this->assertStringEndsWith('/1.flac', $one->storage_path);
        $this->assertStringEndsWith('/2.flac', $two->storage_path);
    }

    public function test_ordinal_zero_is_rejected(): void
    {
        $item = ContentItem::factory()->create();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('1-based');

        $this->writer->persist($item, 0, 0, 55_000, 'fLaC-fake');
    }

    public function test_empty_bytes_are_rejected(): void
    {
        $item = ContentItem::factory()->create();

        $this->expectException(InvalidArgumentException::class);

        $this->writer->persist($item, 1, 55_000, 55_000, '');
    }

    public function test_story_owners_get_their_own_path_and_morph_type(): void
    {
        $story = Story::factory()->create();

        $chunk = $this->writer->persist($story, 2, 110_000, 55_000, 'fLaC-story-bytes');

        $expectedPath = sprintf(
            'tenants/%d/audio-chunks/%s/%d/2.flac',
            $story->tenant_id,
            strtolower($story->platform->value),
            $story->id,
        );

        $this->assertSame($expectedPath, $chunk->storage_path);
        $this->assertSame($story->getMorphClass(), $chunk->owner_type);
        Storage::disk('media')->assertExists($expectedPath);
    }

    public function test_delete_chunk_removes_row_and_blob(): void
    {
        $item = ContentItem::factory()->create();
        $chunk = $this->writer->persist($item, 1, 55_000, 55_000, 'fLaC-fake');
        $path = $chunk->storage_path;

        $this->writer->deleteChunk($chunk);

        $this->assertDatabaseMissing('speech_audio_chunks', ['id' => $chunk->id]);
        Storage::disk('media')->assertMissing($path);
    }

    public function test_delete_chunk_is_safe_when_the_blob_is_already_gone(): void
    {
        $item = ContentItem::factory()->create();
        $chunk = $this->writer->persist($item, 1, 55_000, 55_000, 'fLaC-fake');
        Storage::disk('media')->delete($chunk->storage_path);

        $this->writer->deleteChunk($chunk);

        $this->assertDatabaseMissing('speech_audio_chunks', ['id' => $chunk->id]);
    }
}
