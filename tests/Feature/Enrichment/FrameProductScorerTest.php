<?php

namespace Tests\Feature\Enrichment;

use App\Modules\CRM\Models\Product;
use App\Modules\Monitoring\Models\ContentItem;
use App\Modules\Monitoring\Models\Keyframe;
use App\Platform\Enrichment\VisualMatch\Candidates\Candidate;
use App\Platform\Enrichment\VisualMatch\Frames\PreparedFrame;
use App\Platform\Enrichment\VisualMatch\Matching\FrameProductScorer;
use App\Platform\Enrichment\VisualMatch\Support\VectorLiteral;
use App\Shared\Enums\KeyframeKind;
use App\Shared\ValueObjects\Provenance;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Exact-scan scorer over seeded vectors with KNOWN cosine geometry: unit
 * vectors at known angles in the first two of the 3072 dimensions
 * (zero-padding changes nothing about the angle), so every expected
 * similarity is a textbook cosine. Verified pgvector semantics (spec §18):
 * `<=>` is cosine DISTANCE, similarity = 1 - (a <=> b), exact scan.
 */
class FrameProductScorerTest extends TestCase
{
    use RefreshDatabase;

    private const MODEL = 'gemini-embedding-2';

    public function test_scores_best_photo_per_frame_per_candidate_with_known_cosine_geometry(): void
    {
        $item = ContentItem::factory()->create();
        $frameA = $this->makeKeyframe($item, 0, 0);
        $frameB = $this->makeKeyframe($item, 1, 6000);
        $this->embedKeyframe($frameA, [1.0, 0.0]);
        $this->embedKeyframe($frameB, [0.0, 1.0]);

        $productA = Product::factory()->create();
        $productB = Product::factory()->create();
        // Product A: one photo aligned with frame A (cos 0° = 1.0), one at
        // 60° from frame A (cos 60° = 0.5) — the aligned photo must win A/A.
        $alignedPhoto = $this->photoWithEmbedding($productA, [1.0, 0.0]);
        $sixtyPhoto = $this->photoWithEmbedding($productA, [0.5, sqrt(3) / 2]);
        // Product B: one photo orthogonal to frame A, aligned with frame B.
        $orthogonalPhoto = $this->photoWithEmbedding($productB, [0.0, 1.0]);

        $scores = app(FrameProductScorer::class)->score(
            [$this->preparedFrame($frameA), $this->preparedFrame($frameB, representedFrames: 2)],
            [$this->candidate($productA), $this->candidate($productB)],
            self::MODEL,
        );

        $this->assertCount(2, $scores);
        [$forA, $forB] = $scores;

        // Candidate order preserved even though B also scores.
        $this->assertSame($productA->id, $forA->candidate->productId);
        $this->assertSame($productB->id, $forB->candidate->productId);

        // Product A vs frame A: aligned photo wins at ~1.0 (60° photo loses
        // at 0.5); vs frame B: the 60° photo wins at cos 30° ≈ 0.8660.
        $this->assertCount(2, $forA->frameScores);
        [$a0, $a1] = $forA->frameScores; // ordered by ordinal
        $this->assertSame(0, $a0->ordinal);
        $this->assertSame($frameA->id, $a0->keyframeId);
        $this->assertSame(0, $a0->timestampMs);
        $this->assertSame($alignedPhoto, $a0->photoId);
        $this->assertSame(1, $a0->representedFrames);
        $this->assertEqualsWithDelta(1.0, $a0->similarity, 0.0001);
        $this->assertSame(1, $a1->ordinal);
        $this->assertSame(6000, $a1->timestampMs);
        $this->assertSame(2, $a1->representedFrames);
        $this->assertSame($sixtyPhoto, $a1->photoId);
        $this->assertEqualsWithDelta(sqrt(3) / 2, $a1->similarity, 0.0001);
        $this->assertEqualsWithDelta(1.0, $forA->bestSimilarity(), 0.0001);

        // Product B vs frame A: orthogonal → 0.0; vs frame B: aligned → 1.0.
        [$b0, $b1] = $forB->frameScores;
        $this->assertSame($orthogonalPhoto, $b0->photoId);
        $this->assertEqualsWithDelta(0.0, $b0->similarity, 0.0001);
        $this->assertEqualsWithDelta(1.0, $b1->similarity, 0.0001);
        $this->assertEqualsWithDelta(1.0, $forB->bestSimilarity(), 0.0001);
    }

