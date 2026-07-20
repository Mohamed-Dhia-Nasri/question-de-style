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
use App\Modules\Monitoring\Models\RecognitionDetection;
use App\Modules\Monitoring\Models\SpeechAudioChunk;
use App\Platform\AiBudget\AiBudgetGuard;
use App\Platform\AiBudget\Models\AiUsageCounter;
use App\Platform\Enrichment\Recognition\AudioChunker;
use App\Platform\Enrichment\Recognition\AudioExtractor;
use App\Platform\Enrichment\Recognition\RecognitionService;
use App\Platform\Ingestion\Models\ProviderCall;
use App\Platform\Ingestion\SourceRegistry;
use App\Platform\Ingestion\Support\CallOutcome;
use App\Shared\Enums\ContentType;
use App\Shared\Enums\RecognitionType;
use App\Shared\Enums\SeedingCampaignStatus;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Sub-project D (spec §9): the recognition stage's speech sub-path routed
 * through Speech-to-Text v2 — chunk-0 sync pass (multilingual, budget-
 * metered), candidate-bearing extension-chunk persistence, fail-closed
 * markers, and the v1-byte-identical-when-off characterization. Real
 * ffmpeg renders the fixtures (AudioExtractorTest pattern); the v2
 * endpoint is Http::fake'd — no real network (DP-005).
 */
class SpeechV2RecognitionTest extends TestCase
{
    use RefreshDatabase;

    private const MEDIA_URL = 'https://93.184.216.34/video-1.mp4';

    /** Public: the anonymous AudioExtractor stub below references it. */
    public const AUDIO_BYTES = 'fake-flac-bytes';

