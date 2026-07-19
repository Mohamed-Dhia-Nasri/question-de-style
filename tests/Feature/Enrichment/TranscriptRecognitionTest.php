<?php

namespace Tests\Feature\Enrichment;

use App\Modules\CRM\Models\Brand;
use App\Modules\CRM\Models\Creator;
use App\Modules\CRM\Models\PlatformAccount;
use App\Modules\Monitoring\Models\ContentItem;
use App\Modules\Monitoring\Models\ContentTranscript;
use App\Modules\Monitoring\Models\Keyframe;
use App\Modules\Monitoring\Models\RecognitionDetection;
use App\Platform\Enrichment\EnrichmentPipeline;
use App\Platform\Enrichment\Recognition\RecognitionService;
use App\Platform\Ingestion\SourceRegistry;
use App\Shared\Enums\ContentType;
use App\Shared\Enums\KeyframeKind;
use App\Shared\Enums\Platform;
use App\Shared\Enums\RecognitionType;
use App\Shared\ValueObjects\Provenance;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FakesProviderResponses;
use Tests\TestCase;

class TranscriptRecognitionTest extends TestCase
{
    use FakesProviderResponses;
    use RefreshDatabase;

    private function makeYouTubeItem(array $overrides = []): ContentItem
    {
        $account = PlatformAccount::factory()->for(Creator::factory())->create(['platform' => Platform::YouTube]);

        return ContentItem::factory()->for($account, 'platformAccount')->create([
            'platform' => Platform::YouTube,
            'content_type' => ContentType::Video,
            'external_id' => 'vid00000001',
            'media_urls' => [],
            ...$overrides,
        ]);
    }

    private function persistAvailableTranscript(ContentItem $item, string $text): void
    {
        ContentTranscript::query()->create([
            'content_item_id' => $item->id, 'language' => 'und',
            'status' => ContentTranscript::STATUS_AVAILABLE, 'text' => $text,
            'provider' => SourceRegistry::APIFY_YOUTUBE_TRANSCRIPT,
            'provenance' => new Provenance(SourceRegistry::APIFY_YOUTUBE_TRANSCRIPT, CarbonImmutable::now(), 'youtube-transcript-v1'),
            'checksum' => hash('sha256', $text), 'fetched_at' => CarbonImmutable::now(),
        ]);
    }

    public function test_persisted_transcript_yields_spoken_brand_without_any_provider_call(): void
    {
        Brand::factory()->create(['name' => 'Glossier']);
        Http::fake();
        $item = $this->makeYouTubeItem();
        $this->persistAvailableTranscript($item, 'danke an Glossier für das PR Paket');

        $result = app(RecognitionService::class)->enrich($item, 'corr-t1');

        $this->assertSame('completed', $result['status']);
        $detection = RecognitionDetection::query()
            ->where('content_item_id', $item->id)
            ->where('recognition_type', RecognitionType::SpokenBrand)
            ->firstOrFail();
        $this->assertSame('Glossier', $detection->detected_brand);
        $this->assertSame(SourceRegistry::APIFY_YOUTUBE_TRANSCRIPT, $detection->provenance->source);
        Http::assertNothingSent(); // consume-only: recognition never fetches
    }

    public function test_no_available_transcript_reports_unavailable(): void
    {
        Http::fake();
        $item = $this->makeYouTubeItem();

        $result = app(RecognitionService::class)->enrich($item, 'corr-t2');

        $this->assertContains('youtube-transcript:unavailable', $result['skipped']);
        Http::assertNothingSent();
    }

    public function test_full_youtube_flow_transcript_stage_plus_thumbnail_keyframe_plus_spoken_brand(): void
    {
        // The spec §7 YouTube worked example, end-to-end through the pipeline.
        Brand::factory()->create(['name' => 'Glossier']);
        config(['qds.ingestion.media_disk' => 'media']);
        Storage::fake('media');
        $this->fakeProviderCredentials();
        Http::fake([
            'api.apify.com/v2/acts/*/run-sync-get-dataset-items*' => Http::response($this->fixture('youtube-transcript')),
            '93.184.216.34/*' => Http::response('THUMBNAIL', 200, ['Content-Type' => 'image/jpeg']),
        ]);
        $item = $this->makeYouTubeItem(['media_urls' => ['https://93.184.216.34/maxres.jpg']]);

        $run = app(EnrichmentPipeline::class)->run($item, 'corr-t3');

        $this->assertSame('completed:fetched', $run->stages['transcript']);
        $this->assertSame('completed:1 frame(s)', $run->stages['keyframes']);
        $this->assertSame(KeyframeKind::Thumbnail, Keyframe::query()->where('owner_id', $item->id)->firstOrFail()->kind);
        $this->assertTrue(
            RecognitionDetection::query()
                ->where('content_item_id', $item->id)
                ->where('recognition_type', RecognitionType::SpokenBrand)
                ->where('detected_brand', 'Glossier')
                ->exists(),
        );
    }
}
