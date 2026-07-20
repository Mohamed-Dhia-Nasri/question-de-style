<?php

namespace Tests\Feature\Enrichment;

use App\Modules\CRM\Models\Creator;
use App\Modules\CRM\Models\PlatformAccount;
use App\Modules\Monitoring\Models\ContentItem;
use App\Modules\Monitoring\Models\Keyframe;
use App\Modules\Monitoring\Models\KeyframeEmbedding;
use App\Platform\Enrichment\VisualMatch\Contracts\EmbeddingProvider;
use App\Platform\Enrichment\VisualMatch\Frames\KeyframeEmbedder;
use App\Platform\Enrichment\VisualMatch\Frames\PreparedFrame;
use App\Platform\Enrichment\VisualMatch\Support\VectorLiteral;
use App\Platform\Ingestion\Exceptions\ProviderCallException;
use App\Platform\Ingestion\Models\ProviderCall;
use App\Platform\Ingestion\SourceRegistry;
use App\Platform\Ingestion\Support\CallOutcome;
use App\Platform\Ingestion\Support\ErrorCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The embedder never sees HTTP: the provider seam is container-stubbed.
 * Cache per (keyframe, model_version); one call per frame (the model
 * fuses multi-image requests into ONE vector — verified, spec §5);
 * transient failures omit the frame and never fail the run.
 */
class KeyframeEmbedderTest extends TestCase
{
    use RefreshDatabase;

    private FakeEmbeddingProvider $provider;

    private ContentItem $item;

    protected function setUp(): void
    {
        parent::setUp();

        $this->provider = new FakeEmbeddingProvider;
        $this->app->instance(EmbeddingProvider::class, $this->provider);

        $account = PlatformAccount::factory()->for(Creator::factory())->create();
        $this->item = ContentItem::factory()->for($account, 'platformAccount')->create();
    }

    /** @return list<float> a 3072-dim vector with one hot component (the DDL width is fixed). */
    private function vector(int $hot): array
    {
        $vector = array_fill(0, 3072, 0.0);
        $vector[$hot] = 1.0;

        return $vector;
    }

    private function makeFrame(int $ordinal, string $bytes): PreparedFrame
    {
        $keyframe = Keyframe::factory()->create([
            'owner_type' => $this->item->getMorphClass(),
            'owner_id' => $this->item->id,
            'ordinal' => $ordinal,
            'timestamp_ms' => $ordinal * 6000,
        ]);

        return new PreparedFrame($keyframe, $bytes, 'image/jpeg', 1, $ordinal * 6000, $ordinal * 6000);
    }

    private function embedder(): KeyframeEmbedder
    {
        return app(KeyframeEmbedder::class);
    }

    public function test_each_prepared_frame_is_embedded_once_and_cached(): void
    {
        $a = $this->makeFrame(0, 'FRAME-A');
        $b = $this->makeFrame(1, 'FRAME-B');
        $this->provider->vectors = ['FRAME-A' => $this->vector(1), 'FRAME-B' => $this->vector(2)];

        $result = $this->embedder()->embedAll([$a, $b], 'corr-embed-1');

        $this->assertCount(2, $this->provider->calls);
        $this->assertSame('image/jpeg', $this->provider->calls[0]['mimeType']);
        $this->assertSame(2, $result['billedCalls']);
        $this->assertSame(0, $result['cacheHits']);
        $this->assertSame($this->vector(1), $result['embedded'][$a->keyframe->id]);
        $this->assertSame($this->vector(2), $result['embedded'][$b->keyframe->id]);

        $this->assertSame(2, KeyframeEmbedding::query()->count());
        $row = KeyframeEmbedding::query()->where('keyframe_id', $a->keyframe->id)->firstOrFail();
        $this->assertSame('gemini-embedding-2', $row->model_version);
        $this->assertSame((int) $this->defaultTenant->id, (int) $row->tenant_id);
        $this->assertSame($this->vector(1), VectorLiteral::toArray((string) $row->embedding));

        $this->assertSame(2, ProviderCall::query()
            ->where('source', SourceRegistry::GOOGLE_GEMINI_EMBEDDINGS)
            ->where('operation', 'embedding.embed')
            ->where('correlation_id', 'corr-embed-1')
            ->where('outcome', CallOutcome::Success->value)
            ->count());
    }

