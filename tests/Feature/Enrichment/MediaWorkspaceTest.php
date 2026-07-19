<?php

namespace Tests\Feature\Enrichment;

use App\Modules\CRM\Models\Creator;
use App\Modules\CRM\Models\PlatformAccount;
use App\Modules\Monitoring\Models\ContentItem;
use App\Modules\Monitoring\Models\Story;
use App\Platform\Enrichment\Media\MediaWorkspace;
use App\Platform\Enrichment\Media\MediaWorkspaceFactory;
use App\Shared\Enums\ContentType;
use App\Shared\Enums\Platform;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MediaWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    private function makeItem(ContentType $type, array $mediaUrls, Platform $platform = Platform::Instagram): ContentItem
    {
        $account = PlatformAccount::factory()->for(Creator::factory())->create(['platform' => $platform]);

        return ContentItem::factory()->for($account, 'platformAccount')->create([
            'platform' => $platform,
            'content_type' => $type,
            'media_urls' => $mediaUrls,
        ]);
    }

    public function test_acquisition_is_lazy_and_carousel_images_download_once_each(): void
    {
        Http::fake(['93.184.216.34/*' => Http::response('IMAGEBYTES', 200, ['Content-Type' => 'image/jpeg'])]);
        $item = $this->makeItem(ContentType::Carousel, [
            'https://93.184.216.34/a.jpg', 'https://93.184.216.34/b.jpg',
            'https://93.184.216.34/c.jpg', 'https://93.184.216.34/d.jpg',
        ]);

        $ws = app(MediaWorkspaceFactory::class)->forTarget($item);
        Http::assertNothingSent(); // lazy until first access

        $images = $ws->images();

        $this->assertCount(3, $images); // first-3 limit preserved
        Http::assertSentCount(3);
        $this->assertSame('IMAGEBYTES', $images[0]->bytes());
        $this->assertSame(hash('sha256', 'IMAGEBYTES'), $images[0]->sha256);
        $this->assertNull($ws->video());

        $paths = array_map(fn ($a) => $a->tempPath, $images);
        $ws->close();
        foreach ($paths as $path) {
            $this->assertFileDoesNotExist($path);
        }
    }

    public function test_video_typed_row_with_image_payload_routes_to_images(): void
    {
        // THE YouTube case: content_type=VIDEO, media_urls=[thumbnail.jpg].
        Http::fake(['93.184.216.34/*' => Http::response('THUMBBYTES', 200, ['Content-Type' => 'image/jpeg'])]);
        $item = $this->makeItem(ContentType::Video, ['https://93.184.216.34/maxres.jpg'], Platform::YouTube);

        $ws = app(MediaWorkspaceFactory::class)->forTarget($item);

        $this->assertNull($ws->video());
        $this->assertCount(1, $ws->images());
        $this->assertSame('THUMBBYTES', $ws->images()[0]->bytes());
        $ws->close();
    }

    public function test_video_target_downloads_the_first_url_and_expired_url_marks_too_old(): void
    {
        Http::fake(['93.184.216.34/*' => Http::response('', 410)]);
        $item = $this->makeItem(ContentType::Reel, ['https://93.184.216.34/gone.mp4']);

        $ws = app(MediaWorkspaceFactory::class)->forTarget($item);

        $this->assertNull($ws->video());
        $this->assertContains('media:too-old', $ws->markers());
        $ws->close();
    }

    public function test_no_media_urls_marks_media_none(): void
    {
        $item = $this->makeItem(ContentType::Reel, []);
        $ws = app(MediaWorkspaceFactory::class)->forTarget($item);

        $this->assertNull($ws->video());
        $this->assertContains('media:none', $ws->markers());
        $ws->close();
    }

    public function test_story_reads_the_archived_private_disk_file_without_http(): void
    {
        config(['qds.ingestion.media_disk' => 'media']);
        Storage::fake('media');
        Storage::disk('media')->put('tenants/1/stories/instagram/1/story-1.mp4', 'STORYVIDEO');
        Http::fake();

        $account = PlatformAccount::factory()->for(Creator::factory())->create(['platform' => Platform::Instagram]);
        $story = Story::factory()->for($account, 'platformAccount')->create([
            'platform' => Platform::Instagram,
            'media_url' => 'tenants/1/stories/instagram/1/story-1.mp4',
        ]);

        $ws = app(MediaWorkspaceFactory::class)->forTarget($story);

        $this->assertNotNull($ws->video());
        $this->assertSame('STORYVIDEO', $ws->video()->bytes());
        Http::assertNothingSent();
        $ws->close();
    }

    public function test_acquisition_failure_appends_honest_marker_before_propagating_exception(): void
    {
        $ws = new MediaWorkspace(function (): void {
            throw new \RuntimeException('boom');
        });

        // First access throws the exception
        try {
            $ws->images();
            $this->fail('Expected RuntimeException to be thrown');
        } catch (\RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        }

        // Subsequent calls should not re-throw, markers() should contain the honest marker
        $this->assertContains('media:fetch-failed', $ws->markers());
        $this->assertNull($ws->video());
        $ws->close();
    }
}
