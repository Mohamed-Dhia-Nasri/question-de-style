<?php

namespace Tests\Feature\Enrichment;

use App\Modules\Monitoring\Models\SpeechAudioChunk;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Chunk-artifact orphan prune (sub-project D, spec §8.3/§12): rows +
 * blobs older than chunk_orphan_days were left behind by a failure
 * (the job deletes chunks on success) — prune them whatever their
 * status. The window is GLOBAL operational config, not per-tenant
 * retention (transient working data, not an archive), and the command
 * runs tenant-less like every scheduler prune.
 */
class PruneAudioChunksCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'qds.ingestion.media_disk' => 'media',
            'qds.enrichment.speech.chunk_orphan_days' => 7,
        ]);
        Storage::fake('media');
    }

    /** @param array<string, mixed> $attributes */
    private function makeChunk(array $attributes): SpeechAudioChunk
    {
        $chunk = SpeechAudioChunk::factory()->create(array_merge([
            'storage_disk' => 'media',
        ], $attributes));

        Storage::disk('media')->put((string) $chunk->storage_path, 'fLaC-fake');

        return $chunk;
    }

    public function test_prunes_rows_and_blobs_older_than_the_orphan_window(): void
    {
        $old = $this->makeChunk([
            'ordinal' => 1,
            'storage_path' => 'tenants/1/audio-chunks/instagram/11/1.flac',
            'status' => 'pending',
            'created_at' => CarbonImmutable::now()->subDays(8),
        ]);
        $oldTranscribed = $this->makeChunk([
            'ordinal' => 2,
            'storage_path' => 'tenants/1/audio-chunks/instagram/12/2.flac',
            'status' => 'transcribed',
            'created_at' => CarbonImmutable::now()->subDays(8),
        ]);
        $fresh = $this->makeChunk([
            'ordinal' => 3,
            'storage_path' => 'tenants/1/audio-chunks/instagram/13/3.flac',
            'status' => 'pending',
            'created_at' => CarbonImmutable::now()->subDays(6),
        ]);

        $this->artisan('qds:prune-audio-chunks')
            ->expectsOutputToContain('Pruned 2 orphaned speech audio chunks')
            ->assertExitCode(0);

        // Past the window: gone whatever the status — a >7-day-old chunk
        // is an orphan by definition (success deletes within minutes).
        $this->assertDatabaseMissing('speech_audio_chunks', ['id' => $old->id]);
        $this->assertDatabaseMissing('speech_audio_chunks', ['id' => $oldTranscribed->id]);
        Storage::disk('media')->assertMissing('tenants/1/audio-chunks/instagram/11/1.flac');
        Storage::disk('media')->assertMissing('tenants/1/audio-chunks/instagram/12/2.flac');

        // Inside the window: untouched.
        $this->assertDatabaseHas('speech_audio_chunks', ['id' => $fresh->id]);
        Storage::disk('media')->assertExists('tenants/1/audio-chunks/instagram/13/3.flac');
    }

    public function test_prunes_across_tenants_without_a_tenant_context(): void
    {
        $other = $this->makeTenant('Other Workspace');

        $mine = $this->makeChunk([
            'ordinal' => 1,
            'storage_path' => 'tenants/1/audio-chunks/instagram/21/1.flac',
            'created_at' => CarbonImmutable::now()->subDays(8),
        ]);
        $theirs = $this->withTenant($other, fn (): SpeechAudioChunk => $this->makeChunk([
            'ordinal' => 1,
            'storage_path' => 'tenants/2/audio-chunks/instagram/22/1.flac',
            'created_at' => CarbonImmutable::now()->subDays(8),
        ]));

        $this->artisan('qds:prune-audio-chunks')->assertExitCode(0);

        // The scheduler runs tenant-less: BOTH workspaces' orphans go.
        $this->assertSame(
            0,
            SpeechAudioChunk::query()->withoutGlobalScopes()
                ->whereIn('id', [$mine->id, $theirs->id])
                ->count(),
        );
    }
}
