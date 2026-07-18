<?php

namespace Tests\Feature\Ingestion;

use App\Modules\CRM\Models\Creator;
use App\Modules\CRM\Models\PlatformAccount;
use App\Modules\Monitoring\Models\ContentItem;
use App\Modules\Monitoring\Models\MonitoredSubject;
use App\Platform\Ingestion\Jobs\IngestContentJob;
use App\Platform\Ingestion\Jobs\IngestProfileJob;
use App\Platform\Ingestion\Jobs\IngestStoriesJob;
use App\Platform\Ingestion\Jobs\PollMonitoredAccountJob;
use App\Platform\Ingestion\Jobs\RunMonitoringCycleJob;
use App\Platform\Ingestion\Models\IngestionCycle;
use App\Platform\Ingestion\Models\ProviderCall;
use App\Platform\Ingestion\Providers\Instagram\InstagramPostAdapter;
use App\Platform\Ingestion\Providers\TikTok\TikTokContentAdapter;
use App\Platform\Ingestion\SourceRegistry;
use App\Platform\Ingestion\Support\CallOutcome;
use App\Platform\Ingestion\Support\CycleStatus;
use App\Shared\Enums\MonitoredSubjectType;
use App\Shared\Enums\Platform;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakesProviderResponses;
use Tests\TestCase;

/**
 * Provider-side cost controls (reviews/PLAN-apify-cost-optimization-
 * 2026-07-07.md): the refresh-window date filter (rec 1), the periodic
 * full-depth sweep, permalink capture for the direct-URL refresh, and the
 * story kill switch (rec 2).
 */
