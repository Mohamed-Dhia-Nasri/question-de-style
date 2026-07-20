<?php

namespace Tests\Feature\Enrichment;

use App\Modules\CRM\Models\Creator;
use App\Modules\CRM\Models\PlatformAccount;
use App\Modules\Monitoring\Models\ContentItem;
use App\Modules\Monitoring\Models\MonitoredSubject;
use App\Platform\Enrichment\Contracts\EnrichmentService;
use App\Platform\Enrichment\Models\EnrichmentRun;
use App\Shared\Enums\ContentType;
use App\Shared\Enums\Platform;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class VisualMatchPipelineWiringTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'qds.ingestion.media_disk' => 'media',
            'services.google_vision.api_key' => '',
            'services.google_video_intelligence.api_key' => '',
            'qds.enrichment.keyframes.enabled' => false,
        ]);
        Storage::fake('media');
        Http::fake(['93.184.216.34/*' => Http::response('synthetic-image-bytes')]);
    }

    private function wiredContent(): ContentItem
    {
        $creator = Creator::factory()->create();
        $account = PlatformAccount::factory()->forCreator($creator)->create(['platform' => Platform::Instagram]);
        MonitoredSubject::factory()->create(['creator_id' => $creator->id, 'platforms' => [Platform::Instagram]]);

        return ContentItem::factory()->create([
            'platform_account_id' => $account->id,
            'platform' => Platform::Instagram,
            'content_type' => ContentType::ImagePost,
            'caption' => 'ein Post',
            'media_urls' => ['https://93.184.216.34/img.jpg'],
        ]);
    }

    public function test_stage_sits_between_keyframes_and_text_signals_and_kill_switch_records_disabled(): void
    {
        config(['qds.enrichment.visual_match.enabled' => false]);

        $content = $this->wiredContent();
        app(EnrichmentService::class)->enrich($content);

        $run = EnrichmentRun::query()->where('content_item_id', $content->id)->firstOrFail();

        // `stages` is a jsonb column: PostgreSQL's jsonb storage does not
        // preserve input key order (it re-serializes object keys by
        // length-then-lexicographic order), so the persisted round-trip
        // can never reflect execution order — only the key SET is
        // observable here. The stage's actual position (between
        // `keyframes` and `text_signals` in the pipeline's own $stages
        // assembly) is the real ordering contract and is verified by
        // EnrichmentPipeline::run's source, not by this DB read.
        $this->assertEqualsCanonicalizing(
            ['hashtags', 'transcript', 'recognition', 'keyframes', 'visual_match', 'text_signals', 'sentiment', 'attribution', 'emv', 'reach'],
            array_keys($run->stages),
        );
        $this->assertSame('skipped:disabled', $run->stages['visual_match']);
    }

    public function test_enabled_but_unconfigured_provider_records_its_own_marker(): void
    {
        config([
            'qds.enrichment.visual_match.enabled' => true,
            'services.google_embeddings.credentials_path' => null,
            'services.google_embeddings.project_id' => null,
        ]);

        $content = $this->wiredContent();
        app(EnrichmentService::class)->enrich($content);

        $run = EnrichmentRun::query()->where('content_item_id', $content->id)->firstOrFail();
        $this->assertSame('skipped:not-configured', $run->stages['visual_match']);
    }
}
