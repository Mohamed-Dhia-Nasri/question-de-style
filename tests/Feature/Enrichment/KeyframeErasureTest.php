<?php

namespace Tests\Feature\Enrichment;

use App\Modules\CRM\Models\Creator;
use App\Modules\CRM\Models\PlatformAccount;
use App\Modules\CRM\Models\Product;
use App\Modules\CRM\Services\Gdpr\CreatorEraser;
use App\Modules\Monitoring\Models\ContentItem;
use App\Modules\Monitoring\Models\ContentTranscript;
use App\Modules\Monitoring\Models\Keyframe;
use App\Modules\Monitoring\Models\VisualMatchCandidate;
use App\Modules\Monitoring\Models\VisualMatchRun;
use App\Platform\Enrichment\VisualMatch\Support\VectorLiteral;
use App\Platform\Ingestion\SourceRegistry;
use App\Shared\Enums\KeyframeKind;
use App\Shared\ValueObjects\Provenance;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * GDPR erasure coverage for sub-project B's derived-media artifacts
 * (B2-gate merge condition): persisted keyframe frames and content
 * transcripts are personal data too and must be erasable like every other
 * monitoring artifact CreatorEraser already purges.
 */
class KeyframeErasureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('media');
        Storage::fake('exports');
    }

    public function test_erasure_removes_keyframe_rows_files_and_transcripts(): void
    {
        config(['qds.ingestion.media_disk' => 'media']);

        $creator = Creator::factory()->create();
        $account = PlatformAccount::factory()->for($creator)->create();
        $item = ContentItem::factory()->for($account, 'platformAccount')->create();

        $path = "tenants/{$item->tenant_id}/keyframes/instagram/{$account->id}/content-x/0.jpg";
        Storage::disk('media')->put($path, 'FRAME');
        $keyframe = Keyframe::query()->create([
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
        // Sub-project C: a cached frame embedding must die with its keyframe
        // (DB ON DELETE CASCADE — the eraser needs no code for it).
        DB::table('keyframe_embeddings')->insert([
            'tenant_id' => $item->tenant_id,
            'keyframe_id' => $keyframe->id,
            'model_version' => 'gemini-embedding-2',
            'embedding' => VectorLiteral::fromArray(array_fill(0, 3072, 0.001)),
            'created_at' => now(),
        ]);
        ContentTranscript::query()->create([
            'content_item_id' => $item->id,
            'language' => 'und',
            'status' => ContentTranscript::STATUS_AVAILABLE,
            'text' => 'hello',
            'provider' => SourceRegistry::APIFY_YOUTUBE_TRANSCRIPT,
            'provenance' => new Provenance(SourceRegistry::APIFY_YOUTUBE_TRANSCRIPT, CarbonImmutable::now(), 'youtube-transcript-v1'),
            'checksum' => hash('sha256', 'hello'),
            'fetched_at' => CarbonImmutable::now(),
        ]);

        $counts = app(CreatorEraser::class)->erase($creator);

        $this->assertSame(1, $counts['keyframes']);
        $this->assertSame(1, $counts['content_transcripts']);
        $this->assertSame(1, $counts['keyframe_files']);
        $this->assertSame(0, Keyframe::query()->withoutGlobalScopes()->count());
        $this->assertSame(0, ContentTranscript::query()->withoutGlobalScopes()->count());
        $this->assertSame(0, DB::table('keyframe_embeddings')->count());
        Storage::disk('media')->assertMissing($path);
    }

    public function test_erasure_removes_visual_match_runs_and_candidates_but_keeps_catalog_photos(): void
    {
        config(['qds.ingestion.media_disk' => 'media']);

        $creator = Creator::factory()->create();
        $account = PlatformAccount::factory()->for($creator)->create();
        $item = ContentItem::factory()->for($account, 'platformAccount')->create();

        $run = VisualMatchRun::factory()->create(['content_item_id' => $item->id]);
        VisualMatchCandidate::factory()->count(2)->sequence(['rank' => 1], ['rank' => 2])
            ->create(['visual_match_run_id' => $run->id]);

        // Catalog data must SURVIVE a creator erasure: reference photos
        // belong to the product, not the person (spec §13).
        $product = Product::factory()->create();
        $photoId = DB::table('product_reference_photos')->insertGetId([
            'tenant_id' => $item->tenant_id,
            'product_id' => $product->id,
            'storage_disk' => 'media',
            'storage_path' => "tenants/{$item->tenant_id}/product-photos/{$product->id}/ref.jpg",
            'view_label' => 'front',
            'checksum' => str_repeat('c', 64),
            'width' => 800,
            'height' => 800,
            'uploaded_by' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('product_photo_embeddings')->insert([
            'tenant_id' => $item->tenant_id,
            'product_reference_photo_id' => $photoId,
            'model_version' => 'gemini-embedding-2',
            'embedding' => VectorLiteral::fromArray(array_fill(0, 3072, 0.002)),
            'created_at' => now(),
        ]);

        $counts = app(CreatorEraser::class)->erase($creator);

        $this->assertSame(1, $counts['visual_match_runs']);
        $this->assertSame(0, VisualMatchRun::query()->withoutGlobalScopes()->count());
        // Candidates cascade from runs at the DB — no separate delete list entry.
        $this->assertSame(0, VisualMatchCandidate::query()->withoutGlobalScopes()->count());
        $this->assertSame(1, DB::table('product_reference_photos')->where('id', $photoId)->count());
        $this->assertSame(1, DB::table('product_photo_embeddings')->count());
        $this->assertSame(1, Product::query()->withoutGlobalScopes()->where('id', $product->id)->count());
    }
}
