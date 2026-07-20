<?php

namespace Tests\Feature\Enrichment;

use App\Modules\CRM\Models\ProductReferencePhoto;
use App\Modules\Monitoring\Models\ProductPhotoEmbedding;
use App\Platform\Enrichment\VisualMatch\Contracts\EmbeddingProvider;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FakeEmbeddingProvider;
use Tests\TestCase;

/**
 * qds:embed-product-photos (spec §6/§14): the idempotent backfill for
 * photos uploaded while the capability was off, and the model-upgrade
 * tool — embeds every (photo, model_version) pair still missing at the
 * CURRENT model version, per-tenant, through the normal budget guard.
 */
class EmbedProductPhotosCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake((string) config('qds.ingestion.media_disk', 'media'));
        config()->set('qds.enrichment.visual_match.model_version', 'gemini-embedding-2');
    }

    private function makeStoredPhoto(): ProductReferencePhoto
    {
        $disk = (string) config('qds.ingestion.media_disk', 'media');
        $tenantId = app(TenantContext::class)->id() ?? $this->defaultTenant->id;
        $path = "tenants/{$tenantId}/product-photos/1/".fake()->uuid().'.jpg';
        Storage::disk($disk)->put($path, 'jpeg-bytes');

        return ProductReferencePhoto::factory()->create([
            'storage_disk' => $disk,
            'storage_path' => $path,
        ]);
    }

    public function test_backfill_embeds_only_missing_pairs_across_tenants(): void
    {
        $provider = new FakeEmbeddingProvider;
        $this->app->instance(EmbeddingProvider::class, $provider);

        $missing = $this->makeStoredPhoto();

        $done = $this->makeStoredPhoto();
        ProductPhotoEmbedding::factory()->create([
            'product_reference_photo_id' => $done->id,
            'model_version' => 'gemini-embedding-2',
        ]);

        // A second tenant's photo is picked up too — the command iterates
        // ownership with explicit predicates (scheduler is tenant-less).
        [$tenantA] = $this->makeTenantPair();
        $foreign = $this->withTenant($tenantA, fn (): ProductReferencePhoto => $this->makeStoredPhoto());

        $this->artisan('qds:embed-product-photos')
            ->expectsOutputToContain('Embedded 2, skipped 0')
            ->assertExitCode(0);

        $this->assertSame(2, $provider->calls);

        foreach ([$missing, $foreign] as $photo) {
            $this->assertDatabaseHas('product_photo_embeddings', [
                'product_reference_photo_id' => $photo->id,
                'model_version' => 'gemini-embedding-2',
                'tenant_id' => $photo->tenant_id,
            ]);
        }
    }

    public function test_unconfigured_provider_makes_the_backfill_a_no_op(): void
    {
        $this->app->instance(EmbeddingProvider::class, new FakeEmbeddingProvider(configured: false));
        $this->makeStoredPhoto();

        $this->artisan('qds:embed-product-photos')->assertExitCode(0);

        $this->assertDatabaseCount('product_photo_embeddings', 0);
    }
}
