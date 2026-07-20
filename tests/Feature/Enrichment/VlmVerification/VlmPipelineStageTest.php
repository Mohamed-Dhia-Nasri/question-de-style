<?php

namespace Tests\Feature\Enrichment\VlmVerification;

use App\Modules\CRM\Models\Creator;
use App\Modules\CRM\Models\PlatformAccount;
use App\Modules\Monitoring\Models\ContentItem;
use App\Modules\Monitoring\Models\MonitoredSubject;
use App\Modules\Monitoring\Models\Story;
use App\Modules\Monitoring\Models\VisualMatchRun;
use App\Modules\Monitoring\Models\VlmVerificationRun;
use App\Platform\AiBudget\Priority;
use App\Platform\Enrichment\Contracts\EnrichmentService;
use App\Platform\Enrichment\Models\EnrichmentRun;
use App\Platform\Enrichment\VlmVerification\Jobs\VlmVerificationJob;
use App\Shared\Enums\ContentType;
use App\Shared\Enums\Platform;
use App\Shared\Enums\VisualMatchOutcome;
use App\Shared\Enums\VlmRunOutcome;
use App\Shared\Enums\VlmTriggerReason;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The vlm_verification pipeline stage (sub-project D, spec §4/§10):
 * DISPATCH-ONLY — the pipeline never blocks on Gemini. Frozen marker set:
 * skipped:disabled | skipped:no-visual-run | skipped:not-flagged |
 * skipped:already-verified | queued. Consumption = a TERMINAL
 * vlm_verification_runs row at the current model version; PENDING rows do
 * not block (a crashed job needs its dispatch back).
 */
