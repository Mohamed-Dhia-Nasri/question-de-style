<?php

namespace Tests\Feature\Enrichment;

use App\Modules\CRM\Models\Creator;
use App\Modules\CRM\Models\PlatformAccount;
use App\Modules\Monitoring\Models\ContentHashtag;
use App\Modules\Monitoring\Models\ContentItem;
use App\Modules\Monitoring\Models\EmvResult;
use App\Modules\Monitoring\Models\HashtagList;
use App\Modules\Monitoring\Models\Mention;
use App\Modules\Monitoring\Models\MonitoredSubject;
use App\Modules\Monitoring\Models\SentimentAnalysis;
use App\Modules\Monitoring\Models\Story;
use App\Platform\Enrichment\Contracts\EnrichmentService;
use App\Platform\Enrichment\EnrichmentPipeline;
use App\Platform\Enrichment\Jobs\EnrichContentItemJob;
use App\Platform\Enrichment\Models\EnrichmentRun;
use App\Platform\Enrichment\Support\EnrichmentRunStatus;
use App\Platform\Ingestion\Exceptions\ProviderCallException;
use App\Shared\Enums\ConfidenceLevel;
use App\Shared\Enums\ContentType;
use App\Shared\Enums\MentionType;
use App\Shared\Enums\Platform;
use App\Shared\Enums\VerificationStatus;
use App\Shared\ValueObjects\ConfidenceAssessment;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use stdClass;
use Tests\TestCase;

/**
 * SVC-EnrichmentAI end-to-end: the pipeline completes with unavailable
 * boundaries (unconfigured providers, no sentiment model, no active EMV
 * configuration) as normal outcomes — nothing fabricated, missing stays
 * absent — while a thrown provider error marks the run FAILED with a
 * sanitized error only. The sweep command self-gates and only queues
 * recently ingested, not-yet-enriched content.
 */
class EnrichmentPipelineTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['qds.ingestion.media_disk' => 'media']);
        Storage::fake('media');
    }

    /** Creator → platform account → active CREATOR subject → content. */
    private function wiredContent(string $caption): ContentItem
    {
        $creator = Creator::factory()->create();

        $account = PlatformAccount::factory()
            ->forCreator($creator)
            ->create(['platform' => Platform::Instagram]);

        MonitoredSubject::factory()->create([
            'creator_id' => $creator->id,
            'platforms' => [Platform::Instagram],
        ]);

        return ContentItem::factory()->create([
            'platform_account_id' => $account->id,
            'platform' => Platform::Instagram,
            'content_type' => ContentType::ImagePost,
            'caption' => $caption,
            'media_urls' => ['https://93.184.216.34/img.jpg'],
        ]);
    }

    public function test_full_run_completes_with_no_providers_configured(): void
    {
        config([
            'services.google_vision.api_key' => '',
            'services.google_video_intelligence.api_key' => '',
        ]);

        Http::fake(['93.184.216.34/*' => Http::response('synthetic-image-bytes')]);

        HashtagList::factory()->hashtag('#brandtag')->create();

        $content = $this->wiredContent('#brandtag love it');

        app(EnrichmentService::class)->enrich($content);

        $run = EnrichmentRun::query()->where('content_item_id', $content->id)->firstOrFail();

        $this->assertSame(EnrichmentRunStatus::Completed, $run->status);
        $this->assertNotNull($run->finished_at);
        $this->assertNull($run->error);

        $this->assertStringStartsWith('completed:', $run->stages['hashtags']);
        $this->assertStringContainsString('vision:not-configured', $run->stages['recognition']);
        $this->assertStringContainsString('speech:not-configured', $run->stages['recognition']);
        $this->assertSame('unavailable', $run->stages['sentiment']);
        $this->assertSame('completed:1 mention(s)', $run->stages['attribution']);
        $this->assertStringStartsWith('unavailable:', $run->stages['emv']);
        $this->assertStringStartsWith('unavailable:', $run->stages['reach']);

        // Unavailable is absent, never a fabricated (or zero) row.
        $this->assertSame(0, SentimentAnalysis::query()->count());
        $this->assertSame(0, EmvResult::query()->count());

        $hashtagRow = ContentHashtag::query()->where('content_item_id', $content->id)->firstOrFail();
        $this->assertSame('brandtag', $hashtagRow->normalized);
        $this->assertFalse($hashtagRow->is_ambiguous);

        // A matched brand hashtag without any seeding record yields
        // LIKELY_ORGANIC — there is no CONFIRMED_ORGANIC, ever.
        $mention = Mention::query()->where('content_item_id', $content->id)->firstOrFail();
        $this->assertSame(MentionType::LikelyOrganic, $mention->mention_type);

        // Every AI value carries a full envelope and starts AI_ASSESSED.
        $this->assertInstanceOf(ConfidenceAssessment::class, $mention->classification);
        $this->assertSame(MentionType::LikelyOrganic->value, $mention->classification->value);
        $this->assertSame(ConfidenceLevel::Medium, $mention->classification->confidenceLevel);
        $this->assertSame(VerificationStatus::AiAssessed, $mention->classification->verificationStatus);
        $this->assertNotEmpty($mention->classification->signals);
    }

    public function test_enrich_rejects_an_unsupported_record(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(EnrichmentService::class)->enrich(new stdClass);
    }

    public function test_story_run_skips_caption_stages_and_emv(): void
    {
        $story = Story::factory()->create(['media_url' => null]);

        app(EnrichmentService::class)->enrich($story);

        $run = EnrichmentRun::query()->where('story_id', $story->id)->firstOrFail();

        $this->assertSame(EnrichmentRunStatus::Completed, $run->status);
        $this->assertSame('skipped:stories-have-no-caption', $run->stages['hashtags']);
        $this->assertSame('skipped:stories-have-no-caption', $run->stages['sentiment']);
        $this->assertSame('skipped:content-items-only', $run->stages['emv']);
        $this->assertSame('skipped:content-items-only', $run->stages['reach']);
        // No recognition provider is configured, so the pipeline skips
        // before ever touching the story's archived media (cost control).
        $this->assertStringContainsString('vision:not-configured', $run->stages['recognition']);

        // No creator behind the account → no subject → no Mention row.
        $this->assertSame('completed:0 mention(s)', $run->stages['attribution']);
        $this->assertSame(0, Mention::query()->count());
    }

    public function test_provider_failure_marks_the_run_failed_with_a_sanitized_error(): void
    {
        config(['services.google_vision.api_key' => 'test-vision-key-123']);

        Http::fake([
            '93.184.216.34/*' => Http::response('synthetic-image-bytes'),
            'vision.googleapis.com/*' => Http::response(
                '<html><body>Internal error; key=test-vision-key-123</body></html>',
                500,
            ),
        ]);

        $content = $this->wiredContent('Neutral caption without hashtags');

        try {
            app(EnrichmentPipeline::class)->run($content, 'corr-fail');
            $this->fail('Expected a ProviderCallException from the vision call.');
        } catch (ProviderCallException $e) {
            $this->assertStringNotContainsString('test-vision-key-123', $e->getMessage());
        }

        $run = EnrichmentRun::query()->where('content_item_id', $content->id)->firstOrFail();

        $this->assertSame(EnrichmentRunStatus::Failed, $run->status);
        $this->assertNotNull($run->finished_at);

        // Stages recorded up to the failure point only.
        $this->assertArrayHasKey('hashtags', $run->stages);
        $this->assertArrayNotHasKey('recognition', $run->stages);
        $this->assertArrayNotHasKey('sentiment', $run->stages);

        // Sanitized error: classified provider message, no key, no raw body.
        $this->assertNotNull($run->error);
        $this->assertStringContainsString('SRC-google-cloud-vision', $run->error);
        $this->assertStringContainsString('HTTP 500', $run->error);
        $this->assertStringNotContainsString('test-vision-key-123', $run->error);
        $this->assertStringNotContainsString('<html', $run->error);
        $this->assertStringNotContainsString('Internal error', $run->error);
    }

    public function test_sweep_command_self_gates_on_the_enrichment_flag(): void
    {
        config(['qds.enrichment.enabled' => false]);

        Queue::fake();

        ContentItem::factory()->create(['published_at' => now()->subDay()]);

        $this->artisan('qds:run-enrichment')
            ->expectsOutputToContain('Enrichment is disabled')
            ->assertSuccessful();

        Queue::assertNothingPushed();
    }

    public function test_sweep_queues_only_recent_content_without_a_completed_run(): void
    {
        config([
            'qds.enrichment.enabled' => true,
            'qds.enrichment.content_window_days' => 30,
            'qds.enrichment.sweep_batch' => 50,
        ]);

        Queue::fake();

        $enriched = ContentItem::factory()->create(['published_at' => now()->subDays(2)]);
        EnrichmentRun::query()->create([
            'content_item_id' => $enriched->id,
            'correlation_id' => 'corr-done',
            'status' => EnrichmentRunStatus::Completed,
            'started_at' => CarbonImmutable::now()->subDay(),
            'finished_at' => CarbonImmutable::now()->subDay(),
        ]);

        $fresh = ContentItem::factory()->create(['published_at' => now()->subDay()]);

        ContentItem::factory()->create(['published_at' => now()->subDays(45)]);

        $this->artisan('qds:run-enrichment')->assertSuccessful();

        Queue::assertPushed(EnrichContentItemJob::class, 1);
        Queue::assertPushed(
            EnrichContentItemJob::class,
            fn (EnrichContentItemJob $job): bool => $job->contentItemId === $fresh->id,
        );
    }

    public function test_sweep_queues_an_explicitly_named_content_item(): void
    {
        config(['qds.enrichment.enabled' => true]);

        Queue::fake();

        $content = ContentItem::factory()->create(['published_at' => now()->subDays(90)]);

        $this->artisan('qds:run-enrichment', ['--content-item' => (string) $content->id])
            ->expectsOutputToContain("Queued enrichment for ContentItem {$content->id}")
            ->assertSuccessful();

        Queue::assertPushed(EnrichContentItemJob::class, 1);
        Queue::assertPushed(
            EnrichContentItemJob::class,
            fn (EnrichContentItemJob $job): bool => $job->contentItemId === $content->id,
        );
    }
}