    public function test_photo_similarity_ties_break_on_lower_photo_id(): void
    {
        $item = ContentItem::factory()->create();
        $frame = $this->makeKeyframe($item, 0, 0);
        $this->embedKeyframe($frame, [1.0, 0.0]);

        $product = Product::factory()->create();
        $firstPhoto = $this->photoWithEmbedding($product, [1.0, 0.0]);
        $this->photoWithEmbedding($product, [1.0, 0.0]); // identical vector, higher id

        $scores = app(FrameProductScorer::class)->score(
            [$this->preparedFrame($frame)],
            [$this->candidate($product)],
            self::MODEL,
        );

        // Fully-specified ORDER BY: deterministic winner on exact ties.
        $this->assertSame($firstPhoto, $scores[0]->frameScores[0]->photoId);
    }

    public function test_only_embeddings_at_the_requested_model_version_are_scored(): void
    {
        $item = ContentItem::factory()->create();
        $frame = $this->makeKeyframe($item, 0, 0);
        $this->embedKeyframe($frame, [1.0, 0.0]);

        $product = Product::factory()->create();
        $this->photoWithEmbedding($product, [1.0, 0.0], modelVersion: 'other-model-v9');

        $scores = app(FrameProductScorer::class)->score(
            [$this->preparedFrame($frame)],
            [$this->candidate($product)],
            self::MODEL,
        );

        // Vectors from another model live in an incompatible space (spec §5)
        // — never comparable. No fabricated score; bestSimilarity 0.0.
        $this->assertCount(1, $scores);
        $this->assertSame([], $scores[0]->frameScores);
        $this->assertSame(0.0, $scores[0]->bestSimilarity());
    }

    public function test_frames_without_a_cached_embedding_are_omitted_not_fabricated(): void
    {
        $item = ContentItem::factory()->create();
        $embedded = $this->makeKeyframe($item, 0, 0);
        $unembedded = $this->makeKeyframe($item, 1, 6000); // transient embed failure: no row
        $this->embedKeyframe($embedded, [1.0, 0.0]);

        $product = Product::factory()->create();
        $this->photoWithEmbedding($product, [1.0, 0.0]);

        $scores = app(FrameProductScorer::class)->score(
            [$this->preparedFrame($embedded), $this->preparedFrame($unembedded)],
            [$this->candidate($product)],
            self::MODEL,
        );

        $this->assertCount(1, $scores[0]->frameScores);
        $this->assertSame($embedded->id, $scores[0]->frameScores[0]->keyframeId);
    }

    public function test_empty_inputs_short_circuit_without_sql(): void
    {
        $product = Product::factory()->create();

        $this->assertSame([], app(FrameProductScorer::class)->score([], [], self::MODEL));

        $scores = app(FrameProductScorer::class)->score([], [$this->candidate($product)], self::MODEL);
        $this->assertCount(1, $scores);
        $this->assertSame([], $scores[0]->frameScores);
    }

    public function test_another_tenants_embeddings_never_leak_into_the_scan(): void
    {
        $item = ContentItem::factory()->create();
        $frame = $this->makeKeyframe($item, 0, 0);
        $this->embedKeyframe($frame, [0.5, sqrt(3) / 2]); // 60° from e1

        $product = Product::factory()->create();
        $ownPhoto = $this->photoWithEmbedding($product, [1.0, 0.0]); // cos 60° = 0.5

        // A second tenant with a perfectly-aligned photo chain of its own —
        // must be invisible to this tenant's scan.
        $other = $this->makeTenant('Other Tenant');
        $this->withTenant($other, function (): void {
            $foreignProduct = Product::factory()->create();
            $this->photoWithEmbedding($foreignProduct, [0.5, sqrt(3) / 2]); // would score 1.0
        });

        $scores = app(FrameProductScorer::class)->score(
            [$this->preparedFrame($frame)],
            [$this->candidate($product)],
            self::MODEL,
        );

        $this->assertCount(1, $scores);
        $this->assertCount(1, $scores[0]->frameScores);
        $this->assertSame($ownPhoto, $scores[0]->frameScores[0]->photoId);
        $this->assertEqualsWithDelta(0.5, $scores[0]->frameScores[0]->similarity, 0.0001);
    }

