<?php

namespace Tests\Feature\Enrichment;

use App\Modules\CRM\Models\Creator;
use App\Modules\CRM\Models\PlatformAccount;
use App\Modules\Monitoring\Models\ContentItem;
use App\Modules\Monitoring\Models\ContentTranscript;
use App\Platform\Enrichment\Transcripts\YouTubeTranscriptEnricher;
use App\Platform\Ingestion\Models\ProviderCall;
use App\Platform\Ingestion\SourceRegistry;
use App\Shared\Enums\ContentType;
use App\Shared\Enums\Platform;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakesProviderResponses;
use Tests\TestCase;

class YouTubeTranscriptEnricherTest extends TestCase
{
    use FakesProviderResponses;
    use RefreshDatabase;

    private function makeYouTubeItem(): ContentItem
    {
        $account = PlatformAccount::factory()->for(Creator::factory())->create(['platform' => Platform::YouTube]);

        return ContentItem::factory()->for($account, 'platformAccount')->create([
            'platform' => Platform::YouTube,
            'content_type' => ContentType::Video,
            'external_id' => 'vid00000001',
        ]);
    }

    private function enrich(ContentItem $item): string
    {
        return app(YouTubeTranscriptEnricher::class)->enrich($item, 'corr-x', 0);
    }

    public function test_fetches_persists_available_row_and_records_telemetry(): void
    {
        $this->fakeProviderCredentials();
        $this->fakeApifyActor((string) config('services.apify.actors.youtube_transcript'), $this->fixture('youtube-transcript'));
        $item = $this->makeYouTubeItem();

        $summary = $this->enrich($item);

        $this->assertSame('completed:fetched', $summary);
        $row = ContentTranscript::query()->where('content_item_id', $item->id)->firstOrFail();
        $this->assertSame(ContentTranscript::STATUS_AVAILABLE, $row->status);
        $this->assertSame('danke an Glossier für das PR Paket der You Perfume Duft ist unglaublich', $row->text);
        $this->assertSame('und', $row->language);
        $this->assertSame(SourceRegistry::APIFY_YOUTUBE_TRANSCRIPT, $row->provider);
        $this->assertCount(2, $row->segments);
        $this->assertSame(1, ProviderCall::query()->where('source', SourceRegistry::APIFY_YOUTUBE_TRANSCRIPT)->where('operation', 'transcript.fetch')->count());
    }

    public function test_available_row_short_circuits_without_actor_call(): void
    {
        $this->fakeProviderCredentials();
        Http::fake();
        $item = $this->makeYouTubeItem();
        ContentTranscript::query()->create([
            'content_item_id' => $item->id, 'language' => 'und',
            'status' => ContentTranscript::STATUS_AVAILABLE, 'text' => 'schon da',
            'provider' => SourceRegistry::APIFY_YOUTUBE_TRANSCRIPT,
            'provenance' => new \App\Shared\ValueObjects\Provenance(SourceRegistry::APIFY_YOUTUBE_TRANSCRIPT, \Carbon\CarbonImmutable::now(), 'youtube-transcript-v1'),
            'checksum' => hash('sha256', 'schon da'), 'fetched_at' => \Carbon\CarbonImmutable::now(),
        ]);

        $this->assertSame('completed:cached', $this->enrich($item));
        Http::assertNothingSent();
    }

    public function test_no_captions_persists_the_negative_cache_and_never_rebills(): void
    {
        $this->fakeProviderCredentials();
        $this->fakeApifyActor((string) config('services.apify.actors.youtube_transcript'), [['data' => []]]);
        $item = $this->makeYouTubeItem();

        $this->assertSame('skipped:no-captions', $this->enrich($item));
        $row = ContentTranscript::query()->where('content_item_id', $item->id)->firstOrFail();
        $this->assertSame(ContentTranscript::STATUS_UNAVAILABLE, $row->status);
        $this->assertNull($row->text);

        // Second run: negative cache — NO second actor call.
        Http::fake();
        $this->assertSame('skipped:no-captions', $this->enrich($item));
        Http::assertNothingSent();
    }

    public function test_provider_failure_persists_nothing_so_a_retry_can_refetch(): void
    {
        $this->fakeProviderCredentials();
        $this->fakeApifyActor((string) config('services.apify.actors.youtube_transcript'), [], 500);
        $item = $this->makeYouTubeItem();

        $this->assertSame('skipped:provider-error', $this->enrich($item));
        $this->assertSame(0, ContentTranscript::query()->count());
        // Outcome value per the Task 0 CallOutcome audit (seam 1):
        $this->assertSame(1, ProviderCall::query()->where('source', SourceRegistry::APIFY_YOUTUBE_TRANSCRIPT)->where('outcome', \App\Platform\Ingestion\Support\CallOutcome::Failure->value)->count());
    }

    public function test_kill_switch_off_and_non_youtube_targets_skip_cleanly(): void
    {
        Http::fake();
        $item = $this->makeYouTubeItem();

        config(['qds.ingestion.youtube_transcript.enabled' => false]);
        $this->assertSame('skipped:disabled', $this->enrich($item));

        config(['qds.ingestion.youtube_transcript.enabled' => true]);
        $account = PlatformAccount::factory()->for(Creator::factory())->create(['platform' => Platform::Instagram]);
        $insta = ContentItem::factory()->for($account, 'platformAccount')->create(['platform' => Platform::Instagram, 'content_type' => ContentType::Reel]);
        $this->assertSame('skipped:not-applicable', $this->enrich($insta));

        Http::assertNothingSent();
    }
}