    /** @var list<string> */
    private array $cleanupPaths = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Vision configured (house baseline): the no-provider early gate
        // never hides the speech path; a reel has no images, so Vision is
        // never actually called.
        config(['services.google_vision.api_key' => 'test-vision-key']);
        Storage::fake('media');
    }

    protected function tearDown(): void
    {
        foreach ($this->cleanupPaths as $path) {
            @unlink($path);
        }

        parent::tearDown();
    }

    private function requireFfmpeg(): void
    {
        if (! app(AudioChunker::class)->isAvailable()) {
            $this->markTestSkipped('ffmpeg is not installed on this host.');
        }
    }

    private function enableSpeechV2(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'qds-test-sa-');
        $this->assertIsString($path);
        $this->cleanupPaths[] = $path;
        file_put_contents($path, '{"client_email":"qds-speech@qds-speech-test.iam.gserviceaccount.com"}');

        config([
            'qds.enrichment.speech.v2_enabled' => true,
            'qds.enrichment.speech.chunk_seconds' => 2, // tiny fixture windows
            'services.google_speech_v2.credentials_path' => $path,
            'services.google_speech_v2.project_id' => 'qds-speech-test',
        ]);

        // Pre-warmed bearer token (GeminiMultimodalEmbeddingProviderTest
        // pattern, Task 5 cache key): recognize never hits the OAuth
        // endpoint here.
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
        $account = PlatformAccount::factory()->forCreator($creator)->create();
        $item = ContentItem::factory()->for($account, 'platformAccount')->create([
            'content_type' => ContentType::Reel,
            'media_urls' => [self::MEDIA_URL],
            'published_at' => CarbonImmutable::parse('2026-07-15 12:00:00'),
        ]);

        return [$creator, $item];
    }

    /** An in-window shipment under a COMPLETED campaign: candidate-bearing at MEDIUM priority. */
    private function shipInWindow(Creator $creator): Product
    {
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

        return $product;
    }

    /** Synthetic MP4 with a sine audio track, rendered by local ffmpeg (AudioExtractorTest pattern). */
    private function makeVideo(int $seconds): string
    {
        $out = tempnam(sys_get_temp_dir(), 'qds-video-fixture-');
        $this->assertNotFalse($out);
        $this->cleanupPaths[] = $out;

        Process::timeout(60)->run([
            (string) config('qds.enrichment.audio.ffmpeg_path', 'ffmpeg'),
            '-nostdin', '-v', 'error',
            '-f', 'lavfi', '-i', sprintf('testsrc=duration=%d:size=64x64:rate=10', $seconds),
            '-f', 'lavfi', '-i', sprintf('sine=frequency=440:duration=%d', $seconds),
            '-pix_fmt', 'yuv420p', '-shortest', '-f', 'mp4', '-y', $out,
        ])->throw();

        $bytes = file_get_contents($out);
        $this->assertIsString($bytes);

        return $bytes;
    }

    /** The REAL v2 recognize response shape (spec §2b.9): results[].alternatives[] + per-result languageCode. */
    private function v2Response(string $transcript, ?float $confidence = 0.92, string $language = 'de-DE'): array
    {
        return ['results' => [[
            'alternatives' => [['transcript' => $transcript, 'confidence' => $confidence]],
            'languageCode' => $language,
        ]]];
    }

    /** @param array<string, mixed> $v2Response */
    private function fakeMediaAndSpeech(string $videoBytes, array $v2Response): void
    {
        Http::fake([
            '93.184.216.34/*' => Http::response($videoBytes, 200, ['Content-Type' => 'video/mp4']),
            'eu-speech.googleapis.com/*' => Http::response($v2Response),
        ]);
    }

    /** @return array{status: string, created: int, updated: int, skipped: list<string>} */
    private function enrich(ContentItem $content): array
    {
        return app(RecognitionService::class)->enrich($content, 'corr-1');
    }

    private function euSpeechCallCount(): int
    {
        return count(Http::recorded(
            fn (Request $request): bool => str_contains($request->url(), 'eu-speech.googleapis.com'),
        ));
    }

    public function test_chunk_zero_sync_pass_writes_a_multilingual_detection_and_the_transcript_row(): void
    {
        $this->requireFfmpeg();
        $this->brand();
        [, $item] = $this->creatorReel();
        $this->enableSpeechV2();
        $this->fakeMediaAndSpeech($this->makeVideo(3), $this->v2Response('heute zeige ich euch die neue Lumiere Palette'));

        $result = $this->enrich($item);

        $this->assertSame('completed', $result['status']);

        $detection = RecognitionDetection::query()->sole()->fresh();
        $this->assertSame(RecognitionType::SpokenBrand, $detection->recognition_type);
        $this->assertSame('Maison Lumière', $detection->detected_brand);
        // Deterministic chunk identity — never the v1 truncated transcript.
        $this->assertSame('speech-chunk:0:maison-lumiere', $detection->provider_label);
        $this->assertSame(SourceRegistry::GOOGLE_SPEECH_TO_TEXT, $detection->provenance->source);
        $this->assertSame('google-speech-to-text-v2', $detection->provenance->sourceVersion);

        $transcript = ContentTranscript::query()->sole();
        $this->assertSame($item->id, $transcript->content_item_id);
        $this->assertSame(SourceRegistry::GOOGLE_SPEECH_TO_TEXT, $transcript->provider);
        $this->assertSame('de-DE', $transcript->language);
        // Postgres jsonb normalizes object-key order (length, then bytewise),
        // so compare the single segment key-order-independently while keeping
        // strict-type value checks (the Task 20 as-built pattern): it is
        // exactly these five key/value pairs.
        $this->assertCount(1, $transcript->segments);
        $segment = $transcript->segments[0];
        ksort($segment);
        $expectedSegment = [
            'start' => '0.000',
            'dur' => '2.000', // billedSeconds absent → the chunk window length
            'text' => 'heute zeige ich euch die neue Lumiere Palette',
            'language' => 'de-DE',
            'chunk' => 0,
        ];
        ksort($expectedSegment);
        $this->assertSame($expectedSegment, $segment);

        // Budget: 1 unit, the post counted once (spec §11: no v2 free tier).
        $counter = AiUsageCounter::query()->where('capability', 'speech_transcription')->sole();
        $this->assertSame(1, $counter->units);
        $this->assertSame(1, $counter->posts_processed);

        // Telemetry: the unchanged operation name on the same source.
        $call = ProviderCall::query()->where('source', SourceRegistry::GOOGLE_SPEECH_TO_TEXT)->sole();
        $this->assertSame('speech.recognize', $call->operation);
        $this->assertSame(CallOutcome::Success, $call->outcome);

        // Non-candidate post: chunk 0 only — no extension artifacts.
        $this->assertSame(0, SpeechAudioChunk::query()->count());
        $this->assertSame([], array_filter($result['skipped'], fn (string $m): bool => str_starts_with($m, 'speech:chunks-queued')));

        Http::assertSent(function (Request $request): bool {
            if (! str_contains($request->url(), 'eu-speech.googleapis.com')) {
                return false;
            }

            // Bearer-only auth, never a key in the URL; phrase hints ride
            // along (the ASCII alias avoids JSON unicode-escape ambiguity).
            return ($request->header('Authorization')[0] ?? null) === 'Bearer test-bearer-token'
                && ! str_contains($request->url(), 'key=')
                && str_contains($request->body(), 'lumiere');
        });
    }

    public function test_candidate_bearing_posts_persist_extension_chunks_and_mark_the_queue(): void
    {
        $this->requireFfmpeg();
        $this->brand();
        [$creator, $item] = $this->creatorReel();
        $this->shipInWindow($creator);
        $this->enableSpeechV2();
        $this->fakeMediaAndSpeech($this->makeVideo(5), $this->v2Response('kein markenname hier'));

        $result = $this->enrich($item);

        // 5 s at chunk_seconds=2 → chunk 0 sync + extension chunks 1 (2–4 s)
        // and 2 (4–5 s); chunk 3 starts past EOF → extraction null → stop.
        $this->assertContains('speech:chunks-queued=2', $result['skipped']);

        $chunks = SpeechAudioChunk::query()->orderBy('ordinal')->get();
        $this->assertSame([1, 2], $chunks->pluck('ordinal')->all());
        $this->assertSame([2000, 4000], $chunks->pluck('offset_ms')->all());
        $this->assertSame([2000, 2000], $chunks->pluck('duration_ms')->all());
        $this->assertSame(['pending', 'pending'], $chunks->pluck('status')->all());

        foreach ($chunks as $chunk) {
            Storage::disk($chunk->storage_disk)->assertExists($chunk->storage_path);
        }

        // The extension is ASYNC: exactly one sync call went out (chunk 0).
        $this->assertSame(1, $this->euSpeechCallCount());
    }

    public function test_non_candidate_posts_never_persist_extension_chunks(): void
    {
        $this->requireFfmpeg();
        $this->brand();
        [, $item] = $this->creatorReel(); // no shipment, no roster
        $this->enableSpeechV2();
        $this->fakeMediaAndSpeech($this->makeVideo(5), $this->v2Response('kein markenname hier'));

        $result = $this->enrich($item);

        $this->assertSame(0, SpeechAudioChunk::query()->count());
        $this->assertSame([], array_filter($result['skipped'], fn (string $m): bool => str_starts_with($m, 'speech:chunks-queued')));
    }

    public function test_budget_deny_skips_speech_and_counts_the_skip(): void
    {
        $this->requireFfmpeg();
        $this->brand();
        [, $item] = $this->creatorReel(); // empty candidate set → MEDIUM priority
        $this->enableSpeechV2();
        config(['qds.ai_budget.capabilities.speech_transcription.tenant_daily_units' => 0]);
        $this->fakeMediaAndSpeech($this->makeVideo(3), $this->v2Response('nie gesendet'));

        $result = $this->enrich($item);

        $this->assertContains('speech:budget-exhausted', $result['skipped']);
        $this->assertSame(0, $this->euSpeechCallCount());
        $this->assertSame(0, RecognitionDetection::query()->count());
        $this->assertSame(0, ContentTranscript::query()->count());

        $counter = AiUsageCounter::query()->where('capability', 'speech_transcription')->sole();
        $this->assertSame(0, $counter->units);
        $this->assertSame(1, $counter->posts_skipped_budget);
    }

    public function test_read_only_mode_skips_without_recording(): void
    {
        $this->requireFfmpeg();
        $this->brand();
        [, $item] = $this->creatorReel();
        $this->enableSpeechV2();
        Cache::put(AiBudgetGuard::READ_ONLY_CACHE_KEY, true);
        $this->fakeMediaAndSpeech($this->makeVideo(3), $this->v2Response('nie gesendet'));

        $result = $this->enrich($item);

        $this->assertContains('speech:budget-exhausted', $result['skipped']);
        $this->assertSame(0, $this->euSpeechCallCount());
        $this->assertSame(0, AiUsageCounter::query()->count());
    }

    public function test_v2_enabled_but_unconfigured_fails_closed_and_never_falls_back_to_v1(): void
    {
        $this->brand();
        [, $item] = $this->creatorReel();
        config([
            'qds.enrichment.speech.v2_enabled' => true,
            'services.google_speech.api_key' => 'test-speech-key', // v1 IS configured — must stay unused
        ]);
        Http::fake([
            '93.184.216.34/*' => Http::response('fake-video-bytes', 200, ['Content-Type' => 'video/mp4']),
            'speech.googleapis.com/*' => Http::response(['results' => []]),
        ]);

        $result = $this->enrich($item);

        $this->assertContains('speech:v2-not-configured', $result['skipped']);
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'speech.googleapis.com'));
        $this->assertSame(0, SpeechAudioChunk::query()->count());
    }

    public function test_transient_v2_failure_degrades_gracefully_and_still_counts_the_unit(): void
    {
        $this->requireFfmpeg();
        $this->brand();
        [, $item] = $this->creatorReel();
        $this->enableSpeechV2();
        Http::fake([
            '93.184.216.34/*' => Http::response($this->makeVideo(3), 200, ['Content-Type' => 'video/mp4']),
            'eu-speech.googleapis.com/*' => Http::response(['error' => ['message' => 'RESOURCE_EXHAUSTED']], 429),
        ]);

        $result = $this->enrich($item);

        // Never fails the run (v1 posture); the attempt may have billed —
        // counted conservatively so caps never drift loose.
        $this->assertContains('speech:provider-error', $result['skipped']);
        $this->assertSame(0, RecognitionDetection::query()->count());
        $this->assertSame(1, AiUsageCounter::query()->where('capability', 'speech_transcription')->sole()->units);

        $call = ProviderCall::query()->where('source', SourceRegistry::GOOGLE_SPEECH_TO_TEXT)->sole();
        $this->assertSame(CallOutcome::Failure, $call->outcome);
    }

    public function test_the_no_provider_early_gate_consults_the_v2_client_when_the_switch_is_on(): void
    {
        $this->requireFfmpeg();
        $this->brand();
        [, $item] = $this->creatorReel();
        $this->enableSpeechV2();
        config(['services.google_vision.api_key' => null]); // only speech v2 is configured
        $this->fakeMediaAndSpeech($this->makeVideo(3), $this->v2Response('die lumiere palette'));

        $this->enrich($item);

        // Media WAS downloaded and the v2 call went out — the gate did not
        // early-return on "no configured provider".
        $this->assertSame(1, $this->euSpeechCallCount());
        $this->assertSame(1, RecognitionDetection::query()->count());
    }

    public function test_v1_path_is_byte_identical_when_the_switch_is_off(): void
    {
        // CHARACTERIZATION (spec §9 rollback): default v2_enabled=false →
        // de-DE v1 request, API-key auth, v1 sourceVersion, transcript-text
        // provider label, no transcript rows, no chunks, no budget rows.
        $this->brand();
        [, $item] = $this->creatorReel();
        config([
            'services.google_speech.api_key' => 'test-speech-key',
            'services.google_speech.language_code' => 'de-DE',
        ]);

        // AudioExtractor is not final: the RecognitionPipelineTest stub.
        $this->app->instance(AudioExtractor::class, new class extends AudioExtractor
        {
            public function isAvailable(): bool
            {
                return true;
            }

            public function extract(string $videoBytes): ?string
            {
                return SpeechV2RecognitionTest::AUDIO_BYTES;
            }

            public function extractFromFile(string $videoPath): ?string
            {
                return SpeechV2RecognitionTest::AUDIO_BYTES;
            }
        });

        $transcript = 'heute zeige ich euch die neue Lumiere Palette';
        Http::fake([
            '93.184.216.34/*' => Http::response('fake-video-bytes', 200, ['Content-Type' => 'video/mp4']),
            'speech.googleapis.com/*' => Http::response([
                'results' => [['alternatives' => [['transcript' => $transcript, 'confidence' => 0.91]]]],
            ]),
        ]);

        $result = $this->enrich($item);

        $this->assertSame('completed', $result['status']);

        Http::assertSent(function (Request $request) use ($transcript): bool {
            if (! str_contains($request->url(), 'speech.googleapis.com') || str_contains($request->url(), 'eu-speech')) {
                return false;
            }

            // The EXACT v1 body and auth — byte-identical rollback path.
            return $request->hasHeader('X-Goog-Api-Key', 'test-speech-key')
                && ! str_contains($request->url(), 'key=')
                && $request->data() === [
                    'config' => ['languageCode' => 'de-DE', 'enableAutomaticPunctuation' => true],
                    'audio' => ['content' => base64_encode(self::AUDIO_BYTES)],
                ];
        });
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'eu-speech.googleapis.com'));

        $detection = RecognitionDetection::query()->sole()->fresh();
        $this->assertSame($transcript, $detection->provider_label); // v1 label scheme untouched
        $this->assertSame('google-speech-to-text-v1', $detection->provenance->sourceVersion);

        $this->assertSame(0, ContentTranscript::query()->count());
        $this->assertSame(0, SpeechAudioChunk::query()->count());
        $this->assertSame(0, AiUsageCounter::query()->count());
    }
}
