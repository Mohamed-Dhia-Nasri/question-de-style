<?php

namespace Tests\Feature\Enrichment;

use App\Modules\CRM\Models\ProductReferencePhoto;
use App\Modules\Monitoring\Models\ProductPhotoEmbedding;
use App\Platform\Enrichment\VisualMatch\Contracts\EmbeddingProvider;
use App\Platform\Enrichment\VisualMatch\ReferencePhotoEmbedder;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FakeEmbeddingProvider;
use Tests\TestCase;

/**
 * Spec §6: reference photos embed through the container-bound provider,
 * idempotent per (photo, model_version), budget-guarded at priority HIGH
 * (user-triggered catalog work). Skips return false and write NOTHING —
 * never a fabricated vector, never a phantom budget unit.
 */
class ReferencePhotoEmbedderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake((string) config('qds.ingestion.media_disk', 'media'));
        config()->set('qds.enrichment.visual_match.model_version', 'gemini-embedding-2');
    }

    private function bindProvider(FakeEmbeddingProvider $provider): FakeEmbeddingProvider
    {
        $this->app->instance(EmbeddingProvider::class, $provider);

        return $provider;
    }

    private function makeStoredPhoto(string $extension = 'jpg'): ProductReferencePhoto
    {
        $disk = (string) config('qds.ingestion.media_disk', 'media');
        $tenantId = app(TenantContext::class)->id() ?? $this->defaultTenant->id;
        $path = "tenants/{$tenantId}/product-photos/1/".fake()->uuid().'.'.$extension;
        Storage::disk($disk)->put($path, 'image-bytes');

        return ProductReferencePhoto::factory()->create([
            'storage_disk' => $disk,
            'storage_path' => $path,
        ]);
    }

    public function test_embed_stores_one_vector_and_records_one_budget_unit(): void
    {
        $provider = $this->bindProvider(new FakeEmbeddingProvider);
        $photo = $this->makeStoredPhoto('png');

        $this->assertTrue(app(ReferencePhotoEmbedder::class)->embed($photo));

        $this->assertSame(1, $provider->calls);
        // Mime type is derived from the stored extension, not guessed.
        $this->assertSame(['image/png'], $provider->mimeTypes);
        $this->assertDatabaseHas('product_photo_embeddings', [
            'product_reference_photo_id' => $photo->id,
            'tenant_id' => $photo->tenant_id,
            'model_version' => 'gemini-embedding-2',
        ]);
        // Post-spend accounting flowed through AiBudgetGuard::record.
        $this->assertDatabaseHas('ai_usage_counters', [
            'capability' => 'embedding',
            'tenant_id' => $photo->tenant_id,
            'units' => 1,
        ]);
    }

    public function test_embed_is_idempotent_per_photo_and_model_version(): void
    {
        $provider = $this->bindProvider(new FakeEmbeddingProvider);
        $photo = $this->makeStoredPhoto();

        $embedder = app(ReferencePhotoEmbedder::class);

        $this->assertTrue($embedder->embed($photo));
        $this->assertTrue($embedder->embed($photo)); // cached — never a second bill

        $this->assertSame(1, $provider->calls);
        $this->assertSame(1, ProductPhotoEmbedding::query()
            ->where('product_reference_photo_id', $photo->id)
            ->count());
    }

    public function test_unconfigured_provider_skips_without_writing(): void
    {
        $this->bindProvider(new FakeEmbeddingProvider(configured: false));
        $photo = $this->makeStoredPhoto();

        $this->assertFalse(app(ReferencePhotoEmbedder::class)->embed($photo));

        $this->assertDatabaseCount('product_photo_embeddings', 0);
        $this->assertDatabaseCount('ai_usage_counters', 0);
    }

    public function test_read_only_mode_skips_before_calling_the_provider(): void
    {
        config()->set('qds.ai_budget.read_only', true);
        $provider = $this->bindProvider(new FakeEmbeddingProvider);
        $photo = $this->makeStoredPhoto();

        $this->assertFalse(app(ReferencePhotoEmbedder::class)->embed($photo));

        $this->assertSame(0, $provider->calls);
        $this->assertDatabaseCount('product_photo_embeddings', 0);
    }

    public function test_exhausted_global_hard_cap_stops_even_high_priority(): void
    {
        // HIGH priority ignores tenant soft caps but MUST stop at the
        // global hard caps (§10 priority semantics).
        config()->set('qds.ai_budget.capabilities.embedding.global_daily_hard_units', 0);
        $provider = $this->bindProvider(new FakeEmbeddingProvider);
        $photo = $this->makeStoredPhoto();

        $this->assertFalse(app(ReferencePhotoEmbedder::class)->embed($photo));

        $this->assertSame(0, $provider->calls);
        $this->assertDatabaseCount('product_photo_embeddings', 0);
    }
}