class CostControlsTest extends TestCase
{
    use FakesProviderResponses;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeProviderCredentials();
    }

    public function test_instagram_content_polls_send_the_refresh_window_and_skip_pinned(): void
    {
        config(['qds.ingestion.refresh_window_days' => 14]);
        $this->fakeApifyActor('apify~instagram-post-scraper', $this->fixture('instagram-posts'));

        app(InstagramPostAdapter::class)->fetchContent('styleicon.de');

        Http::assertSent(function (Request $request): bool {
            $body = $request->data();

            return ($body['onlyPostsNewerThan'] ?? null) === '14 days'
                && ($body['skipPinnedPosts'] ?? null) === true;
        });
    }

    public function test_full_depth_fetch_omits_the_date_filter(): void
    {
        config(['qds.ingestion.refresh_window_days' => 14]);
        $this->fakeApifyActor('apify~instagram-post-scraper', $this->fixture('instagram-posts'));

        app(InstagramPostAdapter::class)->fetchContent('styleicon.de', fullDepth: true);

        Http::assertSent(
            fn (Request $request): bool => ! array_key_exists('onlyPostsNewerThan', $request->data()),
        );
    }

    public function test_disabled_window_omits_the_date_filter(): void
    {
        config(['qds.ingestion.refresh_window_days' => 0]);
        $this->fakeApifyActor('apify~instagram-post-scraper', $this->fixture('instagram-posts'));

        app(InstagramPostAdapter::class)->fetchContent('styleicon.de');

        Http::assertSent(
            fn (Request $request): bool => ! array_key_exists('onlyPostsNewerThan', $request->data()),
        );
    }

    public function test_tiktok_content_polls_send_the_unified_date_filter(): void
    {
        config(['qds.ingestion.refresh_window_days' => 14]);
        $this->fakeApifyActor('clockworks~tiktok-scraper', $this->fixture('tiktok-items'));

        app(TikTokContentAdapter::class)->fetchContent('styleicon');

        Http::assertSent(
            fn (Request $request): bool => ($request->data()['oldestPostDateUnified'] ?? null) === '14 days',
        );
    }

    public function test_instagram_permalinks_are_captured_and_persisted(): void
    {
        $account = PlatformAccount::factory()->create([
            'platform' => Platform::Instagram,
            'handle' => 'styleicon.de',
        ]);

        Http::fake([
            'api.apify.com/v2/acts/apify~instagram-post-scraper/*' => Http::response([[
                'id' => 'post-permalink-1',
                'type' => 'Image',
                'caption' => 'hello',
                'url' => 'https://www.instagram.com/p/ABC123/',
                'timestamp' => '2026-07-01T10:00:00.000Z',
                'likesCount' => 10,
                'commentsCount' => 2,
            ]]),
            'api.apify.com/v2/acts/apify~instagram-reel-scraper/*' => Http::response([]),
        ]);

        IngestContentJob::dispatchSync($account->id, null, 'corr-permalink');

        $item = ContentItem::query()->where('external_id', 'post-permalink-1')->firstOrFail();
        $this->assertSame('https://www.instagram.com/p/ABC123/', $item->permalink);
    }

    public function test_the_first_cycle_per_interval_runs_full_depth(): void
    {
        config([
            'qds.ingestion.refresh_window_days' => 14,
            'qds.ingestion.full_sweep_interval_days' => 7,
        ]);

        $creator = Creator::factory()->create();
        PlatformAccount::factory()->create(['creator_id' => $creator->id, 'platform' => Platform::Instagram]);
        MonitoredSubject::factory()->create([
            'creator_id' => $creator->id,
            'subject_type' => MonitoredSubjectType::Creator,
            'platforms' => [Platform::Instagram],
            'active' => true,
        ]);

        Queue::fake();

        (new RunMonitoringCycleJob)->handle();

        $first = IngestionCycle::query()->sole();
        $this->assertTrue($first->full_depth);
        Queue::assertPushed(fn (PollMonitoredAccountJob $job) => $job->fullDepth === true);

        // Same interval: the next cycle polls windowed, not full depth.
        $first->update(['status' => CycleStatus::Completed, 'finished_at' => now()]);

        (new RunMonitoringCycleJob)->handle();

        $second = IngestionCycle::query()->orderByDesc('id')->first();
        $this->assertFalse($second->full_depth);
    }

    public function test_recently_profiled_accounts_skip_the_profile_call_and_bookkeeping_matches(): void
    {
        config(['qds.ingestion.profile_poll_interval_hours' => 168]);

        $creator = Creator::factory()->create();
        $account = PlatformAccount::factory()->create([
            'creator_id' => $creator->id,
            'platform' => Platform::Instagram,
        ]);
        MonitoredSubject::factory()->create([
            'creator_id' => $creator->id,
            'subject_type' => MonitoredSubjectType::Creator,
            'platforms' => [Platform::Instagram],
            'active' => true,
        ]);

        // Fresh successful profile fetch → this cycle plans NO profile job.
        ProviderCall::query()->create([
            'source' => SourceRegistry::APIFY_INSTAGRAM_PROFILE_SCRAPER,
            'operation' => 'profile.fetch',
            'correlation_id' => 'corr-earlier',
            'platform_account_id' => $account->id,
            'started_at' => now()->subHours(3),
            'finished_at' => now()->subHours(3),
            'outcome' => CallOutcome::Success,
        ]);

        Queue::fake();

        (new RunMonitoringCycleJob)->handle();

        $cycle = IngestionCycle::query()->sole();
        // Content only — the planned count matches the dispatch decision.
        $this->assertSame(1, $cycle->jobs_expected);
        Queue::assertPushed(
            fn (PollMonitoredAccountJob $job) => $job->includeProfile === false,
        );

        // And the poll job honors the planning decision: no profile job.
        Queue::fake();
        (new PollMonitoredAccountJob($account->id, $cycle->id, 'corr-x', includeProfile: false))->handle();
        Queue::assertNotPushed(IngestProfileJob::class);
        Queue::assertPushed(IngestContentJob::class, 1);
    }

    public function test_on_demand_story_polling_respects_the_kill_switch(): void
    {
        $account = PlatformAccount::factory()->create(['platform' => Platform::Instagram]);

        $cycle = IngestionCycle::query()->create([
            'correlation_id' => 'corr-ks',
            'status' => CycleStatus::Running,
            'accounts_count' => 1,
            'jobs_expected' => 2,
            'jobs_pending' => 2,
            'jobs_failed' => 0,
            'started_at' => now(),
        ]);

        config(['qds.ingestion.stories_enabled' => false]);

        Queue::fake();

        (new PollMonitoredAccountJob($account->id, $cycle->id, 'corr-ks', includeStories: true))->handle();

        Queue::assertNotPushed(IngestStoriesJob::class);
        Queue::assertPushed(IngestContentJob::class, 1);
    }
}
