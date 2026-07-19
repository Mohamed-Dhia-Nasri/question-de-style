<?php

namespace Tests\Feature\Enrichment;

use App\Modules\CRM\Models\Creator;
use App\Modules\CRM\Models\PlatformAccount;
use App\Modules\Monitoring\Models\ContentItem;
use App\Modules\Monitoring\Models\Keyframe;
use App\Modules\Monitoring\Models\Story;
use App\Platform\Enrichment\EnrichmentPipeline;
use App\Platform\Enrichment\Keyframes\KeyframeSampler;
use App\Platform\Enrichment\Keyframes\SampledFrame;
use App\Shared\Enums\ContentType;
use App\Shared\Enums\KeyframeKind;
use App\Shared\Enums\Platform;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MediaResolutionFlowsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['qds.ingestion.media_disk' => 'media']);
        Storage::fake('media');
        $this->app->instance(KeyframeSampler::class, new class extends KeyframeSampler
        {
            public function isAvailable(): bool
            {
                return true;
            }

            public function sample(string $videoPath): ?array
            {
                $path = (string) tempnam(sys_get_temp_dir(), 'qds-stub-frame-');
                file_put_contents($path, 'FRAME');

                return [new SampledFrame($path, 500, 0)];
            }
        });
    }

    private function makeAccount(Platform $platform): PlatformAccount
    {
        return PlatformAccount::factory()->for(Creator::factory())->create(['platform' => $platform]);
    }

    public function test_tiktok_video_yields_downloaded_media_and_video_sample_keyframes(): void
    {
        Http::fake(['93.184.216.34/*' => Http::response('TIKTOKVIDEO', 200, ['Content-Type' => 'video/mp4'])]);
        $item = ContentItem::factory()->for($this->makeAccount(Platform::TikTok), 'platformAccount')->create([
            'platform' => Platform::TikTok,
            'content_type' => ContentType::Short,
            'external_id' => 'tt-1',
            'media_urls' => ['https://93.184.216.34/tt-1.mp4'],
        ]);

        $run = app(EnrichmentPipeline::class)->run($item, 'corr-f1');

        $this->assertSame('completed:1 frame(s)', $run->stages['keyframes']);
        $this->assertSame(KeyframeKind::VideoSample, Keyframe::query()->where('owner_id', $item->id)->firstOrFail()->kind);
    }

    public function test_youtube_video_typed_item_with_thumbnail_yields_a_thumbnail_keyframe(): void
    {
        // THE routing fix: content_type=VIDEO + image payload → Thumbnail,
        // never ffmpeg, never Video Intelligence.
        Http::fake(['93.184.216.34/*' => Http::response('THUMBNAIL', 200, ['Content-Type' => 'image/jpeg'])]);
        $item = ContentItem::factory()->for($this->makeAccount(Platform::YouTube), 'platformAccount')->create([
            'platform' => Platform::YouTube,
            'content_type' => ContentType::Video,
            'external_id' => 'vid00000001',
            'media_urls' => ['https://93.184.216.34/maxres.jpg'],
        ]);

        $run = app(EnrichmentPipeline::class)->run($item, 'corr-f2');

        $this->assertSame('completed:1 frame(s)', $run->stages['keyframes']);
        $frame = Keyframe::query()->where('owner_id', $item->id)->firstOrFail();
        $this->assertSame(KeyframeKind::Thumbnail, $frame->kind);
        $this->assertNull($frame->timestamp_ms);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'videointelligence'));
    }

    public function test_large_video_still_gets_keyframes_with_the_split_marker(): void
    {
        config([
            'services.google_video_intelligence.api_key' => 'test-vi-key',
            'qds.enrichment.recognition.inline_max_bytes' => 5,
        ]);
        Http::fake(['93.184.216.34/*' => Http::response('LARGEREELBYTES', 200, ['Content-Type' => 'video/mp4'])]);
        $item = ContentItem::factory()->for($this->makeAccount(Platform::Instagram), 'platformAccount')->create([
            'platform' => Platform::Instagram,
            'content_type' => ContentType::Reel,
            'external_id' => 'reel-big',
            'media_urls' => ['https://93.184.216.34/big.mp4'],
        ]);

        $run = app(EnrichmentPipeline::class)->run($item, 'corr-f3');

        $this->assertStringContainsString('recognition:whole-video-skipped-too-large', $run->stages['recognition']);
        $this->assertSame('completed:1 frame(s)', $run->stages['keyframes']);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'videointelligence'));
    }

    public function test_story_video_samples_frames_from_the_archived_file(): void
    {
        Storage::disk('media')->put('tenants/1/stories/instagram/9/st-1.mp4', 'STORYVIDEO');
        $story = Story::factory()->for($this->makeAccount(Platform::Instagram), 'platformAccount')->create([
            'platform' => Platform::Instagram,
            'external_id' => 'st-1',
            'media_url' => 'tenants/1/stories/instagram/9/st-1.mp4',
        ]);

        $run = app(EnrichmentPipeline::class)->run($story, 'corr-f4');

        $this->assertSame('completed:1 frame(s)', $run->stages['keyframes']);
        $frame = Keyframe::query()->where('owner_type', $story->getMorphClass())->where('owner_id', $story->id)->firstOrFail();
        $this->assertSame(KeyframeKind::VideoSample, $frame->kind);
    }
}
