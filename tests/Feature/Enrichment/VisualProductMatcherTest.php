<?php

namespace Tests\Feature\Enrichment;

use App\Modules\CRM\Models\Brand;
use App\Modules\CRM\Models\Campaign;
use App\Modules\CRM\Models\Creator;
use App\Modules\CRM\Models\PlatformAccount;
use App\Modules\CRM\Models\Product;
use App\Modules\CRM\Models\ProductReferencePhoto;
use App\Modules\CRM\Models\SeedingCampaign;
use App\Modules\CRM\Models\Shipment;
use App\Modules\Monitoring\Models\ContentItem;
use App\Modules\Monitoring\Models\Keyframe;
use App\Modules\Monitoring\Models\KeyframeEmbedding;
use App\Modules\Monitoring\Models\ProductPhotoEmbedding;
use App\Modules\Monitoring\Models\RecognitionDetection;
use App\Modules\Monitoring\Models\VisualMatchRun;
use App\Platform\Enrichment\VisualMatch\Contracts\EmbeddingProvider;
use App\Platform\Enrichment\VisualMatch\Support\VectorLiteral;
use App\Platform\Enrichment\VisualMatch\VisualProductMatcher;
use App\Platform\Ingestion\Exceptions\ProviderCallException;
use App\Platform\Ingestion\Models\ProviderHealthState;
use App\Platform\Ingestion\SourceRegistry;
use App\Platform\Ingestion\Support\ErrorCategory;
use App\Platform\Ingestion\Support\ProviderStatus;
use App\Shared\Enums\ConfidenceLevel;
use App\Shared\Enums\KeyframeKind;
use App\Shared\Enums\Platform;
use App\Shared\Enums\SectorLabel;
use App\Shared\Enums\SeedingCampaignStatus;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class VisualProductMatcherTest extends TestCase
{
    use RefreshDatabase;

    private VisualProductMatcherFakeProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'qds.ingestion.media_disk' => 'media',
            'qds.enrichment.visual_match.enabled' => true,
            'qds.enrichment.visual_match.model_version' => 'gemini-embedding-2',
            'qds.enrichment.visual_match.dimensions' => 3072,
            'qds.enrichment.visual_match.frame_budget' => 12,
            'qds.enrichment.visual_match.thresholds' => [
                'default' => ['auto' => 0.65, 'review' => 0.55, 'margin' => 0.05],
            ],
            // Frame prep subtleties are T12's tests; here any decodable JPEG passes.
            'qds.enrichment.visual_match.quality_filter.enabled' => false,
            'qds.enrichment.visual_match.dedup.enabled' => false,
            'qds.ai_budget.read_only' => false,
            'qds.ai_budget.capabilities.embedding.price_micro_usd_per_unit' => 120,
            'qds.ai_budget.capabilities.embedding.per_post_units' => 12,
            'qds.ai_budget.capabilities.embedding.tenant_daily_units' => 2000,
            'qds.ai_budget.capabilities.embedding.tenant_monthly_units' => 40000,
            'qds.ai_budget.capabilities.embedding.global_daily_units' => 50000,
            'qds.ai_budget.capabilities.embedding.global_daily_hard_units' => 100000,
            'qds.ai_budget.capabilities.embedding.global_monthly_units' => 1000000,
            'qds.ai_budget.capabilities.embedding.global_monthly_hard_units' => 2000000,
            'qds.ingestion.circuit_breaker.enabled' => false,
        ]);

        Storage::fake('media');

        $this->provider = new VisualProductMatcherFakeProvider;
        $this->swap(EmbeddingProvider::class, $this->provider);
    }

    /** @return array{0: ContentItem, 1: Creator} */
    private function wiredContent(): array
    {
        $creator = Creator::factory()->create();
        $account = PlatformAccount::factory()->forCreator($creator)->onPlatform(Platform::Instagram)->create();
        $content = ContentItem::factory()->create([
            'platform_account_id' => $account->id,
            'platform' => Platform::Instagram,
            'published_at' => CarbonImmutable::now()->subDay(),
        ]);

        return [$content, $creator];
    }

    /** Product with one embedded reference photo + an in-window ACTIVE-campaign shipment. */
    private function shippedProduct(Creator $creator, array $photoVector): Product
    {
        $brand = Brand::factory()->create(['name' => 'Glossier']);
        $product = Product::factory()->create(['brand_id' => $brand->id, 'name' => 'You Perfume', 'category' => SectorLabel::Tech]);
        $seeding = SeedingCampaign::factory()->create([
            'brand_id' => $brand->id,
            'campaign_id' => Campaign::factory()->create(['brand_id' => $brand->id])->id,
            'product_id' => $product->id,
            'status' => SeedingCampaignStatus::Active,
        ]);
        Shipment::factory()->create([
            'seeding_campaign_id' => $seeding->id,
            'creator_id' => $creator->id,
            'product_id' => $product->id,
            'shipped_at' => CarbonImmutable::now()->subDays(5),
            'delivered_at' => CarbonImmutable::now()->subDays(3),
        ]);

        $photo = ProductReferencePhoto::factory()->create(['product_id' => $product->id]);
        ProductPhotoEmbedding::factory()->create([
            'product_reference_photo_id' => $photo->id,
            'model_version' => 'gemini-embedding-2',
            'embedding' => VectorLiteral::fromArray(array_pad($photoVector, 3072, 0.0)),
        ]);

        return $product;
    }

    /** Stores frame bytes on the media disk, registers the fake vector, returns the row. */
    private function storeFrame(ContentItem $content, int $ordinal, ?int $timestampMs, array $vector, string $extension = 'jpg'): Keyframe
    {
        $bytes = $extension === 'jpg' ? $this->jpegBytes($ordinal) : 'not-an-image-'.$ordinal;
        $path = "tenants/{$this->defaultTenant->id}/keyframes/{$content->id}/frame-{$ordinal}.{$extension}";
        Storage::disk('media')->put($path, $bytes);

        $this->provider->vectors[hash('sha256', $bytes)] = array_pad($vector, 3072, 0.0);

        return Keyframe::factory()->create([
            'owner_type' => $content->getMorphClass(),
            'owner_id' => $content->id,
            'ordinal' => $ordinal,
            'timestamp_ms' => $timestampMs,
            'kind' => $timestampMs === null ? KeyframeKind::SourceImage : KeyframeKind::VideoSample,
            'storage_disk' => 'media',
            'storage_path' => $path,
            'checksum' => hash('sha256', $bytes),
        ]);
    }

    private function jpegBytes(int $seed): string
    {
        $image = imagecreatetruecolor(32, 32);
        $shade = 40 + ($seed * 37) % 180;
        imagefilledrectangle($image, 0, 0, 31, 31, imagecolorallocate($image, $shade, $shade, ($shade * 7) % 255));
        ob_start();
        imagejpeg($image);
        imagedestroy($image);

        return (string) ob_get_clean();
    }

    public function test_kill_switch_off_is_a_true_noop(): void
    {
        config(['qds.enrichment.visual_match.enabled' => false]);
        [$content] = $this->wiredContent();

        $this->assertSame('skipped:disabled', app(VisualProductMatcher::class)->enrich($content, 'corr-vm-1'));
        $this->assertSame(0, VisualMatchRun::query()->count());
        $this->assertSame(0, $this->provider->calls);
    }

    public function test_unconfigured_provider_skips(): void
    {
        $this->provider->configured = false;
        [$content] = $this->wiredContent();

        $this->assertSame('skipped:not-configured', app(VisualProductMatcher::class)->enrich($content, 'corr-vm-1'));
        $this->assertSame(0, VisualMatchRun::query()->count());
    }

    public function test_unresolvable_creator_skips(): void
    {
        $account = PlatformAccount::factory()->create(['creator_id' => null, 'platform' => Platform::Instagram]);
        $content = ContentItem::factory()->create(['platform_account_id' => $account->id, 'platform' => Platform::Instagram]);

        $this->assertSame('skipped:no-creator', app(VisualProductMatcher::class)->enrich($content, 'corr-vm-1'));
    }

    public function test_empty_candidate_set_skips_free_and_counts(): void
    {
        [$content] = $this->wiredContent();

        $this->assertSame('skipped:no-candidates', app(VisualProductMatcher::class)->enrich($content, 'corr-vm-1'));
        $this->assertSame(0, VisualMatchRun::query()->count());
        $this->assertSame(0, $this->provider->calls);
        $this->assertDatabaseHas('ai_usage_counters', [
            'capability' => 'embedding',
            'tenant_id' => $this->defaultTenant->id,
            'posts_skipped_no_candidates' => 1,
        ]);
    }

    public function test_empty_keyframe_set_skips(): void
    {
        [$content, $creator] = $this->wiredContent();
        $this->shippedProduct($creator, [1.0]);

        $this->assertSame('skipped:no-frames', app(VisualProductMatcher::class)->enrich($content, 'corr-vm-1'));
        $this->assertSame(0, VisualMatchRun::query()->count());
    }

    public function test_auto_match_writes_the_detection_and_the_audit_run(): void
    {
        [$content, $creator] = $this->wiredContent();
        $product = $this->shippedProduct($creator, [1.0]);
        $this->storeFrame($content, 0, 1000, [1.0]);
        $this->storeFrame($content, 1, 5000, [1.0]);

        $marker = app(VisualProductMatcher::class)->enrich($content, 'corr-vm-1');

        $this->assertSame('completed:matched=1,review=0,rejected=0', $marker);
        $this->assertSame(2, $this->provider->calls);

        $this->assertDatabaseHas('recognition_detections', [
            'content_item_id' => $content->id,
            'recognition_type' => 'VISUAL_PRODUCT',
            'provider_label' => 'visual-product:'.$product->id,
            'product_id' => $product->id,
        ]);
        $this->assertDatabaseHas('visual_match_runs', [
            'content_item_id' => $content->id,
            'correlation_id' => 'corr-vm-1',
            'outcome' => 'matched',
            'priority' => 'high',
            'frames_available' => 2,
            'frames_processed' => 2,
            'embedding_calls' => 2,
            'cache_hits' => 0,
            'candidates_checked' => 1,
            'needs_verification' => false,
        ]);
        $this->assertDatabaseHas('visual_match_candidates', ['product_id' => $product->id, 'band' => 'auto', 'rank' => 1]);
        $this->assertSame(2, KeyframeEmbedding::query()->count());
        $this->assertDatabaseHas('ai_usage_counters', [
            'capability' => 'embedding', 'tenant_id' => $this->defaultTenant->id,
            'units' => 2, 'posts_processed' => 1,
        ]);
    }

    public function test_reruns_ride_the_embedding_cache(): void
    {
        [$content, $creator] = $this->wiredContent();
        $this->shippedProduct($creator, [1.0]);
        $this->storeFrame($content, 0, 1000, [1.0]);
        $this->storeFrame($content, 1, 5000, [1.0]);

        $matcher = app(VisualProductMatcher::class);
        $matcher->enrich($content, 'corr-vm-1');
        $marker = $matcher->enrich($content, 'corr-vm-2');

        $this->assertSame('completed:matched=1,review=0,rejected=0', $marker);
        $this->assertSame(2, $this->provider->calls); // nothing re-billed
        $this->assertSame(1, RecognitionDetection::query()->count());
        $this->assertDatabaseHas('visual_match_runs', ['correlation_id' => 'corr-vm-2', 'cache_hits' => 2, 'embedding_calls' => 0]);
    }

    /**
     * Coverage gap fix (self-review, task-19-report): withdrawSupport is
     * unit-tested in isolation (T18's VisualMatchWriterTest), but the
     * matcher's own wiring of it — an AUTO-matched product that later
     * rejects (catalog/model drift: the reference photo is swapped) — was
     * never exercised end-to-end. DP-004: downgraded to LOW, never deleted.
     */
    public function test_withdraw_support_downgrades_a_previously_auto_matched_product_on_catalog_drift(): void
    {
        [$content, $creator] = $this->wiredContent();
        $product = $this->shippedProduct($creator, [1.0]);
        $this->storeFrame($content, 0, 1000, [1.0]);
        $this->storeFrame($content, 1, 5000, [1.0]);

        $matcher = app(VisualProductMatcher::class);
        $first = $matcher->enrich($content, 'corr-vm-1');

        $this->assertSame('completed:matched=1,review=0,rejected=0', $first);
        $detection = RecognitionDetection::query()->firstOrFail();
        $this->assertSame(ConfidenceLevel::High, $detection->assessment->confidenceLevel);

        // The brand replaces its reference photo with something visually
        // unrelated. The frames are unchanged (still cached), so the rerun
        // compares the SAME frame vectors against the NEW photo vector.
        $photo = ProductReferencePhoto::query()->where('product_id', $product->id)->firstOrFail();
        ProductPhotoEmbedding::query()
            ->where('product_reference_photo_id', $photo->id)
            ->update(['embedding' => VectorLiteral::fromArray(array_pad([0.0, 1.0], 3072, 0.0))]);

        $second = $matcher->enrich($content, 'corr-vm-2');

        $this->assertSame('completed:no-match', $second);
        $this->assertSame(2, $this->provider->calls); // both frames still cached — nothing re-billed

        $detection->refresh();
        $this->assertSame(ConfidenceLevel::Low, $detection->assessment->confidenceLevel);
        $this->assertContains('visual-support-withdrawn', $detection->assessment->signals);
        $this->assertSame(1, RecognitionDetection::query()->count()); // downgraded, never deleted (DP-004)
    }

    public function test_single_frame_hit_lands_review_and_flags_verification(): void
    {
        [$content, $creator] = $this->wiredContent();
        $this->shippedProduct($creator, [1.0]);
        $this->storeFrame($content, 0, 1000, [1.0]);

        $marker = app(VisualProductMatcher::class)->enrich($content, 'corr-vm-1');

        $this->assertSame('completed:matched=0,review=1,rejected=0', $marker);
        $detection = RecognitionDetection::query()->firstOrFail();
        $this->assertSame(ConfidenceLevel::Low, $detection->assessment->confidenceLevel);
        $this->assertTrue($detection->assessment->needsHumanReview());
        $this->assertDatabaseHas('visual_match_runs', ['outcome' => 'review', 'needs_verification' => true]);
    }

    /**
     * Coverage gap fix (self-review, task-19-report): the brief's suite
     * never exercised the clean NO_MATCH outcome end-to-end — "we looked
     * properly and did not see it" (full coverage, similarity below the
     * review threshold). An orthogonal frame vector scores 0 cosine
     * similarity against the product's reference photo, well under 0.55.
     */
    public function test_clean_no_match_when_similarity_is_below_review_threshold(): void
    {
        [$content, $creator] = $this->wiredContent();
        $this->shippedProduct($creator, [1.0]); // reference photo hot at index 0
        $this->storeFrame($content, 0, 1000, [0.0, 1.0]); // frame hot at index 1: orthogonal, similarity 0

        $marker = app(VisualProductMatcher::class)->enrich($content, 'corr-vm-1');

        $this->assertSame('completed:no-match', $marker);
        $this->assertSame(0, RecognitionDetection::query()->count());
        $this->assertDatabaseHas('visual_match_runs', [
            'outcome' => 'no_match',
            // In-window shipment with no visual support still needs a human look.
            'needs_verification' => true,
        ]);
        $this->assertDatabaseHas('visual_match_candidates', ['band' => 'reject', 'rejection_reason' => 'below-review-threshold']);
    }

    public function test_exhausted_global_hard_budget_skips_before_any_call(): void
    {
        config(['qds.ai_budget.capabilities.embedding.global_daily_hard_units' => 0]);
        [$content, $creator] = $this->wiredContent();
        $this->shippedProduct($creator, [1.0]);
        $this->storeFrame($content, 0, 1000, [1.0]);

        $marker = app(VisualProductMatcher::class)->enrich($content, 'corr-vm-1');

        $this->assertSame('skipped:budget-exhausted', $marker);
        $this->assertSame(0, $this->provider->calls);
        $this->assertSame(0, RecognitionDetection::query()->count());
        $this->assertDatabaseHas('visual_match_runs', ['outcome' => 'skipped_budget', 'needs_verification' => false]);
        $this->assertDatabaseHas('ai_usage_counters', ['capability' => 'embedding', 'posts_skipped_budget' => 1]);
    }

    public function test_read_only_mode_stops_spend_with_its_own_marker(): void
    {
        config(['qds.ai_budget.read_only' => true]);
        [$content, $creator] = $this->wiredContent();
        $this->shippedProduct($creator, [1.0]);
        $this->storeFrame($content, 0, 1000, [1.0]);

        $marker = app(VisualProductMatcher::class)->enrich($content, 'corr-vm-1');

        $this->assertSame('skipped:ai-read-only', $marker);
        $this->assertSame(0, $this->provider->calls);
        $this->assertDatabaseHas('visual_match_runs', ['outcome' => 'skipped_read_only']);
    }

    public function test_open_circuit_breaker_skips_before_spending(): void
    {
        config(['qds.ingestion.circuit_breaker.enabled' => true, 'qds.ingestion.circuit_breaker.cooldown_minutes' => 60]);
        ProviderHealthState::query()->create([
            'source' => SourceRegistry::GOOGLE_GEMINI_EMBEDDINGS,
            'status' => ProviderStatus::Failing,
            'consecutive_failures' => 3,
            'last_failure_at' => CarbonImmutable::now()->subMinutes(5),
            'last_error_category' => ErrorCategory::Authentication,
        ]);
        [$content, $creator] = $this->wiredContent();
        $this->shippedProduct($creator, [1.0]);
        $this->storeFrame($content, 0, 1000, [1.0]);

        $marker = app(VisualProductMatcher::class)->enrich($content, 'corr-vm-1');

        $this->assertSame('skipped:provider-unavailable', $marker);
        $this->assertSame(0, $this->provider->calls);
        $this->assertDatabaseHas('visual_match_runs', ['outcome' => 'skipped_provider']);
    }

    public function test_transient_provider_failure_is_a_marker_never_a_crash(): void
    {
        $this->provider->failAll = true;
        [$content, $creator] = $this->wiredContent();
        $this->shippedProduct($creator, [1.0]);
        $this->storeFrame($content, 0, 1000, [1.0]);

        $marker = app(VisualProductMatcher::class)->enrich($content, 'corr-vm-1');

        $this->assertSame('skipped:provider-error', $marker);
        $this->assertSame(0, RecognitionDetection::query()->count());
        $this->assertDatabaseHas('visual_match_runs', ['outcome' => 'skipped_provider', 'embedding_calls' => 0]);
    }

    public function test_unusable_frames_yield_inconclusive_with_the_verification_flag(): void
    {
        [$content, $creator] = $this->wiredContent();
        $this->shippedProduct($creator, [1.0]);
        $this->storeFrame($content, 0, 1000, [1.0], extension: 'bin');

        $marker = app(VisualProductMatcher::class)->enrich($content, 'corr-vm-1');

        $this->assertSame('completed:inconclusive', $marker);
        $this->assertSame(0, $this->provider->calls);
        $this->assertSame(0, RecognitionDetection::query()->count());
        $this->assertDatabaseHas('visual_match_runs', [
            'outcome' => 'inconclusive',
            'frames_available' => 1,
            'frames_processed' => 0,
            'frames_skipped_format' => 1,
            // In-window shipment + no clean look → sub-project D verifies.
            'needs_verification' => true,
        ]);
    }
}

/** Deterministic container stub for the provider seam (spec §15). */
final class VisualProductMatcherFakeProvider implements EmbeddingProvider
{
    /** @var array<string, list<float>> sha256(bytes) => vector */
    public array $vectors = [];

    public bool $configured = true;

    public bool $failAll = false;

    public int $calls = 0;

    public function embedImage(string $bytes, string $mimeType): array
    {
        if ($this->failAll) {
            throw new ProviderCallException(SourceRegistry::GOOGLE_GEMINI_EMBEDDINGS, ErrorCategory::UpstreamError, 'upstream boom', 500);
        }

        $this->calls++;

        return $this->vectors[hash('sha256', $bytes)] ?? array_pad([1.0], 3072, 0.0);
    }

    public function modelVersion(): string
    {
        return 'gemini-embedding-2';
    }

    public function isConfigured(): bool
    {
        return $this->configured;
    }
}
