<?php

namespace Tests\Feature\Enrichment;

use App\Modules\Monitoring\Models\ContentItem;
use App\Modules\Monitoring\Models\SpeechAudioChunk;
use App\Modules\Monitoring\Models\Story;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * speech_audio_chunks (sub-project D, spec §8.3): persisted extension-
 * chunk artifacts consumed by TranscribeExtendedAudioJob. Ordinals are
 * 1-based — chunk 0 is the in-pipeline sync pass and is never persisted.
 * Rows + blobs are deleted after successful transcription; the daily
 * orphan prune and CreatorEraser (later tasks) are the backstops.
 */
class SpeechAudioChunkTest extends TestCase
{
    use RefreshDatabase;

    public function test_factory_persists_a_tenant_stamped_pending_chunk(): void
    {
        $chunk = SpeechAudioChunk::factory()->create();

        $chunk->refresh();

        $this->assertNotNull($chunk->tenant_id);
        $this->assertSame(SpeechAudioChunk::STATUS_PENDING, $chunk->status);
        $this->assertSame(ContentItem::class, $chunk->owner_type);
        $this->assertInstanceOf(ContentItem::class, $chunk->owner);
        $this->assertSame(55_000, $chunk->duration_ms);
        $this->assertGreaterThanOrEqual(1, $chunk->ordinal);
        $this->assertNotNull($chunk->created_at);
        $this->assertNotNull($chunk->updated_at);
    }

    public function test_for_owner_attaches_to_a_story(): void
    {
        $story = Story::factory()->create();
        $chunk = SpeechAudioChunk::factory()->forOwner($story)->create(['ordinal' => 1]);

        $this->assertSame($story->getMorphClass(), $chunk->owner_type);
        $this->assertSame($story->id, $chunk->owner_id);
        $this->assertInstanceOf(Story::class, $chunk->fresh()->owner);
    }

    public function test_transcribed_state_marks_the_chunk_done(): void
    {
        $chunk = SpeechAudioChunk::factory()->transcribed()->create();

        $this->assertSame(SpeechAudioChunk::STATUS_TRANSCRIBED, $chunk->status);
    }

    public function test_ordinals_are_unique_per_owner(): void
    {
        $item = ContentItem::factory()->create();
        SpeechAudioChunk::factory()->forOwner($item)->create(['ordinal' => 1]);

        $this->expectException(UniqueConstraintViolationException::class);
        SpeechAudioChunk::factory()->forOwner($item)->create(['ordinal' => 1]);
    }

    public function test_chunk_zero_is_rejected_by_the_one_based_check(): void
    {
        $this->expectException(QueryException::class);

        // Chunk 0 is the in-pipeline sync pass — never persisted.
        SpeechAudioChunk::factory()->create(['ordinal' => 0]);
    }

    public function test_unknown_status_is_rejected(): void
    {
        $this->expectException(QueryException::class);

        SpeechAudioChunk::factory()->create(['status' => 'uploading']);
    }
}
