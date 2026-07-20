<?php

namespace Tests\Feature\Enrichment;

use App\Modules\CRM\Models\Brand;
use App\Modules\CRM\Models\Product;
use App\Modules\Monitoring\Models\ContentItem;
use App\Modules\Monitoring\Models\RecognitionDetection;
use App\Modules\Monitoring\Models\Story;
use App\Platform\Enrichment\VlmVerification\Banding\VlmBandResult;
use App\Platform\Enrichment\VlmVerification\VlmDetectionWriter;
use App\Platform\Enrichment\VlmVerification\Verdicts\CandidateVerdict;
use App\Shared\Enums\ConfidenceLevel;
use App\Shared\Enums\RecognitionType;
use App\Shared\Enums\VerificationStatus;
use App\Shared\Enums\VlmBand;
use App\Shared\ValueObjects\ConfidenceAssessment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class VlmDetectionWriterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Pin the thresholds so the threshold signal is deterministic.
        config(['qds.enrichment.vlm.thresholds' => [
            'auto' => 0.85, 'review' => 0.60, 'margin' => 0.10,
        ]]);
    }

    /** @return array{0: ContentItem, 1: Product} content + catalog product */
    private function wired(): array
    {
        $content = ContentItem::factory()->create();
        $brand = Brand::factory()->create(['name' => 'Glossier']);
        $product = Product::factory()->create(['brand_id' => $brand->id, 'name' => 'You Perfume']);

        return [$content, $product];
    }

    /** @param list<int|null> $frames */
    private function bandResult(Product $product, VlmBand $band, bool $captionEcho = false, array $frames = [1000, 5000]): VlmBandResult
    {
        return new VlmBandResult(
            verdict: new CandidateVerdict(
                productKey: 'P'.$product->id,
                productId: $product->id,
                visible: true,
                spoken: false,
                giftingCue: true,
                confidence: 0.91,
                frameTimestampsMs: $frames,
                rationale: 'Bottle visible on the vanity.',
            ),
            band: $band,
            rejectionReason: $band === VlmBand::Review ? 'margin-ambiguous' : null,
            captionEcho: $captionEcho,
        );
    }

    public function test_auto_band_writes_a_high_detection_with_the_frozen_signal_trail(): void
    {
        [$content, $product] = $this->wired();

        $written = app(VlmDetectionWriter::class)->write($content, $this->bandResult($product, VlmBand::Auto), 'gemini-3.5-flash');

        $this->assertSame(1, $written);

        $detection = RecognitionDetection::query()
            ->where('content_item_id', $content->id)
            ->where('recognition_type', RecognitionType::VlmProduct)
            ->firstOrFail();

        $this->assertSame('vlm-product:'.$product->id, $detection->provider_label);
        $this->assertSame('Glossier', $detection->detected_brand);
        $this->assertSame('You Perfume', $detection->detected_product);
        $this->assertSame($product->id, $detection->product_id);
        $this->assertNull($detection->detected_text);

        $this->assertSame('Glossier', $detection->assessment->value);
        $this->assertSame(ConfidenceLevel::High, $detection->assessment->confidenceLevel);
        $this->assertSame(VerificationStatus::AiAssessed, $detection->assessment->verificationStatus);
        $this->assertSame([
            'vlm-product-match:You Perfume',
            'vlm-confidence:0.91',
            'vlm-visible:true',
            'vlm-spoken:false',
            'vlm-gifting-cue:true',
            'vlm-frame:t=1000ms',
            'vlm-frame:t=5000ms',
            'vlm-threshold:auto=0.85:review=0.60:margin=0.10',
            'vlm-model:gemini-3.5-flash',
        ], $detection->assessment->signals);

        $this->assertSame('SRC-google-gemini-vlm', $detection->provenance->source);
        $this->assertSame('vlm-verification-v1', $detection->provenance->sourceVersion);
    }

    public function test_review_band_writes_low_and_queues_for_review(): void
    {
        [$content, $product] = $this->wired();

        app(VlmDetectionWriter::class)->write($content, $this->bandResult($product, VlmBand::Review), 'gemini-3.5-flash');

        $detection = RecognitionDetection::query()->firstOrFail();
        $this->assertSame(ConfidenceLevel::Low, $detection->assessment->confidenceLevel);
        $this->assertTrue($detection->assessment->needsHumanReview());
    }

    public function test_caption_echo_appends_its_signal(): void
    {
        [$content, $product] = $this->wired();

        app(VlmDetectionWriter::class)->write($content, $this->bandResult($product, VlmBand::Review, captionEcho: true), 'gemini-3.5-flash');

        $detection = RecognitionDetection::query()->firstOrFail();
        $this->assertContains('vlm-caption-echo', $detection->assessment->signals);
        // The conditional signal appends AFTER the frozen core trail.
        $this->assertSame('vlm-caption-echo', $detection->assessment->signals[count($detection->assessment->signals) - 1]);
    }

    public function test_frame_signals_cap_at_the_first_five(): void
    {
        [$content, $product] = $this->wired();

        app(VlmDetectionWriter::class)->write(
            $content,
            $this->bandResult($product, VlmBand::Auto, frames: [0, 1000, 2000, 3000, 4000, 5000, 6000]),
            'gemini-3.5-flash',
        );

        $frameSignals = array_values(array_filter(
            RecognitionDetection::query()->firstOrFail()->assessment->signals,
            fn (string $signal): bool => str_starts_with($signal, 'vlm-frame:'),
        ));

        $this->assertSame([
            'vlm-frame:t=0ms', 'vlm-frame:t=1000ms', 'vlm-frame:t=2000ms',
            'vlm-frame:t=3000ms', 'vlm-frame:t=4000ms',
        ], $frameSignals);
    }

    public function test_null_timestamp_citations_write_no_frame_signals_but_the_detection_still_lands(): void
    {
        // All-unstamped citations (carousel lockout fix): the null entries
        // are valid frame references for BANDING, but the signal trail
        // stays timestamped-only — an AUTO verdict still writes at HIGH
        // with zero vlm-frame entries.
        [$content, $product] = $this->wired();

        $written = app(VlmDetectionWriter::class)->write(
            $content,
            $this->bandResult($product, VlmBand::Auto, frames: [null, null]),
            'gemini-3.5-flash',
        );

        $this->assertSame(1, $written);
        $detection = RecognitionDetection::query()->firstOrFail();
        $this->assertSame(ConfidenceLevel::High, $detection->assessment->confidenceLevel);
        $this->assertSame([], array_values(array_filter(
            $detection->assessment->signals,
            fn (string $signal): bool => str_starts_with($signal, 'vlm-frame:'),
        )));
    }

    public function test_rerun_updates_the_same_row_and_identity_fields_seed_only_on_create(): void
    {
        [$content, $product] = $this->wired();
        $writer = app(VlmDetectionWriter::class);

        $writer->write($content, $this->bandResult($product, VlmBand::Review), 'gemini-3.5-flash');

        // Simulate a later catalog rename on the EXISTING AI row: the
        // identity-adjacent fields must NOT re-seed on the next pass.
        RecognitionDetection::query()->update(['detected_brand' => 'Renamed Brand']);

        $writer->write($content, $this->bandResult($product, VlmBand::Auto), 'gemini-3.5-flash');

        $this->assertSame(1, RecognitionDetection::query()->count());
        $detection = RecognitionDetection::query()->firstOrFail();
        $this->assertSame('Renamed Brand', $detection->detected_brand);
        $this->assertSame('Renamed Brand', $detection->assessment->value);
        $this->assertSame(ConfidenceLevel::High, $detection->assessment->confidenceLevel);
    }

    public function test_human_touched_rows_are_never_overwritten(): void
    {
        [$content, $product] = $this->wired();
        RecognitionDetection::factory()->create([
            'content_item_id' => $content->id,
            'recognition_type' => RecognitionType::VlmProduct,
            'provider_label' => 'vlm-product:'.$product->id,
            'detected_brand' => 'Corrected Brand',
            'product_id' => null,
            'assessment' => new ConfidenceAssessment('Corrected Brand', ConfidenceLevel::High, ['human-corrected'], VerificationStatus::HumanCorrected),
        ]);

        $written = app(VlmDetectionWriter::class)->write($content, $this->bandResult($product, VlmBand::Auto), 'gemini-3.5-flash');

        $this->assertSame(0, $written);
        $detection = RecognitionDetection::query()->firstOrFail();
        $this->assertSame('Corrected Brand', $detection->detected_brand);
        $this->assertSame(VerificationStatus::HumanCorrected, $detection->assessment->verificationStatus);
    }

    public function test_a_vanished_catalog_product_writes_nothing(): void
    {
        [$content, $product] = $this->wired();
        $result = $this->bandResult($product, VlmBand::Auto);
        $product->delete();

        $written = app(VlmDetectionWriter::class)->write($content, $result, 'gemini-3.5-flash');

        $this->assertSame(0, $written);
        $this->assertSame(0, RecognitionDetection::query()->count());
    }

    public function test_concurrent_insert_is_recovered_without_duplicates(): void
    {
        [$content, $product] = $this->wired();

        // PostgreSQL aborts the WHOLE current transaction on a unique
        // violation — the writer's immediate recovery re-query only works
        // outside an already-open, now-poisoned transaction. This ONE test
        // escapes RefreshDatabase's wrapping transaction to genuine
        // autocommit (matching production) and cleans up by hand — the
        // same escape VisualMatchWriterTest documents in full.
        DB::commit();

        try {
            // Simulate the race: just before OUR insert commits, a
            // concurrent pass lands the same identity row (the partial
            // unique index is the backstop).
            $raced = false;
            RecognitionDetection::creating(function () use (&$raced, $content, $product): void {
                if ($raced) {
                    return;
                }
                $raced = true;

                DB::table('recognition_detections')->insert([
                    'tenant_id' => $this->defaultTenant->id,
                    'content_item_id' => $content->id,
                    'recognition_type' => 'VLM_PRODUCT',
                    'provider_label' => 'vlm-product:'.$product->id,
                    'detected_brand' => 'Glossier',
                    'detected_product' => 'You Perfume',
                    'product_id' => $product->id,
                    'assessment' => json_encode([
                        'value' => 'Glossier', 'confidenceLevel' => 'HIGH',
                        'signals' => ['vlm-product-match:You Perfume'],
                        'verificationStatus' => 'AI_ASSESSED',
                    ]),
                    'provenance' => json_encode([
                        'source' => 'SRC-google-gemini-vlm',
                        'fetchedAt' => now()->toIso8601String(),
                        'sourceVersion' => 'vlm-verification-v1',
                    ]),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });

            $written = app(VlmDetectionWriter::class)->write($content, $this->bandResult($product, VlmBand::Auto), 'gemini-3.5-flash');

            $this->assertSame(0, $written); // the concurrent insert already recorded it
            $this->assertSame(1, RecognitionDetection::query()->count());
        } finally {
            RecognitionDetection::flushEventListeners();

            // Everything above was committed for real (autocommit) — undo
            // it by hand so later tests find the database clean. The tenant
            // row itself is left for RefreshDatabase's re-migration (see
            // VisualMatchWriterTest for the full rationale).
            RecognitionDetection::query()->where('content_item_id', $content->id)->delete();
            $content->delete();
            $brandId = $product->brand_id;
            $product->delete();
            Brand::query()->whereKey($brandId)->delete();
        }
    }

    public function test_withdraw_support_downgrades_an_ai_row_to_review_once(): void
    {
        [$content, $product] = $this->wired();
        $writer = app(VlmDetectionWriter::class);
        $writer->write($content, $this->bandResult($product, VlmBand::Auto), 'gemini-3.5-flash');

        $this->assertSame(1, $writer->withdrawSupport($content, $product->id));

        $detection = RecognitionDetection::query()->firstOrFail();
        $this->assertSame(ConfidenceLevel::Low, $detection->assessment->confidenceLevel);
        $this->assertContains('vlm-support-withdrawn', $detection->assessment->signals);
        $this->assertTrue($detection->assessment->needsHumanReview());

        // Idempotent: a second withdraw changes nothing.
        $this->assertSame(0, $writer->withdrawSupport($content, $product->id));
    }

    public function test_withdraw_support_skips_missing_and_human_rows(): void
    {
        [$content, $product] = $this->wired();
        $writer = app(VlmDetectionWriter::class);

        $this->assertSame(0, $writer->withdrawSupport($content, $product->id)); // nothing to downgrade

        RecognitionDetection::factory()->create([
            'content_item_id' => $content->id,
            'recognition_type' => RecognitionType::VlmProduct,
            'provider_label' => 'vlm-product:'.$product->id,
            'detected_brand' => 'Glossier',
            'assessment' => new ConfidenceAssessment('Glossier', ConfidenceLevel::High, ['human-approved'], VerificationStatus::HumanReviewed),
        ]);

        $this->assertSame(0, $writer->withdrawSupport($content, $product->id));
        $this->assertSame(VerificationStatus::HumanReviewed, RecognitionDetection::query()->firstOrFail()->assessment->verificationStatus);
    }

    public function test_story_targets_key_on_story_id(): void
    {
        $story = Story::factory()->create();
        $brand = Brand::factory()->create(['name' => 'Glossier']);
        $product = Product::factory()->create(['brand_id' => $brand->id, 'name' => 'You Perfume']);

        $written = app(VlmDetectionWriter::class)->write($story, $this->bandResult($product, VlmBand::Review), 'gemini-3.5-flash');

        $this->assertSame(1, $written);
        $this->assertDatabaseHas('recognition_detections', [
            'story_id' => $story->id,
            'content_item_id' => null,
            'recognition_type' => 'VLM_PRODUCT',
            'provider_label' => 'vlm-product:'.$product->id,
        ]);
    }
}
