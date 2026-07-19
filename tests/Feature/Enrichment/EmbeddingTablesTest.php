<?php

namespace Tests\Feature\Enrichment;

use App\Modules\CRM\Models\Creator;
use App\Modules\CRM\Models\PlatformAccount;
use App\Modules\CRM\Models\Product;
use App\Modules\CRM\Models\ProductReferencePhoto;
use App\Modules\Monitoring\Models\ContentItem;
use App\Modules\Monitoring\Models\Keyframe;
use App\Modules\Monitoring\Models\KeyframeEmbedding;
use App\Modules\Monitoring\Models\ProductPhotoEmbedding;
use App\Platform\Enrichment\VisualMatch\Support\VectorLiteral;
use App\Shared\Enums\KeyframeKind;
use App\Shared\ValueObjects\Provenance;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Schema + lifecycle guarantees for the two vector(3072) tables (spec
 * §4.2/§4.3): text-literal round trip through pgvector, immutability keys
 * (one row per parent per model_version), and the DB-level cascades that
 * keep both existing deleters (CreatorEraser, qds:prune-keyframes) and the
 * product-delete path correct with zero code changes.
 */
class EmbeddingTablesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('media');
    }

    /** A 3072-dim pgvector literal: zeros with distinctive spot values. */
    private function vector(array $spots = [0 => 1.0]): string
    {
        $vector = array_fill(0, 3072, 0.0);
        foreach ($spots as $index => $value) {
            $vector[$index] = $value;
        }

        return VectorLiteral::fromArray($vector);
    }

    /** Hand-built keyframe (KeyframeRetentionTest pattern) so age + blob path are exact. */
    private function makeFrame(int $ageDays = 0, string $path = 'tenants/1/keyframes/instagram/1/content-x/0.jpg'): Keyframe
    {
        $account = PlatformAccount::factory()->for(Creator::factory())->create();
        $item = ContentItem::factory()->for($account, 'platformAccount')->create();
        Storage::disk('media')->put($path, 'FRAME');

        $frame = Keyframe::query()->create([
            'owner_type' => $item->getMorphClass(),
            'owner_id' => $item->id,
            'ordinal' => 0,
            'timestamp_ms' => 0,
            'storage_disk' => 'media',
            'storage_path' => $path,
            'width' => 100,
            'height' => 100,
            'kind' => KeyframeKind::VideoSample,
            'checksum' => str_repeat('a', 64),
            'source_checksum' => str_repeat('b', 64),
            'provenance' => new Provenance('SRC-apify-instagram-reel-scraper', CarbonImmutable::now(), 'keyframes-v1'),
        ]);
        $frame->timestamps = false;
        $frame->forceFill(['created_at' => CarbonImmutable::now()->subDays($ageDays)])->save();

        return $frame->refresh();
    }

    private function embedFrame(Keyframe $frame, string $modelVersion = 'gemini-embedding-2'): KeyframeEmbedding
    {
        return KeyframeEmbedding::query()->create([
            'keyframe_id' => $frame->id,
            'model_version' => $modelVersion,
            'embedding' => $this->vector(),
        ]);
    }

    public function test_factories_build_tenant_stamped_rows_with_reachable_parents(): void
    {
        $photoEmbedding = ProductPhotoEmbedding::factory()->create();
        $frameEmbedding = KeyframeEmbedding::factory()->create();

        $this->assertSame($this->defaultTenant->id, $photoEmbedding->tenant_id);
        $this->assertSame($this->defaultTenant->id, $frameEmbedding->tenant_id);
        $this->assertSame('gemini-embedding-2', $photoEmbedding->model_version);
        $this->assertNotNull($photoEmbedding->created_at);
        $this->assertTrue($photoEmbedding->photo()->exists());
        $this->assertTrue($frameEmbedding->keyframe()->exists());
    }

    public function test_photo_embedding_round_trips_a_3072_dimension_vector(): void
    {
        $embedding = ProductPhotoEmbedding::factory()->create([
            'embedding' => $this->vector([0 => 1.0, 1 => 0.5, 2 => 0.25]),
        ]);

        // Refresh so we read pgvector's own text output, not our input string.
        $values = VectorLiteral::toArray($embedding->refresh()->embedding);

        $this->assertCount(3072, $values);
        // 1, 0.5, 0.25 are exactly representable in float4 — exact round trip.
        $this->assertSame(1.0, $values[0]);
        $this->assertSame(0.5, $values[1]);
        $this->assertSame(0.25, $values[2]);
        $this->assertSame(0.0, $values[3071]);
    }

    public function test_photo_embedding_is_unique_per_photo_and_model_version(): void
    {
        $photo = ProductReferencePhoto::factory()->create();
        ProductPhotoEmbedding::factory()->create(['product_reference_photo_id' => $photo->id]);
        // Another model_version for the SAME photo is legal (backfill path).
        ProductPhotoEmbedding::factory()->create([
            'product_reference_photo_id' => $photo->id,
            'model_version' => 'gemini-embedding-3',
        ]);

        $this->expectException(UniqueConstraintViolationException::class);
        ProductPhotoEmbedding::factory()->create(['product_reference_photo_id' => $photo->id]);
    }

    public function test_keyframe_embedding_is_unique_per_keyframe_and_model_version(): void
    {
        $frame = $this->makeFrame();
        $this->embedFrame($frame);
        $this->embedFrame($frame, 'gemini-embedding-3'); // legal: model upgrade

        $this->expectException(UniqueConstraintViolationException::class);
        $this->embedFrame($frame);
    }

    public function test_deleting_a_keyframe_cascades_its_embeddings(): void
    {
        $doomed = $this->makeFrame(0, 'tenants/1/keyframes/instagram/1/content-a/0.jpg');
        $survivor = $this->makeFrame(0, 'tenants/1/keyframes/instagram/1/content-b/0.jpg');
        $doomedEmbedding = $this->embedFrame($doomed);
        $doomedUpgrade = $this->embedFrame($doomed, 'gemini-embedding-3');
        $survivorEmbedding = $this->embedFrame($survivor);

        $doomed->delete();

        $this->assertDatabaseMissing('keyframe_embeddings', ['id' => $doomedEmbedding->id]);
        $this->assertDatabaseMissing('keyframe_embeddings', ['id' => $doomedUpgrade->id]);
        $this->assertDatabaseHas('keyframe_embeddings', ['id' => $survivorEmbedding->id]);
    }

    public function test_prune_command_cascades_embeddings_of_expired_keyframes(): void
    {
        config(['qds.enrichment.keyframes.retention_days' => 30]);
        $expired = $this->makeFrame(40, 'tenants/1/keyframes/instagram/1/content-old/0.jpg');
        $fresh = $this->makeFrame(5, 'tenants/1/keyframes/instagram/1/content-new/0.jpg');
        $expiredEmbedding = $this->embedFrame($expired);
        $freshEmbedding = $this->embedFrame($fresh);

        $this->artisan('qds:prune-keyframes')->assertSuccessful();

        $this->assertDatabaseMissing('keyframes', ['id' => $expired->id]);
        $this->assertDatabaseMissing('keyframe_embeddings', ['id' => $expiredEmbedding->id]);
        $this->assertDatabaseHas('keyframes', ['id' => $fresh->id]);
        $this->assertDatabaseHas('keyframe_embeddings', ['id' => $freshEmbedding->id]);
    }

    public function test_deleting_a_photo_cascades_its_embeddings(): void
    {
        $photo = ProductReferencePhoto::factory()->create();
        $survivorPhoto = ProductReferencePhoto::factory()->create();
        $doomed = ProductPhotoEmbedding::factory()->create(['product_reference_photo_id' => $photo->id]);
        $survivor = ProductPhotoEmbedding::factory()->create(['product_reference_photo_id' => $survivorPhoto->id]);

        $photo->delete();

        $this->assertDatabaseMissing('product_photo_embeddings', ['id' => $doomed->id]);
        $this->assertDatabaseHas('product_photo_embeddings', ['id' => $survivor->id]);
    }

    public function test_deleting_a_product_cascades_photos_and_their_embeddings(): void
    {
        $photo = ProductReferencePhoto::factory()->create();
        $embedding = ProductPhotoEmbedding::factory()->create(['product_reference_photo_id' => $photo->id]);

        Product::query()->findOrFail($photo->product_id)->delete();

        $this->assertDatabaseMissing('product_reference_photos', ['id' => $photo->id]);
        $this->assertDatabaseMissing('product_photo_embeddings', ['id' => $embedding->id]);
    }
}
