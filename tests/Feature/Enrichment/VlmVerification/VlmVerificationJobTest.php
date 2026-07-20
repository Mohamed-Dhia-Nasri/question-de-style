<?php

namespace Tests\Feature\Enrichment\VlmVerification;

use App\Models\Tenant;
use App\Modules\CRM\Models\Creator;
use App\Modules\CRM\Models\PlatformAccount;
use App\Modules\CRM\Models\Product;
use App\Modules\Monitoring\Models\ContentItem;
use App\Modules\Monitoring\Models\Keyframe;
use App\Modules\Monitoring\Models\Story;
use App\Modules\Monitoring\Models\VisualMatchCandidate;
use App\Modules\Monitoring\Models\VisualMatchRun;
use App\Modules\Monitoring\Models\VlmVerificationRun;
use App\Platform\AiBudget\AiBudgetGuard;
use App\Platform\AiBudget\Priority;
use App\Platform\Enrichment\Attribution\AttributionService;
use App\Platform\Enrichment\VlmVerification\Jobs\VlmVerificationJob;
use App\Platform\Ingestion\Exceptions\ProviderCallException;
use App\Platform\Ingestion\Models\IngestionAlert;
use App\Platform\Ingestion\Models\ProviderHealthState;
use App\Platform\Ingestion\SourceRegistry;
use App\Platform\Ingestion\Support\AlertType;
use App\Platform\Ingestion\Support\ErrorCategory;
use App\Platform\Ingestion\Support\ProviderStatus;
use App\Shared\Enums\KeyframeKind;
use App\Shared\Enums\VisualMatchBand;
use App\Shared\Enums\VisualMatchOutcome;
use App\Shared\Enums\VlmRunOutcome;
use App\Shared\Enums\VlmTriggerReason;
use App\Shared\Tenancy\TenantContext;
use App\Shared\ValueObjects\Provenance;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * VlmVerificationJob (sub-project D, spec §10): the full deferral-vs-
 * terminal gate matrix, the crash-safe billing ledger (attempts committed
 * BEFORE each provider call; ceiling survives resumes), the §5/§6 response
 * semantics (safety-block permanence, malformed retry), and the failure
 * hooks. Http::fake only — no real network; token cache pre-warmed so the
 * OAuth flow (own tests, Task 5) is never touched.
 */
class VlmVerificationJobTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $cleanupPaths = [];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('media');
        config([
            'qds.ingestion.media_disk' => 'media',
            'qds.enrichment.vlm.enabled' => true,
            'qds.enrichment.vlm.model_version' => 'gemini-3.5-flash',
            'qds.enrichment.vlm.queue' => 'enrichment',
            'qds.ai_budget.capabilities.vlm_verification' => [
                'price_micro_usd_per_unit' => 30000,
                'per_post_units' => 3,
                'tenant_daily_units' => 150,
                'tenant_monthly_units' => 3000,
                'global_daily_units' => 1500,
                'global_daily_hard_units' => 3000,
                'global_monthly_units' => 30000,
                'global_monthly_hard_units' => 60000,
            ],
        ]);
        $this->configureVlmProvider();
    }

    protected function tearDown(): void
    {
        foreach ($this->cleanupPaths as $path) {
            @unlink($path);
        }

        parent::tearDown();
    }

    /**
     * Configured client + pre-warmed bearer token: the stub credentials
     * file only satisfies isConfigured(); the warm 'qds:google-vlm-token'
     * cache entry (Task 5 contextual binding) keeps the token endpoint
     * untouched.
     */
    private function configureVlmProvider(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'qds-test-vlm-sa-');
        $this->assertIsString($path);
        $this->cleanupPaths[] = $path;
        file_put_contents($path, '{"client_email":"qds-vlm@qds-vlm-test.iam.gserviceaccount.com"}');

        config([
            'services.google_vlm.credentials_path' => $path,
            'services.google_vlm.project_id' => 'qds-vlm-test',
        ]);

        Cache::put('qds:google-vlm-token', 'test-bearer-token', 3540);
    }

    /**
     * Deterministic gradient JPEG: mid luminance, stddev well above the
     * flat threshold. The ramp direction flips per ordinal so sibling
     * frames carry far-apart dHashes — C's FrameDeduplicator (hamming
     * threshold 6/64) would collapse byte-identical frames and frames_sent
     * could never reach 2 (Task 8 precedent: distinct bytes per frame).
     */
    private function frameBytes(int $ordinal = 0): string
    {
        $img = imagecreatetruecolor(64, 64);

        for ($x = 0; $x < 64; $x++) {
            $fx = $ordinal % 2 === 1 ? 63 - $x : $x;

            for ($y = 0; $y < 64; $y++) {
                imagesetpixel($img, $x, $y, imagecolorallocate($img, ($fx * 4) % 256, ($y * 4) % 256, 128));
            }
        }

        ob_start();
        imagejpeg($img, null, 90);

        return (string) ob_get_clean();
    }

    private function makeKeyframe(ContentItem|Story $owner, int $ordinal, ?int $timestampMs): Keyframe
    {
        $path = sprintf(
            'tenants/%d/keyframes/test/%s-%d/%d.jpg',
            (int) $owner->tenant_id,
            class_basename($owner),
            $owner->id,
            $ordinal,
        );
        Storage::disk('media')->put($path, $this->frameBytes($ordinal));

        return Keyframe::query()->create([
            'owner_type' => $owner->getMorphClass(),
            'owner_id' => $owner->id,
            'ordinal' => $ordinal,
            'timestamp_ms' => $timestampMs,
            'storage_disk' => 'media',
            'storage_path' => $path,
            'width' => 64,
            'height' => 64,
            'kind' => KeyframeKind::VideoSample,
            'checksum' => hash('sha256', $path),
            'source_checksum' => str_repeat('b', 64),
            'provenance' => new Provenance('SRC-apify-instagram-reel-scraper', CarbonImmutable::now(), 'keyframes-v1'),
        ]);
    }

    private function flaggedAnchor(
        ContentItem|Story $target,
        Product $product,
        VisualMatchBand $band = VisualMatchBand::Review,
        string $source = 'shipment',
    ): VisualMatchRun {
        $anchor = VisualMatchRun::factory()->create([
            'content_item_id' => $target instanceof ContentItem ? $target->id : null,
            'story_id' => $target instanceof Story ? $target->id : null,
            'outcome' => VisualMatchOutcome::Review,
            'needs_verification' => true,
        ]);

        VisualMatchCandidate::factory()->create([
            'visual_match_run_id' => $anchor->id,
            'product_id' => $product->id,
            'product_label' => $product->name,
            'band' => $band,
            'source' => $source,
            'shipment_in_window' => $source === 'shipment',
            'seeding_campaign_id' => null,
        ]);

        return $anchor;
    }

    /** @return array{0: ContentItem, 1: VisualMatchRun, 2: Product} */
    private function escalatedContentItem(VisualMatchBand $band = VisualMatchBand::Review): array
    {
        $account = PlatformAccount::factory()->for(Creator::factory())->create();
        $item = ContentItem::factory()->for($account, 'platformAccount')->create([
            'published_at' => CarbonImmutable::now()->subDays(2),
            'caption' => 'unboxing day',
        ]);
        $this->makeKeyframe($item, 0, 0);
        $this->makeKeyframe($item, 1, 2000);

        $product = Product::factory()->create(['name' => 'Nexon Aura Headset', 'category' => null]);
        $anchor = $this->flaggedAnchor($item, $product, $band);

        return [$item, $anchor, $product];
    }

    private function pendingRun(ContentItem $item, VisualMatchRun $anchor, int $attempts): VlmVerificationRun
    {
        return VlmVerificationRun::query()->create([
            'content_item_id' => $item->id,
            'visual_match_run_id' => $anchor->id,
            'correlation_id' => 'pending-seed',
            'model_version' => 'gemini-3.5-flash',
            'trigger_reason' => VlmTriggerReason::ReviewBand,
            'priority' => Priority::Medium,
            'frames_sent' => 2,
            'attempts' => $attempts,
            'outcome' => VlmRunOutcome::Pending,
            'thresholds' => ['auto' => 0.85, 'review' => 0.60, 'margin' => 0.10],
            'latency_ms' => 0,
            'estimated_cost_micro_usd' => 30000 * $attempts,
        ]);
    }

    private function terminalRun(ContentItem $item, VisualMatchRun $anchor, VlmRunOutcome $outcome): VlmVerificationRun
    {
        return VlmVerificationRun::query()->create([
            'content_item_id' => $item->id,
            'visual_match_run_id' => $anchor->id,
            'correlation_id' => 'terminal-seed',
            'model_version' => 'gemini-3.5-flash',
            'trigger_reason' => VlmTriggerReason::ReviewBand,
            'priority' => Priority::Medium,
            'frames_sent' => 2,
            'attempts' => 1,
            'outcome' => $outcome,
            'thresholds' => ['auto' => 0.85, 'review' => 0.60, 'margin' => 0.10],
            'latency_ms' => 900,
            'estimated_cost_micro_usd' => 30000,
        ]);
    }

    /** @return array<string, mixed> a schema-valid PRODUCT_CONFIRMED generateContent body */
    private function confirmedResponse(Product $product): array
    {
        return [
            'candidates' => [[
                'content' => ['parts' => [['text' => json_encode([
                    'outcome' => 'PRODUCT_CONFIRMED',
                    'verdicts' => [[
                        'product_key' => 'P'.$product->id,
                        'visible' => true,
                        'spoken' => false,
                        'gifting_cue' => true,
                        'confidence' => 0.92,
                        'frame_names' => ['FRAME_1'],
                        'rationale' => 'The headset sits on the desk in frame one.',
                    ]],
                    'overall_rationale' => 'Clear, unobstructed view.',
                ])]]],
                'finishReason' => 'STOP',
            ]],
            'usageMetadata' => [
                'promptTokenCount' => 9000,
                'candidatesTokenCount' => 300,
                'thoughtsTokenCount' => 40,
            ],
        ];
    }

    /** Records every enrich() call; the job must re-classify in-context (backfill precedent). */
    private function bindAttributionSpy(): object
    {
        $spy = new class extends AttributionService
        {
            /** @var list<int> */
            public array $enriched = [];

            public function __construct() {}

            public function enrich(ContentItem|Story $target): array
            {
                $this->enriched[] = (int) $target->id;

                return [];
            }
        };

        $this->app->instance(AttributionService::class, $spy);

        return $spy;
    }

    private function runJob(int $targetId, ?string $correlationId = 'corr-vlm-test', string $targetType = 'content'): VlmVerificationJob
    {
        $job = new VlmVerificationJob($targetType, $targetId, $correlationId);
        $this->app->call([$job, 'handle']);

        return $job;
    }

    // ---------------------------------------------------------------
    // Deferral gates: NO row, NO provider call.
    // ---------------------------------------------------------------

    public function test_kill_switch_off_is_a_true_noop(): void
    {
        config(['qds.enrichment.vlm.enabled' => false]);
        Http::fake();
        [$item] = $this->escalatedContentItem();

        $this->runJob($item->id);

        Http::assertNothingSent();
        $this->assertDatabaseCount('vlm_verification_runs', 0);
    }

    public function test_missing_target_is_a_quiet_noop(): void
    {
        Http::fake();

        $this->runJob(999999);

        Http::assertNothingSent();
        $this->assertDatabaseCount('vlm_verification_runs', 0);
    }

    public function test_unflagged_or_missing_anchor_writes_nothing(): void
    {
        Http::fake();
        [$item, $anchor] = $this->escalatedContentItem();
        $anchor->update(['needs_verification' => false]);

        $this->runJob($item->id);

        $account = PlatformAccount::factory()->for(Creator::factory())->create();
        $noRunItem = ContentItem::factory()->for($account, 'platformAccount')->create();
        $this->runJob($noRunItem->id);

        Http::assertNothingSent();
        $this->assertDatabaseCount('vlm_verification_runs', 0);
    }

    public function test_consumed_anchor_is_idempotent(): void
    {
        Http::fake();
        [$item, $anchor] = $this->escalatedContentItem();
        $this->terminalRun($item, $anchor, VlmRunOutcome::Confirmed);

        $this->runJob($item->id);

        Http::assertNothingSent();
        $this->assertDatabaseCount('vlm_verification_runs', 1);
    }

    public function test_model_version_bump_reopens_a_consumed_anchor(): void
    {
        [$item, $anchor, $product] = $this->escalatedContentItem();
        $this->terminalRun($item, $anchor, VlmRunOutcome::Confirmed); // consumed at gemini-3.5-flash
        config(['qds.enrichment.vlm.model_version' => 'gemini-4-flash']);
        Http::fake(['aiplatform.eu.rep.googleapis.com/*' => Http::response($this->confirmedResponse($product))]);
        $this->bindAttributionSpy();

        $this->runJob($item->id);

        $this->assertDatabaseCount('vlm_verification_runs', 2);
        $this->assertDatabaseHas('vlm_verification_runs', [
            'content_item_id' => $item->id,
            'model_version' => 'gemini-4-flash',
            'outcome' => 'confirmed',
        ]);
    }

    public function test_unconfigured_provider_defers_without_a_row(): void
    {
        config([
            'services.google_vlm.credentials_path' => null,
            'services.google_vlm.project_id' => null,
        ]);
        Http::fake();
        [$item] = $this->escalatedContentItem();

        $this->runJob($item->id);

        Http::assertNothingSent();
        $this->assertDatabaseCount('vlm_verification_runs', 0);
    }

    public function test_open_breaker_defers_without_a_row(): void
    {
        config(['qds.ingestion.circuit_breaker.enabled' => true]);
        ProviderHealthState::query()->create([
            'source' => SourceRegistry::GOOGLE_GEMINI_VLM,
            'status' => ProviderStatus::Failing,
            'last_failure_at' => CarbonImmutable::now(),
            'consecutive_failures' => 5,
            'last_error_category' => ErrorCategory::Authentication,
        ]);
        Http::fake();
        [$item] = $this->escalatedContentItem();

        $this->runJob($item->id);

        Http::assertNothingSent();
        $this->assertDatabaseCount('vlm_verification_runs', 0);
    }

    public function test_budget_deny_defers_with_a_skip_counter_and_no_row(): void
    {
        config(['qds.ai_budget.capabilities.vlm_verification.tenant_daily_units' => 0]);
        Http::fake();
        [$item] = $this->escalatedContentItem(); // shipment source, no campaign => Medium priority

        $this->runJob($item->id);

        Http::assertNothingSent();
        $this->assertDatabaseCount('vlm_verification_runs', 0);
        $this->assertDatabaseHas('ai_usage_counters', [
            'capability' => 'vlm_verification',
            'tenant_id' => $this->defaultTenant->id,
            'units' => 0,
            'posts_skipped_budget' => 1,
        ]);
    }

    public function test_read_only_mode_defers_without_recording_a_budget_skip(): void
    {
        Cache::put(AiBudgetGuard::READ_ONLY_CACHE_KEY, true);
        Http::fake();
        [$item] = $this->escalatedContentItem();

        $this->runJob($item->id);

        Http::assertNothingSent();
        $this->assertDatabaseCount('vlm_verification_runs', 0);
        $this->assertDatabaseCount('ai_usage_counters', 0);
    }

    // ---------------------------------------------------------------
    // Terminal gates: a row IS written (consumed), still no provider call.
    // ---------------------------------------------------------------

    public function test_pruned_frames_finalize_skipped_no_frames(): void
    {
        Http::fake();
        $account = PlatformAccount::factory()->for(Creator::factory())->create();
        $item = ContentItem::factory()->for($account, 'platformAccount')->create([
            'published_at' => CarbonImmutable::now()->subDays(2),
            'caption' => 'unboxing day',
        ]);
        $product = Product::factory()->create(['name' => 'Nexon Aura Headset', 'category' => null]);
        $anchor = $this->flaggedAnchor($item, $product); // flagged, but NO keyframes exist

        $this->runJob($item->id);

        Http::assertNothingSent();
        $this->assertDatabaseHas('vlm_verification_runs', [
            'content_item_id' => $item->id,
            'visual_match_run_id' => $anchor->id,
            'outcome' => 'skipped_no_frames',
            'attempts' => 0,
        ]);
    }

    public function test_payload_guard_trip_finalizes_skipped_payload_guard(): void
    {
        Http::fake();
        [$item] = $this->escalatedContentItem();
        $item->update(['caption' => 'DM me at creator@example.com for a discount code']);

        $this->runJob($item->id);

        Http::assertNothingSent();
        $this->assertDatabaseHas('vlm_verification_runs', [
            'content_item_id' => $item->id,
            'outcome' => 'skipped_payload_guard',
            'attempts' => 0,
        ]);
    }

    // ---------------------------------------------------------------
    // Success path, safety blocks, and the crash-safe billing ledger.
    // ---------------------------------------------------------------

    public function test_confirmed_verdict_writes_the_run_verdict_detection_and_reclassifies(): void
    {
        [$item, $anchor, $product] = $this->escalatedContentItem();
        Http::fake(['aiplatform.eu.rep.googleapis.com/*' => Http::response($this->confirmedResponse($product))]);
        $spy = $this->bindAttributionSpy();

        $this->runJob($item->id);

        Http::assertSentCount(1);
        $this->assertDatabaseHas('vlm_verification_runs', [
            'content_item_id' => $item->id,
            'visual_match_run_id' => $anchor->id,
            'model_version' => 'gemini-3.5-flash',
            'correlation_id' => 'corr-vlm-test',
            'outcome' => 'confirmed',
            'trigger_reason' => 'review-band',
            'priority' => 'medium',
            'attempts' => 1,
            'frames_sent' => 2,
            'prompt_tokens' => 9000,
            'output_tokens' => 300,
            'thinking_tokens' => 40,
            'estimated_cost_micro_usd' => 30000,
        ]);
        $this->assertDatabaseHas('vlm_candidate_verdicts', [
            'product_id' => $product->id,
            'product_label' => 'Nexon Aura Headset',
            'band' => 'auto',
            'visible' => true,
        ]);
        $this->assertDatabaseHas('recognition_detections', [
            'content_item_id' => $item->id,
            'recognition_type' => 'VLM_PRODUCT',
            'provider_label' => 'vlm-product:'.$product->id,
            'product_id' => $product->id,
        ]);
        $this->assertDatabaseHas('ai_usage_counters', [
            'capability' => 'vlm_verification',
            'tenant_id' => $this->defaultTenant->id,
            'units' => 1,
            'posts_processed' => 1,
        ]);
        $this->assertSame([$item->id], $spy->enriched, 'attribution must re-run once, in-context');
    }

    public function test_safety_block_is_permanent_billed_and_derives_no_band_shipment(): void
    {
        [$item] = $this->escalatedContentItem(VisualMatchBand::Reject); // no REVIEW candidate
        Http::fake(['aiplatform.eu.rep.googleapis.com/*' => Http::response([
            'promptFeedback' => ['blockReason' => 'PROHIBITED_CONTENT'],
            'candidates' => [],
        ])]);
        $spy = $this->bindAttributionSpy();

        $this->runJob($item->id);

        Http::assertSentCount(1); // permanent: never retried
        $this->assertDatabaseHas('vlm_verification_runs', [
            'content_item_id' => $item->id,
            'outcome' => 'skipped_safety_block',
            'trigger_reason' => 'no-band-shipment',
            'attempts' => 1,
        ]);
        $this->assertDatabaseHas('ai_usage_counters', [
            'capability' => 'vlm_verification',
            'units' => 1, // the blocking call BILLED (HTTP 200 bills)
        ]);
        $this->assertSame([], $spy->enriched, 'no evidence changed — no re-classification');
        $this->assertDatabaseMissing('recognition_detections', ['recognition_type' => 'VLM_PRODUCT']);
    }

    public function test_malformed_output_retries_to_the_ceiling_then_failed_malformed(): void
    {
        [$item] = $this->escalatedContentItem();
        Http::fake(['aiplatform.eu.rep.googleapis.com/*' => Http::response([
            'candidates' => [[
                'content' => ['parts' => [['text' => 'this is not json']]],
                'finishReason' => 'STOP',
            ]],
            'usageMetadata' => ['promptTokenCount' => 9000, 'candidatesTokenCount' => 10],
        ])]);
        $this->bindAttributionSpy();

        $this->runJob($item->id);

        Http::assertSentCount(3); // per_post_units = 3 billed calls, then stop
        $this->assertDatabaseHas('vlm_verification_runs', [
            'content_item_id' => $item->id,
            'outcome' => 'failed_malformed',
            'attempts' => 3,
            'estimated_cost_micro_usd' => 90000,
        ]);
        $this->assertDatabaseHas('ai_usage_counters', ['capability' => 'vlm_verification', 'units' => 3]);
        $this->assertDatabaseMissing('recognition_detections', ['recognition_type' => 'VLM_PRODUCT']);
    }

    public function test_a_resumed_pending_row_honours_the_ceiling_across_executions(): void
    {
        [$item, $anchor] = $this->escalatedContentItem();
        $this->pendingRun($item, $anchor, attempts: 2); // a crashed execution already billed 2
        Http::fake(['aiplatform.eu.rep.googleapis.com/*' => Http::response([
            'candidates' => [[
                'content' => ['parts' => [['text' => 'still not json']]],
                'finishReason' => 'STOP',
            ]],
        ])]);
        $this->bindAttributionSpy();

        $this->runJob($item->id);

        Http::assertSentCount(1); // 2 (resumed) + 1 = ceiling of 3 — NOT a fresh count
        $this->assertDatabaseCount('vlm_verification_runs', 1);
        $this->assertDatabaseHas('vlm_verification_runs', [
            'content_item_id' => $item->id,
            'outcome' => 'failed_malformed',
            'attempts' => 3,
        ]);
    }

    public function test_transient_provider_error_rethrows_and_keeps_the_billed_pending_row(): void
    {
        [$item, $anchor] = $this->escalatedContentItem();
        Http::fake(['aiplatform.eu.rep.googleapis.com/*' => Http::response(['error' => 'boom'], 500)]);

        try {
            $this->runJob($item->id);
            $this->fail('Expected the transient ProviderCallException to propagate for queue backoff.');
        } catch (ProviderCallException $e) {
            $this->assertTrue($e->category->isTransient());
        }

        // The billed attempt survives in the pending ledger: the queue retry
        // RESUMES it instead of re-starting the count.
        $this->assertDatabaseHas('vlm_verification_runs', [
            'content_item_id' => $item->id,
            'visual_match_run_id' => $anchor->id,
            'outcome' => 'pending',
            'attempts' => 1,
        ]);
    }

    public function test_failed_hook_finalizes_a_billed_pending_row_and_alerts(): void
    {
        [$item, $anchor] = $this->escalatedContentItem();
        $this->pendingRun($item, $anchor, attempts: 2);

        $job = new VlmVerificationJob('content', $item->id, 'corr-failed');
        $job->failed(new ProviderCallException(SourceRegistry::GOOGLE_GEMINI_VLM, ErrorCategory::UpstreamError, 'upstream kept failing'));

        $this->assertDatabaseHas('vlm_verification_runs', [
            'content_item_id' => $item->id,
            'outcome' => 'skipped_provider',
            'attempts' => 2,
            'rejection_reason' => 'job-failed',
        ]);
        $this->assertTrue(
            IngestionAlert::query()->where('alert_type', AlertType::JobFailed->value)->exists(),
            'final failure must raise the deduplicated JOB_FAILED critical alert',
        );
    }

    public function test_failed_hook_deletes_an_unbilled_pending_row(): void
    {
        [$item, $anchor] = $this->escalatedContentItem();
        $this->pendingRun($item, $anchor, attempts: 0);

        $job = new VlmVerificationJob('content', $item->id, 'corr-failed');
        $job->failed(new ProviderCallException(SourceRegistry::GOOGLE_GEMINI_VLM, ErrorCategory::UpstreamError, 'upstream kept failing'));

        // Nothing billed ⇒ unconsumed: the anchor stays sweep-eligible.
        $this->assertDatabaseCount('vlm_verification_runs', 0);
    }

    public function test_roster_candidate_earns_high_priority_and_null_correlation_stamps_sweep_catchup(): void
    {
        config(['qds.ai_budget.capabilities.vlm_verification.tenant_daily_units' => 0]); // High bypasses soft caps
        $account = PlatformAccount::factory()->for(Creator::factory())->create();
        $item = ContentItem::factory()->for($account, 'platformAccount')->create([
            'published_at' => CarbonImmutable::now()->subDays(2),
            'caption' => 'unboxing day',
        ]);
        $this->makeKeyframe($item, 0, 0);
        $this->makeKeyframe($item, 1, 2000);
        $product = Product::factory()->create(['name' => 'Nexon Aura Headset', 'category' => null]);
        $this->flaggedAnchor($item, $product, VisualMatchBand::Review, source: 'roster');
        Http::fake(['aiplatform.eu.rep.googleapis.com/*' => Http::response($this->confirmedResponse($product))]);
        $this->bindAttributionSpy();

        $this->runJob($item->id, correlationId: null);

        $this->assertDatabaseHas('vlm_verification_runs', [
            'content_item_id' => $item->id,
            'outcome' => 'confirmed',
            'priority' => 'high',
            'trigger_reason' => 'sweep-catchup',
        ]);
    }

    public function test_story_targets_verify_through_the_story_owner_column(): void
    {
        $account = PlatformAccount::factory()->for(Creator::factory())->create();
        $story = Story::factory()->for($account, 'platformAccount')->create([
            'captured_at' => CarbonImmutable::now()->subDays(2),
        ]);
        $this->makeKeyframe($story, 0, null); // stories carry null-timestamp frames
        $product = Product::factory()->create(['name' => 'Nexon Aura Headset', 'category' => null]);
        $this->flaggedAnchor($story, $product);
        Http::fake(['aiplatform.eu.rep.googleapis.com/*' => Http::response($this->confirmedResponse($product))]);
        $this->bindAttributionSpy();

        $this->runJob($story->id, targetType: 'story');

        $this->assertDatabaseHas('vlm_verification_runs', [
            'story_id' => $story->id,
            'content_item_id' => null,
            'outcome' => 'confirmed',
        ]);
        $this->assertDatabaseHas('recognition_detections', [
            'story_id' => $story->id,
            'recognition_type' => 'VLM_PRODUCT',
            'provider_label' => 'vlm-product:'.$product->id,
        ]);
    }

    public function test_writes_are_stamped_with_the_targets_tenant(): void
    {
        $other = Tenant::factory()->create(['name' => 'Other Tenant']);

        /** @var array{0: ContentItem, 1: VisualMatchRun, 2: Product} $made */
        $made = $this->withTenant($other, fn (): array => $this->escalatedContentItem());
        [$item, , $product] = $made;
        Http::fake(['aiplatform.eu.rep.googleapis.com/*' => Http::response($this->confirmedResponse($product))]);
        $this->bindAttributionSpy();

        // Simulate the tenant-less queue worker: no bound tenant context.
        $job = new VlmVerificationJob('content', $item->id, 'corr-tenant');
        app(TenantContext::class)->runAs(null, fn () => $this->app->call([$job, 'handle']));

        $this->assertDatabaseHas('vlm_verification_runs', [
            'content_item_id' => $item->id,
            'tenant_id' => $other->id,
            'outcome' => 'confirmed',
        ]);
        $this->assertDatabaseMissing('vlm_verification_runs', ['tenant_id' => $this->defaultTenant->id]);
    }
}
