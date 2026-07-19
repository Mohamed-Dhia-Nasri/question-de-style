<?php

namespace Tests\Feature\Enrichment;

use App\Modules\CRM\Models\Creator;
use App\Modules\CRM\Models\PlatformAccount;
use App\Modules\Monitoring\Models\ContentItem;
use App\Modules\Monitoring\Models\Keyframe;
use App\Platform\Enrichment\EnrichmentPipeline;
use App\Platform\Enrichment\Keyframes\KeyframeSampler;
use App\Platform\Enrichment\Keyframes\KeyframeWriter;
use App\Platform\Enrichment\Keyframes\SampledFrame;
use App\Shared\Enums\ContentType;
use App\Shared\Enums\KeyframeKind;
use App\Shared\Enums\Platform;
use App\Shared\ValueObjects\Provenance;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class KeyframePipelineTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['qds.ingestion.media_disk' => 'media']);
        Storage::fake('media');
    }

    /** Container-stub the sampler (the AudioExtractor test pattern) — pipeline tests never shell out. */
    private function stubSampler(int $frameCount): void
    {
        $this->app->instance(KeyframeSampler::class, new class($frameCount) extends KeyframeSampler
        {
            public function __construct(private readonly int $frameCount) {}

            public function isAvailable(): bool
            {
                return true;
            }

            public function sample(string $videoPath): ?array
            {
                $frames = [];

                for ($i = 0; $i < $this->frameCount; $i++) {
                    $path = (string) tempnam(sys_get_temp_dir(), 'qds-stub-frame-');
                    file_put_contents($path, "FRAME-{$i}");
                    $frames[] = new SampledFrame($path, $i * 3000, $i);
                }

                return $frames;
            }
        });
    }

    private function makeReel(): ContentItem
    {
        $account = PlatformAccount::factory()->for(Creator::factory())->create(['platform' => Platform::Instagram]);

        return ContentItem::factory()->for($account, 'platformAccount')->create([
            'platform' => Platform::Instagram,
            'content_type' => ContentType::Reel,
            'external_id' => 'reel-1',
            'media_urls' => ['https://93.184.216.34/reel.mp4'],
        ]);
    }

    public function test_video_frames_are_persisted_with_paths_checksums_and_stage_summary(): void
    {
        Http::fake(['93.184.216.34/*' => Http::response('REELBYTES', 200, ['Content-Type' => 'video/mp4'])]);
        $this->stubSampler(3);
        $reel = $this->makeReel();

        $run = app(EnrichmentPipeline::class)->run($reel, 'corr-k1');

        $this->assertSame('completed:3 frame(s)', $run->stages['keyframes']);
        $frames = Keyframe::query()->where('owner_id', $reel->id)->orderBy('ordinal')->get();
        $this->assertCount(3, $frames);
        $this->assertSame(KeyframeKind::VideoSample, $frames[0]->kind);
        $this->assertSame($reel->tenant_id, $frames[0]->tenant_id);
        $this->assertSame(hash('sha256', 'FRAME-0'), $frames[0]->checksum);
        $this->assertSame(hash('sha256', 'REELBYTES'), $frames[0]->source_checksum);
        $expectedPath = "tenants/{$reel->tenant_id}/keyframes/instagram/{$reel->platform_account_id}/content-reel-1/0.jpg";
        $this->assertSame($expectedPath, $frames[0]->storage_path);
        Storage::disk('media')->assertExists($expectedPath);
    }

    public function test_writer_is_all_or_nothing_when_an_ordinal_is_already_taken(): void
    {
        // The atomicity guarantee behind extract-once: a conflicting row for
        // ordinal 1 must roll back ordinals 0 and 2 as well — no partial set.
        $reel = $this->makeReel();
        Keyframe::query()->create([
            'owner_type' => $reel->getMorphClass(), 'owner_id' => $reel->id, 'ordinal' => 1,
            'timestamp_ms' => 3000, 'storage_disk' => 'media',
            'storage_path' => "tenants/{$reel->tenant_id}/keyframes/instagram/{$reel->platform_account_id}/content-reel-1/1.jpg",
            'width' => 1, 'height' => 1, 'kind' => KeyframeKind::VideoSample,
            'checksum' => str_repeat('a', 64), 'source_checksum' => str_repeat('b', 64),
            'provenance' => new Provenance('SRC-apify-instagram-reel-scraper', CarbonImmutable::now(), 'keyframes-v1'),
        ]);

        $entries = [];
        foreach ([0, 1, 2] as $i) {
            $path = (string) tempnam(sys_get_temp_dir(), 'qds-atomic-frame-');
            file_put_contents($path, "F{$i}");
            $entries[] = ['tempPath' => $path, 'timestampMs' => $i * 1000, 'kind' => KeyframeKind::VideoSample, 'extension' => 'jpg', 'sourceChecksum' => str_repeat('b', 64)];
        }

        $written = app(KeyframeWriter::class)->persist($reel, $entries);

        $this->assertSame(0, $written);
        $this->assertSame(1, Keyframe::query()->where('owner_id', $reel->id)->count()); // only the pre-seeded row
        foreach ($entries as $entry) {
            @unlink($entry['tempPath']);
        }
    }

    public function test_writer_fails_whole_batch_when_temp_file_is_unreadable(): void
    {
        // An unreadable frame fails the WHOLE batch (complete-set doctrine):
        // ordinals 0, 2 must also roll back; no partial set.
        $reel = $this->makeReel();

        $entries = [];
        foreach ([0, 1, 2] as $i) {
            $path = (string) tempnam(sys_get_temp_dir(), 'qds-unread-frame-');
            file_put_contents($path, "F{$i}");
            $entries[] = ['tempPath' => $path, 'timestampMs' => $i * 1000, 'kind' => KeyframeKind::VideoSample, 'extension' => 'jpg', 'sourceChecksum' => str_repeat('b', 64)];
        }

        try {
            chmod($entries[1]['tempPath'], 0o000);

            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Keyframe temp file unreadable: ordinal 1');

            app(KeyframeWriter::class)->persist($reel, $entries);
        } finally {
            @chmod($entries[1]['tempPath'], 0o644);
            foreach ($entries as $entry) {
                @unlink($entry['tempPath']);
            }
        }

        // Verify no keyframes were written (the batch failed all-or-nothing)
        $this->assertSame(0, Keyframe::query()->where('owner_id', $reel->id)->count());

        // Verify no storage files exist under the owner's keyframes path
        $ownerSegment = "content-{$reel->external_id}";
        $keyframesPath = "tenants/{$reel->tenant_id}/keyframes/instagram/{$reel->platform_account_id}/{$ownerSegment}/";
        $files = Storage::disk('media')->files($keyframesPath);
        $this->assertEmpty($files);
    }

    public function test_extract_once_a_second_run_skips_and_never_renumbers(): void
    {
        Http::fake(['93.184.216.34/*' => Http::response('REELBYTES', 200, ['Content-Type' => 'video/mp4'])]);
        $this->stubSampler(2);
        $reel = $this->makeReel();

        app(EnrichmentPipeline::class)->run($reel, 'corr-k2');
        $firstIds = Keyframe::query()->where('owner_id', $reel->id)->pluck('id')->all();

        $run = app(EnrichmentPipeline::class)->run($reel, 'corr-k3');

        $this->assertSame('skipped:already-extracted', $run->stages['keyframes']);
        $this->assertSame($firstIds, Keyframe::query()->where('owner_id', $reel->id)->pluck('id')->all());
    }

    public function test_kill_switch_off_reports_disabled_and_writes_nothing(): void
    {
        config(['qds.enrichment.keyframes.enabled' => false]);
        Http::fake(['93.184.216.34/*' => Http::response('REELBYTES', 200, ['Content-Type' => 'video/mp4'])]);
        $this->stubSampler(2);
        $reel = $this->makeReel();

        $run = app(EnrichmentPipeline::class)->run($reel, 'corr-k4');

        $this->assertSame('skipped:disabled', $run->stages['keyframes']);
        $this->assertSame(0, Keyframe::query()->count());
    }

    public function test_carousel_images_persist_as_source_image_frames(): void
    {
        Http::fake(['93.184.216.34/*' => Http::response('IMAGEBYTES', 200, ['Content-Type' => 'image/jpeg'])]);
        $account = PlatformAccount::factory()->for(Creator::factory())->create(['platform' => Platform::Instagram]);
        $carousel = ContentItem::factory()->for($account, 'platformAccount')->create([
            'platform' => Platform::Instagram,
            'content_type' => ContentType::Carousel,
            'external_id' => 'car-1',
            'media_urls' => ['https://93.184.216.34/a.jpg', 'https://93.184.216.34/b.jpg'],
        ]);

        $run = app(EnrichmentPipeline::class)->run($carousel, 'corr-k5');

        $this->assertSame('completed:2 frame(s)', $run->stages['keyframes']);
        $frames = Keyframe::query()->where('owner_id', $carousel->id)->orderBy('ordinal')->get();
        $this->assertSame([KeyframeKind::SourceImage, KeyframeKind::SourceImage], $frames->pluck('kind')->all());
        $this->assertNull($frames[0]->timestamp_ms);
        // Same bytes Vision would see — one download, one checksum.
        $this->assertSame(hash('sha256', 'IMAGEBYTES'), $frames[0]->checksum);
        $this->assertSame($frames[0]->checksum, $frames[0]->source_checksum); // a source image IS its own source
    }

    public function test_ffmpeg_unavailable_reports_the_marker(): void
    {
        Http::fake(['93.184.216.34/*' => Http::response('REELBYTES', 200, ['Content-Type' => 'video/mp4'])]);
        $this->app->instance(KeyframeSampler::class, new class extends KeyframeSampler
        {
            public function isAvailable(): bool
            {
                return false;
            }
        });
        $reel = $this->makeReel();

        $run = app(EnrichmentPipeline::class)->run($reel, 'corr-k6');

        $this->assertSame('skipped:ffmpeg-unavailable', $run->stages['keyframes']);
    }
}