class VlmPipelineStageTest extends TestCase
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
            // C off: no matcher runs, so factory-made anchors STAY the
            // latest visual run for their post (deterministic fixtures).
            'qds.enrichment.visual_match.enabled' => false,
            'qds.enrichment.vlm.enabled' => true,
            'qds.enrichment.vlm.model_version' => 'gemini-3.5-flash',
            'qds.enrichment.vlm.queue' => 'enrichment',
        ]);
        Storage::fake('media');
        Http::fake(['93.184.216.34/*' => Http::response('synthetic-image-bytes')]);
        Queue::fake();
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

    private function flaggedRun(ContentItem|Story $target): VisualMatchRun
    {
        return VisualMatchRun::factory()->create([
            'content_item_id' => $target instanceof ContentItem ? $target->id : null,
            'story_id' => $target instanceof Story ? $target->id : null,
            'outcome' => VisualMatchOutcome::Review,
            'needs_verification' => true,
        ]);
    }

    private function stageMarker(ContentItem|Story $target): string
    {
        $column = $target instanceof ContentItem ? 'content_item_id' : 'story_id';

        /** @var EnrichmentRun $run */
        $run = EnrichmentRun::query()->where($column, $target->id)->orderByDesc('id')->firstOrFail();

        return (string) $run->stages['vlm_verification'];
    }

    public function test_kill_switch_off_records_disabled_and_queues_nothing(): void
    {
        config(['qds.enrichment.vlm.enabled' => false]);
        $content = $this->wiredContent();
        $this->flaggedRun($content);

        app(EnrichmentService::class)->enrich($content);

        $this->assertSame('skipped:disabled', $this->stageMarker($content));
        Queue::assertNotPushed(VlmVerificationJob::class);
    }

    public function test_no_visual_run_records_its_marker(): void
    {
        $content = $this->wiredContent();

        app(EnrichmentService::class)->enrich($content);

        $this->assertSame('skipped:no-visual-run', $this->stageMarker($content));
        Queue::assertNotPushed(VlmVerificationJob::class);
    }

    public function test_the_latest_unflagged_run_wins_over_an_older_flag(): void
    {
        $content = $this->wiredContent();
        $this->flaggedRun($content); // older, flagged
        VisualMatchRun::factory()->create([ // newer, unflagged — authoritative (max id)
            'content_item_id' => $content->id,
            'outcome' => VisualMatchOutcome::NoMatch,
            'needs_verification' => false,
        ]);

        app(EnrichmentService::class)->enrich($content);

        $this->assertSame('skipped:not-flagged', $this->stageMarker($content));
        Queue::assertNotPushed(VlmVerificationJob::class);
    }

    public function test_consumed_anchor_skips_and_a_model_bump_reopens(): void
    {
        $content = $this->wiredContent();
        $anchor = $this->flaggedRun($content);
        VlmVerificationRun::query()->create([
            'content_item_id' => $content->id,
            'visual_match_run_id' => $anchor->id,
            'correlation_id' => 'seed',
            'model_version' => 'gemini-3.5-flash',
            'trigger_reason' => VlmTriggerReason::ReviewBand,
            'priority' => Priority::Medium,
            'frames_sent' => 2,
            'attempts' => 1,
            'outcome' => VlmRunOutcome::Confirmed,
            'thresholds' => ['auto' => 0.85, 'review' => 0.60, 'margin' => 0.10],
            'latency_ms' => 900,
            'estimated_cost_micro_usd' => 30000,
        ]);

        app(EnrichmentService::class)->enrich($content);

        $this->assertSame('skipped:already-verified', $this->stageMarker($content));
        Queue::assertNotPushed(VlmVerificationJob::class);

        // Append-only re-verification: a model_version bump re-opens the anchor.
        config(['qds.enrichment.vlm.model_version' => 'gemini-4-flash']);
        app(EnrichmentService::class)->enrich($content);

        $this->assertSame('queued', $this->stageMarker($content));
        Queue::assertPushed(VlmVerificationJob::class, 1);
    }

    public function test_flagged_anchor_queues_with_the_run_correlation_id(): void
    {
        $content = $this->wiredContent();
        $this->flaggedRun($content);

        app(EnrichmentService::class)->enrich($content);

        $this->assertSame('queued', $this->stageMarker($content));

        /** @var EnrichmentRun $run */
        $run = EnrichmentRun::query()->where('content_item_id', $content->id)->firstOrFail();

        Queue::assertPushed(VlmVerificationJob::class, function (VlmVerificationJob $job) use ($content, $run): bool {
            return $job->targetType === 'content'
                && $job->targetId === $content->id
                && $job->correlationId === $run->correlation_id
                && $job->queue === 'enrichment';
        });
    }

    public function test_a_pending_ledger_row_does_not_block_the_dispatch(): void
    {
        $content = $this->wiredContent();
        $anchor = $this->flaggedRun($content);
        VlmVerificationRun::query()->create([
            'content_item_id' => $content->id,
            'visual_match_run_id' => $anchor->id,
            'correlation_id' => 'seed',
            'model_version' => 'gemini-3.5-flash',
            'trigger_reason' => VlmTriggerReason::ReviewBand,
            'priority' => Priority::Medium,
            'frames_sent' => 2,
            'attempts' => 1,
            'outcome' => VlmRunOutcome::Pending,
            'thresholds' => ['auto' => 0.85, 'review' => 0.60, 'margin' => 0.10],
            'latency_ms' => 0,
            'estimated_cost_micro_usd' => 30000,
        ]);

        app(EnrichmentService::class)->enrich($content);

        $this->assertSame('queued', $this->stageMarker($content));
        Queue::assertPushed(VlmVerificationJob::class, 1);
    }

    public function test_story_targets_queue_with_the_story_type(): void
    {
        $creator = Creator::factory()->create();
        $account = PlatformAccount::factory()->forCreator($creator)->create(['platform' => Platform::Instagram]);
        $story = Story::factory()->for($account, 'platformAccount')->create();
        $this->flaggedRun($story);

        app(EnrichmentService::class)->enrich($story);

        $this->assertSame('queued', $this->stageMarker($story));
        Queue::assertPushed(
            VlmVerificationJob::class,
            fn (VlmVerificationJob $job): bool => $job->targetType === 'story' && $job->targetId === $story->id,
        );
    }
}
