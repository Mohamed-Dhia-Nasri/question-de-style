<?php

namespace Tests\Feature\Enrichment;

use App\Modules\CRM\Models\Brand;
use App\Modules\Monitoring\Models\ContentItem;
use App\Modules\Monitoring\Models\RecognitionDetection;
use App\Platform\Enrichment\Recognition\RecognitionService;
use App\Platform\Ingestion\Exceptions\ProviderCallException;
use App\Platform\Ingestion\Models\IngestionAlert;
use App\Platform\Ingestion\Models\ProviderCall;
use App\Platform\Ingestion\Models\ProviderHealthState;
use App\Platform\Ingestion\Models\QuarantinedRecord;
use App\Platform\Ingestion\SourceRegistry;
use App\Platform\Ingestion\Support\AlertType;
use App\Platform\Ingestion\Support\CallOutcome;
use App\Platform\Ingestion\Support\ErrorCategory;
use App\Platform\Ingestion\Support\ProviderStatus;
use App\Shared\Enums\ConfidenceLevel;
use App\Shared\Enums\ContentType;
use App\Shared\Enums\RecognitionType;
use App\Shared\Enums\VerificationStatus;
use App\Shared\ValueObjects\ConfidenceAssessment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Recognition stage (REQ-M1-008) end-to-end over SRC-google-cloud-vision:
 * synthetic media + synthetic Vision responses only (DP-005). Covers
 * lexicon-matched LOGO / IMAGE_TEXT_OCR detections with mandatory
 * ConfidenceAssessment + Provenance envelopes (DP-002/DP-003), quarantine
 * of malformed annotations, full External API Monitoring telemetry,
 * outbound request security, and human precedence (DP-004).
 */
class RecognitionPipelineTest extends TestCase
{
    use RefreshDatabase;

    private const MEDIA_URL = 'https://93.184.216.34/img-1.jpg';

