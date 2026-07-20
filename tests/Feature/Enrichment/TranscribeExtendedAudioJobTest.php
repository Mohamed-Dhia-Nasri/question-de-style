<?php

namespace Tests\Feature\Enrichment;

use App\Modules\CRM\Models\Brand;
use App\Modules\CRM\Models\Creator;
use App\Modules\CRM\Models\PlatformAccount;
use App\Modules\CRM\Models\Product;
use App\Modules\CRM\Models\SeedingCampaign;
use App\Modules\CRM\Models\Shipment;
use App\Modules\Monitoring\Models\ContentItem;
use App\Modules\Monitoring\Models\ContentTranscript;
use App\Modules\Monitoring\Models\Mention;
use App\Modules\Monitoring\Models\MonitoredSubject;
use App\Modules\Monitoring\Models\RecognitionDetection;
use App\Modules\Monitoring\Models\SpeechAudioChunk;
use App\Modules\Monitoring\Models\Story;
use App\Platform\AiBudget\AiBudgetGuard;
use App\Platform\AiBudget\Models\AiUsageCounter;
use App\Platform\Enrichment\Recognition\AudioChunker;
use App\Platform\Enrichment\Speech\ChunkTranscript;
use App\Platform\Enrichment\Speech\Jobs\TranscribeExtendedAudioJob;
use App\Platform\Enrichment\Speech\SpeechAudioChunkWriter;
use App\Platform\Enrichment\Speech\SpeechTranscriptWriter;
use App\Platform\Ingestion\Exceptions\ProviderCallException;
use App\Platform\Ingestion\Models\ProviderCall;
use App\Platform\Ingestion\Models\ProviderHealthState;
use App\Platform\Ingestion\SourceRegistry;
use App\Platform\Ingestion\Support\CallOutcome;
use App\Platform\Ingestion\Support\ErrorCategory;
use App\Platform\Ingestion\Support\ProviderStatus;
use App\Shared\Enums\ContentType;
use App\Shared\Enums\Platform;
use App\Shared\Enums\SeedingCampaignStatus;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Sub-project D (spec §9/§10): async transcription of the persisted
 * extension chunks — per chunk budget-guarded v2 recognize, SPOKEN_BRAND
 * mining, transcript append + re-stitch, row+blob deletion, then ONE
 * attribution re-classification. Transient failure leaves chunks pending
 * (release/backoff); permanent failure marks THAT chunk failed and keeps
 * going. Fail-closed everywhere; no real network (Http::fake).
 */
class TranscribeExtendedAudioJobTest extends TestCase
{
    use RefreshDatabase;

    private const MEDIA_URL = 'https://93.184.216.34/video-1.mp4';