    /**
     * Zero-padded to the DDL's 3072 dims — padding preserves cosine geometry.
     *
     * @param  list<float>  $components
     * @return list<float>
     */
    private function vec(array $components): array
    {
        return array_pad($components, 3072, 0.0);
    }

    private function makeKeyframe(ContentItem $item, int $ordinal, ?int $timestampMs): Keyframe
    {
        return Keyframe::query()->create([
            'owner_type' => $item->getMorphClass(),
            'owner_id' => $item->id,
            'ordinal' => $ordinal,
            'timestamp_ms' => $timestampMs,
            'storage_disk' => 'media',
            'storage_path' => "tenants/{$item->tenant_id}/keyframes/instagram/1/content-{$item->id}/{$ordinal}.jpg",
            'width' => 100,
            'height' => 100,
            'kind' => KeyframeKind::VideoSample,
            'checksum' => hash('sha256', "frame-{$item->id}-{$ordinal}"),
            'source_checksum' => str_repeat('b', 64),
            'provenance' => new Provenance('SRC-apify-instagram-reel-scraper', CarbonImmutable::now(), 'keyframes-v1'),
        ]);
    }

    /** @param list<float> $components */
    private function embedKeyframe(Keyframe $keyframe, array $components, string $modelVersion = self::MODEL): void
    {
        DB::table('keyframe_embeddings')->insert([
            'tenant_id' => $keyframe->tenant_id,
            'keyframe_id' => $keyframe->id,
            'model_version' => $modelVersion,
            'embedding' => VectorLiteral::fromArray($this->vec($components)),
            'created_at' => now(),
        ]);
    }

    /**
     * @param  list<float>  $components
     * @return int the reference-photo id
     */
    private function photoWithEmbedding(Product $product, array $components, string $modelVersion = self::MODEL): int
    {
        $photoId = (int) DB::table('product_reference_photos')->insertGetId([
            'tenant_id' => $product->tenant_id,
            'product_id' => $product->id,
            'storage_disk' => 'media',
            'storage_path' => "tenants/{$product->tenant_id}/product-photos/{$product->id}/".uniqid('', true).'.jpg',
            'view_label' => 'front',
            'checksum' => hash('sha256', uniqid('photo', true)),
            'width' => 800,
            'height' => 800,
            'uploaded_by' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('product_photo_embeddings')->insert([
            'tenant_id' => $product->tenant_id,
            'product_reference_photo_id' => $photoId,
            'model_version' => $modelVersion,
            'embedding' => VectorLiteral::fromArray($this->vec($components)),
            'created_at' => now(),
        ]);

        return $photoId;
    }

    private function preparedFrame(Keyframe $keyframe, int $representedFrames = 1): PreparedFrame
    {
        return new PreparedFrame(
            keyframe: $keyframe,
            bytes: 'jpeg-bytes',
            mimeType: 'image/jpeg',
            representedFrames: $representedFrames,
            spanStartMs: $keyframe->timestamp_ms,
            spanEndMs: $keyframe->timestamp_ms,
        );
    }

    private function candidate(Product $product): Candidate
    {
        return new Candidate(
            productId: $product->id,
            productLabel: $product->name,
            brandName: 'Nexon Labs',
            category: $product->category,
            source: 'shipment',
            shipmentInWindow: true,
            seedingCampaignId: null,
            shipmentAnchorAt: null,
            shipmentAgeDays: 10,
            hasEmbeddedPhotos: true,
        );
    }
}