    public function test_cached_embeddings_cost_nothing(): void
    {
        $a = $this->makeFrame(0, 'FRAME-A');
        $b = $this->makeFrame(1, 'FRAME-B');
        KeyframeEmbedding::query()->create([
            'keyframe_id' => $a->keyframe->id,
            'model_version' => 'gemini-embedding-2',
            'embedding' => VectorLiteral::fromArray($this->vector(7)),
        ]);
        $this->provider->vectors = ['FRAME-B' => $this->vector(2)];

        $result = $this->embedder()->embedAll([$a, $b], 'corr-embed-2');

        $this->assertCount(1, $this->provider->calls);
        $this->assertSame('FRAME-B', $this->provider->calls[0]['bytes']);
        $this->assertSame(1, $result['billedCalls']);
        $this->assertSame(1, $result['cacheHits']);
        $this->assertSame($this->vector(7), $result['embedded'][$a->keyframe->id]);
    }

    public function test_a_second_run_is_all_cache_hits(): void
    {
        $a = $this->makeFrame(0, 'FRAME-A');
        $b = $this->makeFrame(1, 'FRAME-B');
        $this->provider->vectors = ['FRAME-A' => $this->vector(1), 'FRAME-B' => $this->vector(2)];

        $this->embedder()->embedAll([$a, $b], 'corr-embed-3');
        $result = $this->embedder()->embedAll([$a, $b], 'corr-embed-3');

        $this->assertCount(2, $this->provider->calls); // no new calls
        $this->assertSame(0, $result['billedCalls']);
        $this->assertSame(2, $result['cacheHits']);
        $this->assertSame(2, KeyframeEmbedding::query()->count());
    }

    public function test_a_transient_failure_omits_the_frame_and_never_throws(): void
    {
        $a = $this->makeFrame(0, 'FRAME-A');
        $b = $this->makeFrame(1, 'FRAME-B');
        $this->provider->vectors = ['FRAME-A' => $this->vector(1)];
        $this->provider->failures = ['FRAME-B' => new ProviderCallException(
            SourceRegistry::GOOGLE_GEMINI_EMBEDDINGS,
            ErrorCategory::UpstreamError,
            'SRC-google-gemini-embeddings request failed (HTTP 500).',
            500,
        )];

        $result = $this->embedder()->embedAll([$a, $b], 'corr-embed-4');

        $this->assertArrayHasKey($a->keyframe->id, $result['embedded']);
        $this->assertArrayNotHasKey($b->keyframe->id, $result['embedded']);
        $this->assertSame(1, $result['billedCalls']); // failed calls are not billed
        $this->assertSame(0, KeyframeEmbedding::query()->where('keyframe_id', $b->keyframe->id)->count());
        $this->assertSame(1, ProviderCall::query()
            ->where('source', SourceRegistry::GOOGLE_GEMINI_EMBEDDINGS)
            ->where('outcome', CallOutcome::Failure->value)
            ->count());
    }

    public function test_a_concurrent_winner_row_is_reloaded_never_clobbered(): void
    {
        $a = $this->makeFrame(0, 'FRAME-A');
        $winner = $this->vector(9);
        $this->provider->vectors = ['FRAME-A' => $this->vector(1)];
        $this->provider->onEmbed = function () use ($a, $winner): void {
            // Simulate a parallel run committing between our cache check and save.
            KeyframeEmbedding::query()->create([
                'keyframe_id' => $a->keyframe->id,
                'model_version' => 'gemini-embedding-2',
                'embedding' => VectorLiteral::fromArray($winner),
            ]);
        };

        $result = $this->embedder()->embedAll([$a], 'corr-embed-5');

        $this->assertSame(1, KeyframeEmbedding::query()->where('keyframe_id', $a->keyframe->id)->count());
        $this->assertSame($winner, $result['embedded'][$a->keyframe->id]);
        $this->assertSame(1, $result['billedCalls']); // our call really happened
    }
}

/** Container stub for the provider seam — behaviour driven per frame bytes. */
final class FakeEmbeddingProvider implements EmbeddingProvider
{
    /** @var list<array{bytes: string, mimeType: string}> */
    public array $calls = [];

    /** @var array<string, list<float>> vector returned, keyed by bytes */
    public array $vectors = [];

    /** @var array<string, ProviderCallException> failure thrown, keyed by bytes */
    public array $failures = [];

    /** Hook that runs mid-call — used to simulate concurrent winners. */
    public ?\Closure $onEmbed = null;

    public function embedImage(string $bytes, string $mimeType): array
    {
        $this->calls[] = ['bytes' => $bytes, 'mimeType' => $mimeType];

        if (isset($this->failures[$bytes])) {
            throw $this->failures[$bytes];
        }

        if ($this->onEmbed instanceof \Closure) {
            ($this->onEmbed)($bytes);
        }

        return $this->vectors[$bytes];
    }

    public function modelVersion(): string
    {
        return 'gemini-embedding-2';
    }

    public function isConfigured(): bool
    {
        return true;
    }
}