    private const IMAGE_BYTES = 'fake-image-bytes';

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.google_vision.api_key' => 'test-vision-key']);
    }

    private function brand(): Brand
    {
        return Brand::factory()->create([
            'name' => 'Maison Lumière',
            'aliases' => ['lumiere', '@maisonlumiere'],
        ]);
    }

    private function imagePost(): ContentItem
    {
        return ContentItem::factory()->create([
            'content_type' => ContentType::ImagePost,
            'media_urls' => [self::MEDIA_URL],
        ]);
    }

    /** @param array<string, mixed> $annotationSet */
    private function fakeVision(array $annotationSet): void
    {
        Http::fake([
            '93.184.216.34/*' => Http::response(self::IMAGE_BYTES),
            'vision.googleapis.com/*' => Http::response(['responses' => [$annotationSet]]),
        ]);
    }

    /** @return array{status: string, created: int, updated: int, skipped: list<string>} */
    private function enrich(ContentItem $content, string $correlationId = 'corr-1'): array
    {
        return app(RecognitionService::class)->enrich($content, $correlationId);
    }

    public function test_logo_annotation_becomes_a_high_confidence_lexicon_matched_detection(): void
    {
        $this->brand();
        $content = $this->imagePost();

        // Alias label, high score (>= qds.enrichment.confidence.high).
        $this->fakeVision(['logoAnnotations' => [['description' => 'Lumiere', 'score' => 0.94]]]);

        $result = $this->enrich($content);

        $this->assertSame('completed', $result['status']);
        $this->assertSame(1, $result['created']);

        $detection = RecognitionDetection::query()->sole()->fresh();

        $this->assertSame($content->id, $detection->content_item_id);
        $this->assertSame(RecognitionType::Logo, $detection->recognition_type);
        // The lexicon resolves the alias to the canonical CRM brand name.
        $this->assertSame('Maison Lumière', $detection->detected_brand);

        // Every AI value carries a full ConfidenceAssessment and starts AI_ASSESSED.
        $this->assertInstanceOf(ConfidenceAssessment::class, $detection->assessment);
        $this->assertSame('Maison Lumière', $detection->assessment->value);
        $this->assertSame(ConfidenceLevel::High, $detection->assessment->confidenceLevel);
        $this->assertSame(VerificationStatus::AiAssessed, $detection->assessment->verificationStatus);
        $this->assertNotEmpty($detection->assessment->signals);
        $this->assertContains('logo-match-score:0.94', $detection->assessment->signals);
        $this->assertContains('brand-lexicon:matched', $detection->assessment->signals);

        // Externally produced → mandatory provenance (DP-002).
        $this->assertSame(SourceRegistry::GOOGLE_CLOUD_VISION, $detection->provenance->source);
        $this->assertSame('google-cloud-vision-v1', $detection->provenance->sourceVersion);
    }

    public function test_logo_detection_stores_brand_only_never_text_or_product(): void
    {
        $this->brand();
        $content = $this->imagePost();
        $this->fakeVision(['logoAnnotations' => [['description' => 'Lumiere', 'score' => 0.94]]]);

        $this->enrich($content);

        $detection = RecognitionDetection::query()->sole();

        // A logo is a brand-level claim: no text, no product inference —
        // the missing value stays null, never a fabricated placeholder.
        $this->assertNull($detection->detected_text);
        $this->assertSame('Maison Lumière', $detection->detected_brand);
    }

    public function test_low_score_logo_lands_in_the_review_queue_predicate(): void
    {
        $this->brand();
        $content = $this->imagePost();

        // Below qds.enrichment.confidence.medium → LOW → review queue (DP-004).
        $this->fakeVision(['logoAnnotations' => [['description' => 'Lumiere', 'score' => 0.41]]]);

        $this->enrich($content);

        $detection = RecognitionDetection::query()->sole()->fresh();

        $this->assertSame(ConfidenceLevel::Low, $detection->assessment->confidenceLevel);
        $this->assertTrue($detection->assessment->needsHumanReview());

        $queue = RecognitionDetection::query()
            ->where(DB::raw("assessment->>'verificationStatus'"), VerificationStatus::AiAssessed->value)
            ->whereIn(DB::raw("assessment->>'confidenceLevel'"), [ConfidenceLevel::Low->value, ConfidenceLevel::Unknown->value])
            ->pluck('id');

        $this->assertContains($detection->id, $queue);
        $this->assertCount(1, $queue);
    }

    public function test_ocr_text_containing_a_brand_alias_becomes_a_medium_ocr_detection(): void
    {
        $this->brand();
        $content = $this->imagePost();

        $fullText = 'Unboxing the new lumiere palette today';
        $this->fakeVision(['textAnnotations' => [['description' => $fullText]]]);

        $result = $this->enrich($content);

        $this->assertSame(1, $result['created']);

        $detection = RecognitionDetection::query()->sole()->fresh();

        $this->assertSame(RecognitionType::ImageTextOcr, $detection->recognition_type);
        $this->assertSame($fullText, $detection->detected_text);
        $this->assertSame('Maison Lumière', $detection->detected_brand);
        // No numeric score from OCR text matching → MEDIUM, never HIGH.
        $this->assertSame(ConfidenceLevel::Medium, $detection->assessment->confidenceLevel);
        $this->assertSame(VerificationStatus::AiAssessed, $detection->assessment->verificationStatus);
        $this->assertContains('ocr-brand-text-match:Maison Lumière', $detection->assessment->signals);
    }

    public function test_ocr_text_without_a_known_brand_is_not_stored(): void
    {
        $this->brand();
        $content = $this->imagePost();

        $this->fakeVision(['textAnnotations' => [['description' => 'sunset vibes at the beach, 50% off']]]);

        $result = $this->enrich($content);

        // Free text with no brand signal is not a recognition hit.
        $this->assertSame('completed-empty', $result['status']);
        $this->assertSame(0, $result['created']);
        $this->assertSame(0, RecognitionDetection::query()->count());
    }

    public function test_malformed_logo_annotation_is_quarantined_and_the_call_is_partial(): void
    {
        $this->brand();
        $content = $this->imagePost();

        // Logo annotation without a description → structural rejection.
        $this->fakeVision(['logoAnnotations' => [['score' => 0.9]]]);

        $this->enrich($content);

        $this->assertSame(0, RecognitionDetection::query()->count());

        $quarantined = QuarantinedRecord::query()->sole();
        $this->assertSame(SourceRegistry::GOOGLE_CLOUD_VISION, $quarantined->source);
        $this->assertSame('vision.annotate', $quarantined->operation);
        $this->assertSame('corr-1', $quarantined->correlation_id);
        $this->assertSame(ErrorCategory::MissingRequiredFields, $quarantined->reason_category);
        $this->assertNotNull($quarantined->expires_at);

        $call = ProviderCall::query()->sole();
        $this->assertSame(CallOutcome::Partial, $call->outcome);
        $this->assertSame(1, $call->rejected_count);
        $this->assertSame(1, $call->quarantined_count);
        $this->assertSame(0, $call->accepted_count);
    }

    public function test_successful_call_records_telemetry_and_a_healthy_provider_state(): void
    {
        $this->brand();
        $content = $this->imagePost();
        $this->fakeVision(['logoAnnotations' => [['description' => 'Lumiere', 'score' => 0.94]]]);

        $this->enrich($content);

        // Exactly one provider call — the media download is not an AI provider call.
        $call = ProviderCall::query()->sole();

        $this->assertSame(SourceRegistry::GOOGLE_CLOUD_VISION, $call->source);
        $this->assertSame('vision.annotate', $call->operation);
        $this->assertSame('corr-1', $call->correlation_id);
        $this->assertSame($content->platform_account_id, $call->platform_account_id);
        $this->assertSame(CallOutcome::Success, $call->outcome);
        $this->assertSame(200, $call->http_status);
        $this->assertSame(1, $call->accepted_count);
        $this->assertSame(0, $call->rejected_count);

        $state = ProviderHealthState::query()->where('source', SourceRegistry::GOOGLE_CLOUD_VISION)->sole();
        $this->assertSame(ProviderStatus::Healthy, $state->status);
        $this->assertSame(0, $state->consecutive_failures);
        $this->assertNotNull($state->last_success_at);
    }

    public function test_authentication_failure_is_classified_recorded_and_counted(): void
    {
        $this->brand();
        $content = $this->imagePost();

        Http::fake([
            '93.184.216.34/*' => Http::response(self::IMAGE_BYTES),
            'vision.googleapis.com/*' => Http::response(['error' => ['status' => 'UNAUTHENTICATED']], 401),
        ]);

        try {
            $this->enrich($content);
            $this->fail('Expected a ProviderCallException for the 401 response.');
        } catch (ProviderCallException $e) {
            $this->assertSame(ErrorCategory::Authentication, $e->category);
            $this->assertSame(SourceRegistry::GOOGLE_CLOUD_VISION, $e->source);
        }

        $call = ProviderCall::query()->sole();
        $this->assertSame(CallOutcome::Failure, $call->outcome);
        $this->assertSame(ErrorCategory::Authentication, $call->error_category);
        $this->assertSame(401, $call->http_status);

        $state = ProviderHealthState::query()->where('source', SourceRegistry::GOOGLE_CLOUD_VISION)->sole();
        $this->assertSame(1, $state->consecutive_failures);

        // A second failing run increments the failure streak.
        try {
            $this->enrich($content, 'corr-2');
            $this->fail('Expected a ProviderCallException for the second 401 response.');
        } catch (ProviderCallException) {
            // Already classified above.
        }

        $this->assertSame(2, $state->refresh()->consecutive_failures);
        $this->assertNotNull($state->last_failure_at);
    }

    public function test_repeated_rate_limiting_raises_one_deduplicated_alert(): void
    {
        $this->brand();
        $content = $this->imagePost();

        Http::fake([
            '93.184.216.34/*' => Http::response(self::IMAGE_BYTES),
            'vision.googleapis.com/*' => Http::response('slow down', 429, ['Retry-After' => '30']),
        ]);

        foreach (['corr-1', 'corr-2'] as $correlationId) {
            try {
                $this->enrich($content, $correlationId);
                $this->fail('Expected a ProviderCallException for the 429 response.');
            } catch (ProviderCallException $e) {
                $this->assertSame(ErrorCategory::RateLimited, $e->category);
            }
        }

        $alerts = IngestionAlert::query()->where('alert_type', AlertType::RateLimitRisk->value)->get();

        $this->assertCount(1, $alerts); // deduplicated: one OPEN row per (type, source)
        $this->assertSame(2, $alerts->first()->count);
        $this->assertSame(SourceRegistry::GOOGLE_CLOUD_VISION, $alerts->first()->source);
        $this->assertNull($alerts->first()->resolved_at);
    }

    public function test_unconfigured_vision_is_skipped_and_never_called(): void
    {
        config(['services.google_vision.api_key' => null]);

        $this->brand();
        $content = $this->imagePost();

        Http::fake([
            '93.184.216.34/*' => Http::response(self::IMAGE_BYTES),
            'vision.googleapis.com/*' => Http::response(['responses' => []]),
        ]);

        $result = $this->enrich($content);

        // Missing credentials → outputs stay unavailable, never fabricated.
        $this->assertContains('vision:not-configured', $result['skipped']);
        $this->assertSame('completed-empty', $result['status']);
        $this->assertSame(0, RecognitionDetection::query()->count());
        $this->assertSame(0, ProviderCall::query()->count());

        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'vision.googleapis.com'));
    }

    public function test_vision_request_carries_header_key_and_inline_media_only(): void
    {
        $this->brand();
        $content = $this->imagePost();
        $this->fakeVision(['logoAnnotations' => [['description' => 'Lumiere', 'score' => 0.94]]]);

        $this->enrich($content);

        Http::assertSent(function (Request $request): bool {
            if (! str_contains($request->url(), 'vision.googleapis.com')) {
                return false;
            }

            $image = $request->data()['requests'][0]['image'] ?? [];

            return $request->hasHeader('X-Goog-Api-Key', 'test-vision-key')
                // The key never travels in the URL.
                && ! str_contains($request->url(), 'key=')
                // Media is sent inline (base64), never as a URL (DP-005).
                && ($image['content'] ?? null) === base64_encode(self::IMAGE_BYTES)
                && ! array_key_exists('source', $image)
                && ! str_contains($request->body(), self::MEDIA_URL);
        });
    }

    public function test_human_corrected_detection_survives_a_second_enrichment_run(): void
    {
        $this->brand();
        $content = $this->imagePost();
        $this->fakeVision(['logoAnnotations' => [['description' => 'Lumiere', 'score' => 0.94]]]);

        $this->enrich($content);

        $detection = RecognitionDetection::query()->sole();
        $detection->update([
            'assessment' => new ConfidenceAssessment(
                value: 'Maison Lumière',
                confidenceLevel: ConfidenceLevel::High,
                signals: ['human-review:corrected'],
                verificationStatus: VerificationStatus::HumanCorrected,
            ),
        ]);

        $result = $this->enrich($content, 'corr-2');

        // Human decision stands (DP-004): the AI re-run neither updates nor duplicates.
        $this->assertSame(0, $result['created']);
        $this->assertSame(0, $result['updated']);
        $this->assertSame(1, RecognitionDetection::query()->count());

        $detection->refresh();
        $this->assertSame(VerificationStatus::HumanCorrected, $detection->assessment->verificationStatus);
        $this->assertSame(['human-review:corrected'], $detection->assessment->signals);
    }
}
