<?php

namespace Tests\Feature\Enrichment;

use App\Modules\CRM\Models\Brand;
use App\Modules\CRM\Models\Product;
use App\Modules\Monitoring\Models\ContentItem;
use App\Modules\Monitoring\Models\Keyframe;
use App\Modules\Monitoring\Models\RecognitionDetection;
use App\Modules\Monitoring\Models\Story;
use App\Platform\Enrichment\VisualMatch\Candidates\Candidate;
use App\Platform\Enrichment\VisualMatch\Matching\BandResult;
use App\Platform\Enrichment\VisualMatch\Matching\FrameScore;
use App\Platform\Enrichment\VisualMatch\VisualMatchWriter;
use App\Shared\Enums\ConfidenceLevel;
use App\Shared\Enums\RecognitionType;
use App\Shared\Enums\SectorLabel;
use App\Shared\Enums\VerificationStatus;
use App\Shared\Enums\VisualMatchBand;
use App\Shared\ValueObjects\ConfidenceAssessment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class VisualMatchWriterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Pin the resolver inputs so the threshold signal is deterministic.
        config(['qds.enrichment.visual_match.thresholds' => [
            'default' => ['auto' => 0.65, 'review' => 0.55, 'margin' => 0.05],
            'BEAUTY' => ['auto' => 0.70],
        ]]);
    }

    /** @return array{0: ContentItem, 1: Product} content with three stored keyframes + catalog product */
    private function wired(?SectorLabel $category = null): array
    {
        $content = ContentItem::factory()->create();

        foreach ([0, 1, 2] as $ordinal) {
            Keyframe::factory()->create([
                'owner_type' => $content->getMorphClass(),
                'owner_id' => $content->id,
                'ordinal' => $ordinal,
                'timestamp_ms' => $ordinal * 1000,
            ]);
        }

        $brand = Brand::factory()->create(['name' => 'Glossier']);
        $product = Product::factory()->create(['brand_id' => $brand->id, 'name' => 'You Perfume', 'category' => $category]);

        return [$content, $product];
    }

    private function candidate(Product $product, ?SectorLabel $category = null): Candidate
    {
        return new Candidate(
            productId: $product->id, productLabel: $product->name, brandName: 'Glossier',
            category: $category, source: 'shipment', shipmentInWindow: true,
            seedingCampaignId: null, shipmentAnchorAt: null, shipmentAgeDays: 3,
            hasEmbeddedPhotos: true,
        );
    }

    private function bandResult(Candidate $candidate, VisualMatchBand $band): BandResult
    {
        return new BandResult(
            candidate: $candidate, band: $band,
            supportingFrames: [
                new FrameScore(keyframeId: 1, ordinal: 1, timestampMs: 1000, similarity: 0.71, photoId: 11, representedFrames: 1),
                new FrameScore(keyframeId: 2, ordinal: 2, timestampMs: 5000, similarity: 0.68, photoId: 11, representedFrames: 1),
            ],
            supportCount: 2, marginToRunnerUp: null, rejectionReason: null,
            firstSupportMs: 1000, lastSupportMs: 5000, estimatedVisibleMs: 8000,
            bestSimilarity: 0.71,
        );
    }

    public function test_auto_band_writes_a_high_detection_with_the_frozen_signal_trail(): void
    {
        [$content, $product] = $this->wired();

        $written = app(VisualMatchWriter::class)->write($content, $this->bandResult($this->candidate($product), VisualMatchBand::Auto), 'gemini-embedding-2');

        $this->assertSame(1, $written);

        $detection = RecognitionDetection::query()
            ->where('content_item_id', $content->id)
            ->where('recognition_type', RecognitionType::VisualProduct)
            ->firstOrFail();

        $this->assertSame('visual-product:'.$product->id, $detection->provider_label);
        $this->assertSame('Glossier', $detection->detected_brand);
        $this->assertSame('You Perfume', $detection->detected_product);
        $this->assertSame($product->id, $detection->product_id);
        $this->assertNull($detection->detected_text);

        $this->assertSame('Glossier', $detection->assessment->value);
        $this->assertSame(ConfidenceLevel::High, $detection->assessment->confidenceLevel);
        $this->assertSame(VerificationStatus::AiAssessed, $detection->assessment->verificationStatus);
        $this->assertSame([
            'visual-product-match:You Perfume',
            'visual-frames-supporting:2/3',
            'visual-frame:t=1000ms:sim=0.71',
            'visual-frame:t=5000ms:sim=0.68',
            'visual-threshold:default:auto=0.65:review=0.55:margin=0.05',
            'embedding-model:gemini-embedding-2',
        ], $detection->assessment->signals);

        $this->assertSame('SRC-google-gemini-embeddings', $detection->provenance->source);
        $this->assertSame('visual-match-v1', $detection->provenance->sourceVersion);
    }

    public function test_review_band_writes_low_and_category_thresholds_stamp_the_signal(): void
    {
        [$content, $product] = $this->wired(SectorLabel::Beauty);

        app(VisualMatchWriter::class)->write($content, $this->bandResult($this->candidate($product, SectorLabel::Beauty), VisualMatchBand::Review), 'gemini-embedding-2');

        $detection = RecognitionDetection::query()->firstOrFail();
        $this->assertSame(ConfidenceLevel::Low, $detection->assessment->confidenceLevel);
        $this->assertTrue($detection->assessment->needsHumanReview());
        $this->assertContains('visual-threshold:BEAUTY:auto=0.70:review=0.55:margin=0.05', $detection->assessment->signals);
    }

    public function test_rerun_updates_the_same_row_and_identity_fields_seed_only_on_create(): void
    {
        [$content, $product] = $this->wired();
        $writer = app(VisualMatchWriter::class);

        $writer->write($content, $this->bandResult($this->candidate($product), VisualMatchBand::Review), 'gemini-embedding-2');

        // Simulate a later catalog rename on the EXISTING AI row: the
        // identity-adjacent fields must NOT re-seed on the next pass.
        RecognitionDetection::query()->update(['detected_brand' => 'Renamed Brand']);

        $writer->write($content, $this->bandResult($this->candidate($product), VisualMatchBand::Auto), 'gemini-embedding-2');

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
            'recognition_type' => RecognitionType::VisualProduct,
            'provider_label' => 'visual-product:'.$product->id,
            'detected_brand' => 'Corrected Brand',
            'product_id' => null,
            'assessment' => new ConfidenceAssessment('Corrected Brand', ConfidenceLevel::High, ['human-corrected'], VerificationStatus::HumanCorrected),
        ]);

        $written = app(VisualMatchWriter::class)->write($content, $this->bandResult($this->candidate($product), VisualMatchBand::Auto), 'gemini-embedding-2');

        $this->assertSame(0, $written);
        $detection = RecognitionDetection::query()->firstOrFail();
        $this->assertSame('Corrected Brand', $detection->detected_brand);
        $this->assertSame(VerificationStatus::HumanCorrected, $detection->assessment->verificationStatus);
    }

    public function test_concurrent_insert_is_recovered_without_duplicates(): void
    {
        [$content, $product] = $this->wired();

        // PostgreSQL aborts the WHOLE current transaction on a unique
        // violation (not just the failed statement) — recovering via an
        // immediate re-query on the SAME connection only works if that
        // statement is NOT inside an already-open, now-poisoned
        // transaction. RefreshDatabase wraps every test in one outer
        // transaction; wrapping the writer's own insert in a savepoint to
        // compensate would ALSO roll back this same-connection "concurrent"
        // insert (it lands inside that savepoint too, immediately before
        // ours, via the `creating` event below), erasing the very race it's
        // meant to simulate. So this ONE test escapes to genuine PostgreSQL
        // autocommit — matching how the writer actually runs in
        // production (no ambient transaction) — by committing the
        // RefreshDatabase transaction away and never reopening it; a
        // failed autocommit statement cleanly clears itself, and the
        // immediate recovery re-query works exactly as VisualMatchWriter
        // expects. Cleanup is manual since RefreshDatabase's rollback no
        // longer covers this test.
        DB::commit();

        try {
            // Simulate the race: just before OUR insert commits, a
            // concurrent pass lands the same identity row (the partial
            // unique index recognition_detections_content_identity_unique
            // is the backstop).
            $raced = false;
            RecognitionDetection::creating(function () use (&$raced, $content, $product): void {
                if ($raced) {
                    return;
                }
                $raced = true;

                DB::table('recognition_detections')->insert([
                    'tenant_id' => $this->defaultTenant->id,
                    'content_item_id' => $content->id,
                    'recognition_type' => 'VISUAL_PRODUCT',
                    'provider_label' => 'visual-product:'.$product->id,
                    'detected_brand' => 'Glossier',
                    'detected_product' => 'You Perfume',
                    'product_id' => $product->id,
                    'assessment' => json_encode([
                        'value' => 'Glossier', 'confidenceLevel' => 'HIGH',
                        'signals' => ['visual-product-match:You Perfume'],
                        'verificationStatus' => 'AI_ASSESSED',
                    ]),
                    'provenance' => json_encode([
                        'source' => 'SRC-google-gemini-embeddings',
                        'fetchedAt' => now()->toIso8601String(),
                        'sourceVersion' => 'visual-match-v1',
                    ]),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });

            $written = app(VisualMatchWriter::class)->write($content, $this->bandResult($this->candidate($product), VisualMatchBand::Auto), 'gemini-embedding-2');

            $this->assertSame(0, $written); // the concurrent insert already recorded it
            $this->assertSame(1, RecognitionDetection::query()->count());
        } finally {
            RecognitionDetection::flushEventListeners();

            // Everything above was committed for real (autocommit) —
            // RefreshDatabase's rollback no longer covers it, so undo it
            // by hand to leave the database exactly as every other test
            // expects to find it.
            RecognitionDetection::query()->where('content_item_id', $content->id)->delete();
            Keyframe::query()->where('owner_type', $content->getMorphClass())->where('owner_id', $content->id)->delete();
            $content->delete();
            $brandId = $product->brand_id;
            $product->delete();
            Brand::query()->whereKey($brandId)->delete();
            // The tenant itself is left in place: tenant provisioning
            // cascades into other tables (e.g. a founding `clients` row)
            // this test has no business unwinding. RefreshDatabase also
            // marks the schema for a fresh re-migration on the next test
            // that opens a transaction (see beginDatabaseTransaction()'s
            // inTransaction() check) — the harmless orphan tenant row is
            // cleared then regardless.
        }
    }

    public function test_withdraw_support_downgrades_an_ai_row_to_review_once(): void
    {
        [$content, $product] = $this->wired();
        $writer = app(VisualMatchWriter::class);
        $writer->write($content, $this->bandResult($this->candidate($product), VisualMatchBand::Auto), 'gemini-embedding-2');

        $this->assertSame(1, $writer->withdrawSupport($content, $product->id));

        $detection = RecognitionDetection::query()->firstOrFail();
        $this->assertSame(ConfidenceLevel::Low, $detection->assessment->confidenceLevel);
        $this->assertContains('visual-support-withdrawn', $detection->assessment->signals);
        $this->assertTrue($detection->assessment->needsHumanReview());

        // Idempotent: a second withdraw changes nothing.
        $this->assertSame(0, $writer->withdrawSupport($content, $product->id));
    }

    public function test_withdraw_support_skips_missing_and_human_rows(): void
    {
        [$content, $product] = $this->wired();
        $writer = app(VisualMatchWriter::class);

        $this->assertSame(0, $writer->withdrawSupport($content, $product->id)); // nothing to downgrade

        RecognitionDetection::factory()->create([
            'content_item_id' => $content->id,
            'recognition_type' => RecognitionType::VisualProduct,
            'provider_label' => 'visual-product:'.$product->id,
            'detected_brand' => 'Glossier',
            'assessment' => new ConfidenceAssessment('Glossier', ConfidenceLevel::High, ['human-approved'], VerificationStatus::HumanReviewed),
        ]);

        $this->assertSame(0, $writer->withdrawSupport($content, $product->id));
        $this->assertSame(VerificationStatus::HumanReviewed, RecognitionDetection::query()->firstOrFail()->assessment->verificationStatus);
    }

    public function test_story_targets_key_on_story_id(): void
    {
        $story = Story::factory()->create();
        foreach ([0, 1] as $ordinal) {
            Keyframe::factory()->create([
                'owner_type' => $story->getMorphClass(),
                'owner_id' => $story->id,
                'ordinal' => $ordinal,
                'timestamp_ms' => null,
            ]);
        }
        $brand = Brand::factory()->create(['name' => 'Glossier']);
        $product = Product::factory()->create(['brand_id' => $brand->id, 'name' => 'You Perfume']);

        $written = app(VisualMatchWriter::class)->write($story, $this->bandResult($this->candidate($product), VisualMatchBand::Review), 'gemini-embedding-2');

        $this->assertSame(1, $written);
        $this->assertDatabaseHas('recognition_detections', [
            'story_id' => $story->id,
            'content_item_id' => null,
            'recognition_type' => 'VISUAL_PRODUCT',
            'provider_label' => 'visual-product:'.$product->id,
        ]);
    }
}
