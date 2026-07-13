<?php

namespace Tests\Feature\Ingestion;

use App\Modules\CRM\Models\PlatformAccount;
use App\Modules\Monitoring\Models\Story;
use App\Platform\Ingestion\Jobs\ArchiveStoryMediaJob;
use App\Platform\Ingestion\Jobs\IngestStoriesBatchJob;
use App\Platform\Ingestion\Models\IngestionCycle;
use App\Platform\Ingestion\Models\ProviderCall;
use App\Platform\Ingestion\Observability\ProviderCallRecorder;
use App\Platform\Ingestion\Observability\ProviderCircuitBreaker;
use App\Platform\Ingestion\Persistence\StoryPersister;
use App\Platform\Ingestion\Providers\ProviderResolver;
use App\Platform\Ingestion\Support\CycleStatus;
use App\Shared\Enums\Platform;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakesProviderResponses;
use Tests\TestCase;

/**
 * Batched story polling (cost plan rec 3): one actor run covers a chunk of
 * roster handles via the ASYNC endpoint, items are attributed back to
 * accounts by ownerHandle, and the per-run start fee is paid once per
 * chunk instead of once per account.
 */
class StoryBatchTest extends TestCase
{
    use FakesProviderResponses;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeProviderCredentials();
    }

    /** Fake the async run → dataset flow for the story actor. */
    private function fakeAsyncStoryRun(array $items): void
    {
        Http::fake([
            'api.apify.com/v2/acts/*/runs*' => Http::response([
                'data' => ['id' => 'run-batch-1', 'status' => 'SUCCEEDED', 'defaultDatasetId' => 'ds-batch-1'],
            ], 201),
            'api.apify.com/v2/datasets/ds-batch-1/items*' => Http::response($items),
        ]);
    }

    private function runningStoryCycle(int $jobsExpected): IngestionCycle
    {
        return IngestionCycle::query()->create([
            'correlation_id' => 'corr-batch',
            'status' => CycleStatus::Running,
            'stories_only' => true,
            'accounts_count' => 2,
            'jobs_expected' => $jobsExpected,
            'jobs_pending' => $jobsExpected,
            'jobs_failed' => 0,
            'started_at' => now(),
        ]);
    }

    public function test_one_batched_run_attributes_stories_to_the_right_accounts(): void
    {
        $alpha = PlatformAccount::factory()->create(['platform' => Platform::Instagram, 'handle' => 'Alpha.IG']);
        $beta = PlatformAccount::factory()->create(['platform' => Platform::Instagram, 'handle' => 'beta.ig']);

        $this->fakeAsyncStoryRun([
            ['id' => 'story-a1', 'username' => 'alpha.ig', 'imageUrl' => 'https://cdn.example/a1.jpg'],
            ['id' => 'story-b1', 'username' => 'beta.ig', 'videoUrl' => 'https://cdn.example/b1.mp4'],
            // Ownerless in a multi-account batch: dropped, never guessed.
            ['id' => 'story-x1', 'imageUrl' => 'https://cdn.example/x.jpg'],
        ]);

        $cycle = $this->runningStoryCycle(1);

        Queue::fake();

        (new IngestStoriesBatchJob([$alpha->id, $beta->id], $cycle->id, 'corr-batch'))->handle(
            app(ProviderResolver::class),
            app(ProviderCallRecorder::class),
            app(StoryPersister::class),
            app(ProviderCircuitBreaker::class),
        );

        // One run for the whole chunk — the usernames array carries both.
        Http::assertSent(function (Request $request): bool {
            if (! str_contains($request->url(), '/runs')) {
                return false;
            }

            return ($request->data()['usernames'] ?? null) === ['Alpha.IG', 'beta.ig'];
        });

        $this->assertSame($alpha->id, Story::query()->where('external_id', 'story-a1')->sole()->platform_account_id);
        $this->assertSame($beta->id, Story::query()->where('external_id', 'story-b1')->sole()->platform_account_id);
        $this->assertSame(0, Story::query()->where('external_id', 'story-x1')->count());

        // One batch-level ProviderCall (no single account), archival queued.
        $call = ProviderCall::query()->where('operation', 'stories.fetch')->sole();
        $this->assertNull($call->platform_account_id);
        Queue::assertPushed(ArchiveStoryMediaJob::class, 2);

        $cycle->refresh();
        $this->assertSame(CycleStatus::Completed, $cycle->status);
        $this->assertSame(0, $cycle->jobs_pending);
    }

    public function test_a_single_account_batch_attributes_ownerless_items_to_that_account(): void
    {
        $solo = PlatformAccount::factory()->create(['platform' => Platform::Instagram, 'handle' => 'solo.ig']);

        // Single-handle batches use the synchronous endpoint.
        $this->fakeApifyActor('datavoyantlab~advanced-instagram-stories-scraper', [
            ['id' => 'story-s1', 'imageUrl' => 'https://cdn.example/s1.jpg'],
        ]);

        $cycle = $this->runningStoryCycle(1);

        Queue::fake();

        (new IngestStoriesBatchJob([$solo->id], $cycle->id, 'corr-batch'))->handle(
            app(ProviderResolver::class),
            app(ProviderCallRecorder::class),
            app(StoryPersister::class),
            app(ProviderCircuitBreaker::class),
        );

        $this->assertSame($solo->id, Story::query()->where('external_id', 'story-s1')->sole()->platform_account_id);
    }
}
