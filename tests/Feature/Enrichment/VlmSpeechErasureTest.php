<?php

namespace Tests\Feature\Enrichment;

use App\Modules\CRM\Models\Creator;
use App\Modules\CRM\Models\PlatformAccount;
use App\Modules\CRM\Services\Gdpr\CreatorEraser;
use App\Modules\Monitoring\Models\ContentItem;
use App\Modules\Monitoring\Models\ContentTranscript;
use App\Modules\Monitoring\Models\SpeechAudioChunk;
use App\Modules\Monitoring\Models\VisualMatchRun;
use App\Modules\Monitoring\Models\VlmCandidateVerdict;
use App\Modules\Monitoring\Models\VlmVerificationRun;
use App\Platform\Ingestion\SourceRegistry;
use App\Shared\Enums\VlmRunOutcome;
use App\Shared\Enums\VlmTriggerReason;
use App\Shared\ValueObjects\Provenance;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * GDPR coverage for sub-project D's derived artifacts (spec §12): VLM
 * verification runs/verdicts and persisted speech audio chunks are personal
 * data anchored to a creator's content and must die with the creator, blobs
 * included. The daily qds:prune-audio-chunks backstop bounds how long an
 * orphaned chunk blob can outlive its transcription.
 */
class VlmSpeechErasureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('media');
        Storage::fake('exports');
    }

    public function test_erasure_removes_vlm_runs_and_cascading_verdicts(): void
    {
        $creator = Creator::factory()->create();
        $account = PlatformAccount::factory()->for($creator)->create();
        $item = ContentItem::factory()->for($account, 'platformAccount')->create();

        $anchor = VisualMatchRun::factory()->create([
            'content_item_id' => $item->id,
            'needs_verification' => true,
        ]);
        $run = VlmVerificationRun::factory()->create([
            'content_item_id' => $item->id,
            'visual_match_run_id' => $anchor->id,
            'trigger_reason' => VlmTriggerReason::ReviewBand,
            'outcome' => VlmRunOutcome::Confirmed,
        ]);
        VlmCandidateVerdict::factory()->count(2)->sequence(['rank' => 1], ['rank' => 2])
            ->create(['vlm_verification_run_id' => $run->id]);
        // DEF-021 discovery rows have NO anchor run — they must be erased too.
        VlmVerificationRun::factory()->create([
            'content_item_id' => $item->id,
            'visual_match_run_id' => null,
            'trigger_reason' => VlmTriggerReason::UnverifiableNoRun,
            'outcome' => VlmRunOutcome::Unverifiable,
        ]);

        $counts = app(CreatorEraser::class)->erase($creator);

        $this->assertSame(2, $counts['vlm_verification_runs']);
        $this->assertSame(0, VlmVerificationRun::query()->withoutGlobalScopes()->count());
        // Verdicts cascade from runs at the DB — no separate delete-list entry.
        $this->assertSame(0, VlmCandidateVerdict::query()->withoutGlobalScopes()->count());
    }

    public function test_erasure_removes_speech_chunks_rows_blobs_and_speech_transcripts(): void
    {
        config(['qds.ingestion.media_disk' => 'media']);

        $creator = Creator::factory()->create();
        $account = PlatformAccount::factory()->for($creator)->create();
        $item = ContentItem::factory()->for($account, 'platformAccount')->create();

        $path = "tenants/{$item->tenant_id}/audio-chunks/instagram/{$item->id}/1.flac";
        Storage::disk('media')->put($path, 'FLAC');
        SpeechAudioChunk::query()->create([
            'owner_type' => $item->getMorphClass(),
            'owner_id' => $item->id,
            'ordinal' => 1,
            'offset_ms' => 55000,
            'duration_ms' => 55000,
            'storage_disk' => 'media',
            'storage_path' => $path,
            'byte_size' => 4,
            'checksum' => str_repeat('a', 64),
            'status' => 'pending',
        ]);
        // D's speech path persists transcripts under the speech provider —
        // the EXISTING content_transcripts eraser line must cover them.
        ContentTranscript::query()->create([
            'content_item_id' => $item->id,
            'language' => 'de-DE',
            'status' => ContentTranscript::STATUS_AVAILABLE,
            'text' => 'hallo welt',
            'provider' => SourceRegistry::GOOGLE_SPEECH_TO_TEXT,
            'provenance' => new Provenance(SourceRegistry::GOOGLE_SPEECH_TO_TEXT, CarbonImmutable::now(), 'google-speech-to-text-v2'),
            'checksum' => hash('sha256', 'hallo welt'),
            'fetched_at' => CarbonImmutable::now(),
        ]);

        $counts = app(CreatorEraser::class)->erase($creator);

        $this->assertSame(1, $counts['speech_audio_chunks']);
        $this->assertSame(1, $counts['speech_chunk_files']);
        $this->assertSame(1, $counts['content_transcripts']);
        $this->assertSame(0, SpeechAudioChunk::query()->withoutGlobalScopes()->count());
        $this->assertSame(0, ContentTranscript::query()->withoutGlobalScopes()->count());
        Storage::disk('media')->assertMissing($path);
    }

    public function test_orphan_prune_backstop_removes_aged_chunks_and_keeps_recent_ones(): void
    {
        config(['qds.ingestion.media_disk' => 'media']);
        config(['qds.enrichment.speech.chunk_orphan_days' => 7]);

        $creator = Creator::factory()->create();
        $account = PlatformAccount::factory()->for($creator)->create();
        $item = ContentItem::factory()->for($account, 'platformAccount')->create();

        $oldPath = "tenants/{$item->tenant_id}/audio-chunks/instagram/{$item->id}/1.flac";
        $newPath = "tenants/{$item->tenant_id}/audio-chunks/instagram/{$item->id}/2.flac";
        Storage::disk('media')->put($oldPath, 'OLD');
        Storage::disk('media')->put($newPath, 'NEW');
        $old = SpeechAudioChunk::query()->create([
            'owner_type' => $item->getMorphClass(),
            'owner_id' => $item->id,
            'ordinal' => 1,
            'offset_ms' => 55000,
            'duration_ms' => 55000,
            'storage_disk' => 'media',
            'storage_path' => $oldPath,
            'byte_size' => 3,
            'checksum' => str_repeat('b', 64),
            'status' => 'failed',
        ]);
        $new = SpeechAudioChunk::query()->create([
            'owner_type' => $item->getMorphClass(),
            'owner_id' => $item->id,
            'ordinal' => 2,
            'offset_ms' => 110000,
            'duration_ms' => 55000,
            'storage_disk' => 'media',
            'storage_path' => $newPath,
            'byte_size' => 3,
            'checksum' => str_repeat('c', 64),
            'status' => 'pending',
        ]);
        DB::table('speech_audio_chunks')->where('id', $old->id)
            ->update(['created_at' => now()->subDays(8)]);

        $this->artisan('qds:prune-audio-chunks')->assertExitCode(0);

        $this->assertNull(SpeechAudioChunk::query()->find($old->id));
        $this->assertNotNull(SpeechAudioChunk::query()->find($new->id));
        Storage::disk('media')->assertMissing($oldPath);
        Storage::disk('media')->assertExists($newPath);
    }
}