    /** @var list<string> */
    private array $cleanupPaths = [];

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('media');
    }

    protected function tearDown(): void
    {
        foreach ($this->cleanupPaths as $path) {
            @unlink($path);
        }

        parent::tearDown();
    }

    private function enableSpeechV2(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'qds-test-sa-');
        $this->assertIsString($path);
        $this->cleanupPaths[] = $path;
        file_put_contents($path, '{"client_email":"qds-speech@qds-speech-test.iam.gserviceaccount.com"}');

        config([
            'qds.enrichment.speech.v2_enabled' => true,
            'services.google_speech_v2.credentials_path' => $path,
            'services.google_speech_v2.project_id' => 'qds-speech-test',
        ]);

        Cache::put('qds:google-speech-v2-token', 'test-bearer-token', 3540);
    }

    private function brand(): Brand
    {
        return Brand::factory()->create([
            'name' => 'Maison Lumière',
            'aliases' => ['lumiere', '@maisonlumiere'],
        ]);
    }

    /** @return array{0: Creator, 1: ContentItem} */
    private function creatorReel(): array
    {
        $creator = Creator::factory()->create();
        $account = PlatformAccount::factory()->forCreator($creator)->onPlatform(Platform::Instagram)->create();
        $item = ContentItem::factory()->for($account, 'platformAccount')->create([
            'content_type' => ContentType::Reel,
            'platform' => Platform::Instagram,
            'media_urls' => [self::MEDIA_URL],
            'published_at' => CarbonImmutable::parse('2026-07-15 12:00:00'),
        ]);

        return [$creator, $item];
    }

    private function pendingChunk(ContentItem|Story $target, int $ordinal): SpeechAudioChunk
    {
        // Through the Task 19 writer: real row + real blob on the fake disk.
        return app(SpeechAudioChunkWriter::class)->persist(
            $target,
            $ordinal,
            $ordinal * 55000,
            55000,
            'fake-flac-bytes-'.$ordinal,
        );
    }

    private function seedChunkZeroTranscript(ContentItem $item): void
    {
        app(SpeechTranscriptWriter::class)->apply($item, [new ChunkTranscript(
            ordinal: 0,
            offsetMs: 0,
            durationMs: 55000,
            text: 'hallo und willkommen',
            languageCode: 'de-DE',
            confidence: 0.9,
        )]);
    }

    /** The REAL v2 recognize response shape (spec §2b.9). */
    private function v2Response(string $transcript, ?float $confidence = 0.92, string $language = 'en-US'): array
    {
        return ['results' => [[
            'alternatives' => [['transcript' => $transcript, 'confidence' => $confidence]],
            'languageCode' => $language,
        ]]];
    }

    private function runJob(ContentItem|Story $target, string $type = 'content'): void
    {
        $job = new TranscribeExtendedAudioJob($type, $target->id, 'corr-ext');
        app()->call([$job, 'handle']);
    }

    private function euSpeechCallCount(): int
    {
        return count(Http::recorded(
            fn (Request $request): bool => str_contains($request->url(), 'eu-speech.googleapis.com'),
        ));
    }

    public function test_pending_chunks_are_transcribed_mined_stitched_and_deleted(): void
    {
        $this->brand();
        [, $item] = $this->creatorReel();
        $this->enableSpeechV2();
        $this->seedChunkZeroTranscript($item);
        $chunk1 = $this->pendingChunk($item, 1);
        $chunk2 = $this->pendingChunk($item, 2);
        Http::fake(['eu-speech.googleapis.com/*' => Http::sequence()
            ->push($this->v2Response('the new lumiere palette in english', 0.91))
            ->push($this->v2Response('thanks for watching everyone', 0.95)),
        ]);

        $this->runJob($item);

        // Chunk 1 named the brand → its own deterministic detection row;
        // chunk 2 is free text → no detection (lexicon gate).
        $detection = RecognitionDetection::query()->sole()->fresh();
        $this->assertSame('speech-chunk:1:maison-lumiere', $detection->provider_label);
        $this->assertSame('Maison Lumière', $detection->detected_brand);
        $this->assertSame($item->tenant_id, $detection->tenant_id);
        $this->assertSame('google-speech-to-text-v2', $detection->provenance->sourceVersion);

        // Transcript: appended + re-stitched, dominant language flipped
        // (de-DE 55 s vs en-US 110 s).
        $transcript = ContentTranscript::query()->sole()->fresh();
        $this->assertSame([0, 1, 2], array_column($transcript->segments, 'chunk'));
        $this->assertSame('hallo und willkommen the new lumiere palette in english thanks for watching everyone', $transcript->text);
        $this->assertSame('en-US', $transcript->language);

        // Rows + blobs deleted after successful transcription (spec §8.3).
        $this->assertSame(0, SpeechAudioChunk::query()->count());
        Storage::disk('media')->assertMissing($chunk1->storage_path);
        Storage::disk('media')->assertMissing($chunk2->storage_path);

        // 2 billed units; the POST was counted by the sync pass, not here.
        $counter = AiUsageCounter::query()->where('capability', 'speech_transcription')->sole();
        $this->assertSame(2, $counter->units);
        $this->assertSame(0, $counter->posts_processed);

        $this->assertSame(2, ProviderCall::query()
            ->where('source', SourceRegistry::GOOGLE_SPEECH_TO_TEXT)
            ->where('outcome', CallOutcome::Success->value)
            ->count());
    }

    public function test_transient_failure_leaves_chunks_pending_for_retry(): void
    {
        $this->brand();
        [, $item] = $this->creatorReel();
        $this->enableSpeechV2();
        $chunk1 = $this->pendingChunk($item, 1);
        $this->pendingChunk($item, 2);
        Http::fake(['eu-speech.googleapis.com/*' => Http::response('slow down', 429, ['Retry-After' => '30'])]);

        try {
            $this->runJob($item);
        } catch (ProviderCallException $e) {
            // Without a queue connection release() is a no-op; whether the
            // failure surfaces as release or rethrow, the STATE contract
            // below is what matters.
            $this->assertSame(ErrorCategory::RateLimited, $e->category);
        }

        // Loop stopped at the first transient failure: both chunks stay
        // pending, blobs intact, exactly one attempt billed conservatively.
        $this->assertSame(2, SpeechAudioChunk::query()->where('status', 'pending')->count());
        Storage::disk('media')->assertExists($chunk1->storage_path);
        $this->assertSame(1, $this->euSpeechCallCount());
        $this->assertSame(1, AiUsageCounter::query()->where('capability', 'speech_transcription')->sole()->units);
        $this->assertSame(0, RecognitionDetection::query()->count());
    }

    public function test_permanent_failure_marks_that_chunk_failed_and_continues(): void
    {
        $this->brand();
        [, $item] = $this->creatorReel();
        $this->enableSpeechV2();
        $chunk1 = $this->pendingChunk($item, 1);
        $this->pendingChunk($item, 2);
        Http::fake(['eu-speech.googleapis.com/*' => Http::sequence()
            ->push(['error' => ['status' => 'PERMISSION_DENIED']], 403)
            ->push($this->v2Response('die lumiere palette', 0.9)),
        ]);

        $this->runJob($item);

        // Chunk 1: permanent → failed, blob KEPT for the orphan prune
        // (never silently re-billed); chunk 2 still got its shot.
        $failed = SpeechAudioChunk::query()->sole();
        $this->assertSame(1, $failed->ordinal);
        $this->assertSame('failed', $failed->status);
        Storage::disk('media')->assertExists($chunk1->storage_path);

        $this->assertSame('speech-chunk:2:maison-lumiere', RecognitionDetection::query()->sole()->provider_label);
        $this->assertSame(2, AiUsageCounter::query()->where('capability', 'speech_transcription')->sole()->units);
    }

    public function test_the_per_post_budget_ceiling_binds_cumulatively_across_chunks(): void
    {
        $this->brand();
        [, $item] = $this->creatorReel(); // empty candidate set → MEDIUM priority
        $this->enableSpeechV2();
        config(['qds.ai_budget.capabilities.speech_transcription.per_post_units' => 2]);
        $this->pendingChunk($item, 1);
        $this->pendingChunk($item, 2);
        Http::fake(['eu-speech.googleapis.com/*' => Http::response($this->v2Response('kein markenname'))]);

        $this->runJob($item);

        // Chunk 1 asks allows(ordinal+1 = 2) → within the ceiling; chunk 2
        // asks allows(3) → 3 > 2 → deny: the cumulative-units pattern makes
        // per_post_units actually bind (a flat allows(1) never would).
        $this->assertSame(1, $this->euSpeechCallCount());
        $this->assertSame([2], SpeechAudioChunk::query()->pluck('ordinal')->all()); // chunk 2 still pending

        $counter = AiUsageCounter::query()->where('capability', 'speech_transcription')->sole();
        $this->assertSame(1, $counter->units);
        $this->assertSame(1, $counter->posts_skipped_budget);
    }

    public function test_read_only_mode_stops_without_recording_anything(): void
    {
        $this->brand();
        [, $item] = $this->creatorReel();
        $this->enableSpeechV2();
        Cache::put(AiBudgetGuard::READ_ONLY_CACHE_KEY, true);
        $this->pendingChunk($item, 1);
        Http::fake(['eu-speech.googleapis.com/*' => Http::response($this->v2Response('nie gesendet'))]);

        $this->runJob($item);

        $this->assertSame(0, $this->euSpeechCallCount());
        $this->assertSame(0, AiUsageCounter::query()->count());
        $this->assertSame(1, SpeechAudioChunk::query()->where('status', 'pending')->count());
    }

    public function test_open_circuit_breaker_stops_before_spending(): void
    {
        config(['qds.ingestion.circuit_breaker.enabled' => true, 'qds.ingestion.circuit_breaker.cooldown_minutes' => 60]);
        ProviderHealthState::query()->create([
            'source' => SourceRegistry::GOOGLE_SPEECH_TO_TEXT,
            'status' => ProviderStatus::Failing,
            'consecutive_failures' => 3,
            'last_failure_at' => CarbonImmutable::now()->subMinutes(5),
            'last_error_category' => ErrorCategory::Authentication,
        ]);
        $this->brand();
        [, $item] = $this->creatorReel();
        $this->enableSpeechV2();
        $this->pendingChunk($item, 1);
        Http::fake(['eu-speech.googleapis.com/*' => Http::response($this->v2Response('nie gesendet'))]);

        $this->runJob($item);

        $this->assertSame(0, $this->euSpeechCallCount());
        $this->assertSame(1, SpeechAudioChunk::query()->where('status', 'pending')->count());
        $this->assertSame(0, AiUsageCounter::query()->count());
    }

    public function test_kill_switch_off_is_a_true_no_op(): void
    {
        $this->brand();
        [, $item] = $this->creatorReel();
        // v2_enabled stays at its default (false); chunks left for the prune.
        $this->pendingChunk($item, 1);
        Http::fake(['eu-speech.googleapis.com/*' => Http::response($this->v2Response('nie gesendet'))]);

        $this->runJob($item);

        $this->assertSame(0, $this->euSpeechCallCount());
        $this->assertSame(1, SpeechAudioChunk::query()->where('status', 'pending')->count());
    }

    public function test_unconfigured_v2_is_a_no_op(): void
    {
        $this->brand();
        [, $item] = $this->creatorReel();
        config(['qds.enrichment.speech.v2_enabled' => true]); // on but unconfigured
        $this->pendingChunk($item, 1);
        Http::fake(['eu-speech.googleapis.com/*' => Http::response($this->v2Response('nie gesendet'))]);

        $this->runJob($item);

        $this->assertSame(0, $this->euSpeechCallCount());
        $this->assertSame(1, SpeechAudioChunk::query()->where('status', 'pending')->count());
    }

    public function test_story_targets_mine_detections_without_a_transcript_row(): void
    {
        $this->brand();
        $creator = Creator::factory()->create();
        $account = PlatformAccount::factory()->forCreator($creator)->create();
        $story = Story::factory()->for($account, 'platformAccount')->create();
        $this->enableSpeechV2();
        $this->pendingChunk($story, 1);
        Http::fake(['eu-speech.googleapis.com/*' => Http::response($this->v2Response('die lumiere palette', 0.9))]);

        $this->runJob($story, 'story');

        $detection = RecognitionDetection::query()->sole();
        $this->assertSame($story->id, $detection->story_id);
        $this->assertSame('speech-chunk:1:maison-lumiere', $detection->provider_label);
        // Stories: detections-only — no transcript table for stories (spec §16).
        $this->assertSame(0, ContentTranscript::query()->count());
        $this->assertSame(0, SpeechAudioChunk::query()->count());
    }

    public function test_attribution_reclassifies_once_after_the_last_chunk(): void
    {
        $brand = $this->brand();
        [$creator, $item] = $this->creatorReel();
        MonitoredSubject::factory()->create([
            'creator_id' => $creator->id,
            'platforms' => [Platform::Instagram],
            'active' => true,
        ]);
        // In-window shipment of the SAME brand: spoken brand + timing evidence.
        $product = Product::factory()->create(['name' => 'Lumière Palette', 'brand_id' => $brand->id]);
        $campaign = SeedingCampaign::factory()->create([
            'brand_id' => $brand->id,
            'status' => SeedingCampaignStatus::Completed,
        ]);
        Shipment::factory()->create([
            'seeding_campaign_id' => $campaign->id,
            'creator_id' => $creator->id,
            'product_id' => $product->id,
            'shipped_at' => CarbonImmutable::parse('2026-07-10 12:00:00'),
            'delivered_at' => CarbonImmutable::parse('2026-07-12 12:00:00'),
        ]);
        $this->enableSpeechV2();
        $this->pendingChunk($item, 1);
        Http::fake(['eu-speech.googleapis.com/*' => Http::response($this->v2Response('heute die lumiere palette', 0.9))]);

        $this->assertSame(0, Mention::query()->count());

        $this->runJob($item);

        // The re-classification ran inside the same tenant context (the
        // visual-match backfill precedent) and produced a mention.
        $this->assertTrue(Mention::query()->where('content_item_id', $item->id)->exists());
    }

    public function test_unique_id_and_queue_follow_the_frozen_contract(): void
    {
        config(['qds.enrichment.speech.queue' => 'enrichment']);

        $job = new TranscribeExtendedAudioJob('content', 42);

        $this->assertSame('speech-ext:content:42', $job->uniqueId());
        $this->assertSame('enrichment', $job->queue);
        $this->assertSame(4, $job->tries);
        $this->assertSame(300, $job->timeout);
    }

    public function test_the_recognition_stage_dispatches_the_job_for_queued_chunks(): void
    {
        if (! app(AudioChunker::class)->isAvailable()) {
            $this->markTestSkipped('ffmpeg is not installed on this host.');
        }

        Queue::fake();
        config(['services.google_vision.api_key' => 'test-vision-key']);
        $this->brand();
        [$creator, $item] = $this->creatorReel();
        $product = Product::factory()->create(['name' => 'Nexon Headset']);
        $campaign = SeedingCampaign::factory()->create([
            'brand_id' => $product->brand_id,
            'status' => SeedingCampaignStatus::Completed,
        ]);
        Shipment::factory()->create([
            'seeding_campaign_id' => $campaign->id,
            'creator_id' => $creator->id,
            'product_id' => $product->id,
            'shipped_at' => CarbonImmutable::parse('2026-07-10 12:00:00'),
            'delivered_at' => CarbonImmutable::parse('2026-07-12 12:00:00'),
        ]);
        $this->enableSpeechV2();
        config(['qds.enrichment.speech.chunk_seconds' => 2]);

        $out = tempnam(sys_get_temp_dir(), 'qds-video-fixture-');
        $this->assertNotFalse($out);
        $this->cleanupPaths[] = $out;
        Process::timeout(60)->run([
            (string) config('qds.enrichment.audio.ffmpeg_path', 'ffmpeg'),
            '-nostdin', '-v', 'error',
            '-f', 'lavfi', '-i', 'testsrc=duration=5:size=64x64:rate=10',
            '-f', 'lavfi', '-i', 'sine=frequency=440:duration=5',
            '-pix_fmt', 'yuv420p', '-shortest', '-f', 'mp4', '-y', $out,
        ])->throw();
        $videoBytes = file_get_contents($out);
        $this->assertIsString($videoBytes);

        Http::fake([
            '93.184.216.34/*' => Http::response($videoBytes, 200, ['Content-Type' => 'video/mp4']),
            'eu-speech.googleapis.com/*' => Http::response($this->v2Response('kein markenname hier')),
        ]);

        $result = app(\App\Platform\Enrichment\Recognition\RecognitionService::class)->enrich($item, 'corr-1');

        $this->assertContains('speech:chunks-queued=2', $result['skipped']);
        Queue::assertPushed(TranscribeExtendedAudioJob::class, function (TranscribeExtendedAudioJob $job) use ($item): bool {
            return $job->targetType === 'content'
                && $job->targetId === $item->id
                && $job->correlationId === 'corr-1'
                && $job->queue === 'enrichment';
        });
    }
}
