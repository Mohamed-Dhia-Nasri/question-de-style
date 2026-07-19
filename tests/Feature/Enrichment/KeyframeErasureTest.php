<?php

namespace Tests\Feature\Enrichment;

use App\Modules\CRM\Models\Creator;
use App\Modules\CRM\Models\PlatformAccount;
use App\Modules\CRM\Services\Gdpr\CreatorEraser;
use App\Modules\Monitoring\Models\ContentItem;
use App\Modules\Monitoring\Models\ContentTranscript;
use App\Modules\Monitoring\Models\Keyframe;
use App\Platform\Ingestion\SourceRegistry;
use App\Shared\Enums\KeyframeKind;
use App\Shared\ValueObjects\Provenance;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * GDPR erasure coverage for sub-project B's derived-media artifacts
 * (B2-gate merge condition): persisted keyframe frames and content
 * transcripts are personal data too and must be erasable like every other
 * monitoring artifact CreatorEraser already purges.
 */
class KeyframeErasureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('media');
        Storage::fake('exports');
    }

    public function test_erasure_removes_keyframe_rows_files_and_transcripts(): void
    {
        config(['qds.ingestion.media_disk' => 'media']);

        $creator = Creator::factory()->create();
        $account = PlatformAccount::factory()->for($creator)->create();
        $item = ContentItem::factory()->for($account, 'platformAccount')->create();

        $path = "tenants/{$item->tenant_id}/keyframes/instagram/{$account->id}/content-x/0.jpg";
        Storage::disk('media')->put($path, 'FRAME');
        Keyframe::query()->create([
            'owner_type' => $item->getMorphClass(),
            'owner_id' => $item->id,
            'ordinal' => 0,
            'timestamp_ms' => 0,
            'storage_disk' => 'media',
            'storage_path' => $path,
            'width' => 100,
            'height' => 100,
            'kind' => KeyframeKind::VideoSample,
            'checksum' => str_repeat('a', 64),
            'source_checksum' => str_repeat('b', 64),
            'provenance' => new Provenance('SRC-apify-instagram-reel-scraper', CarbonImmutable::now(), 'keyframes-v1'),
        ]);
        ContentTranscript::query()->create([
            'content_item_id' => $item->id,
            'language' => 'und',
            'status' => ContentTranscript::STATUS_AVAILABLE,
            'text' => 'hello',
            'provider' => SourceRegistry::APIFY_YOUTUBE_TRANSCRIPT,
            'provenance' => new Provenance(SourceRegistry::APIFY_YOUTUBE_TRANSCRIPT, CarbonImmutable::now(), 'youtube-transcript-v1'),
            'checksum' => hash('sha256', 'hello'),
            'fetched_at' => CarbonImmutable::now(),
        ]);

        $counts = app(CreatorEraser::class)->erase($creator);

        $this->assertSame(1, $counts['keyframes']);
        $this->assertSame(1, $counts['content_transcripts']);
        $this->assertSame(1, $counts['keyframe_files']);
        $this->assertSame(0, Keyframe::query()->withoutGlobalScopes()->count());
        $this->assertSame(0, ContentTranscript::query()->withoutGlobalScopes()->count());
        Storage::disk('media')->assertMissing($path);
    }
}
