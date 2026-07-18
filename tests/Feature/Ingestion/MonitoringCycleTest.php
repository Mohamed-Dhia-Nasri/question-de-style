<?php

namespace Tests\Feature\Ingestion;

use App\Modules\CRM\Models\Creator;
use App\Modules\CRM\Models\PlatformAccount;
use App\Modules\Monitoring\Models\MonitoredSubject;
use App\Platform\Ingestion\Jobs\IngestProfileJob;
use App\Platform\Ingestion\Jobs\IngestStoriesBatchJob;
use App\Platform\Ingestion\Jobs\PollMonitoredAccountJob;
use App\Platform\Ingestion\Jobs\RunMonitoringCycleJob;
use App\Platform\Ingestion\Models\IngestionCycle;
use App\Platform\Ingestion\Support\CycleStatus;
use App\Shared\Enums\MonitoredSubjectType;
use App\Shared\Enums\Platform;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakesProviderResponses;
use Tests\TestCase;

/**
 * Roster monitoring cycles (REQ-M1-001, AC-M1-001, ADR-0011): only active
 * CREATOR subjects are polled, platform filters apply, open-web term
 * subjects are never processed (DEF-006), duplicate concurrent cycles are
 * prevented (requirement 15), and cycle slots complete race-safely.
 */
class MonitoringCycleTest extends TestCase
{
    use FakesProviderResponses;
    use RefreshDatabase;

    /** @return array{0: PlatformAccount, 1: PlatformAccount} */
    private function rosterWithTwoAccounts(): array
    {
        $creator = Creator::factory()->create();

        $instagram = PlatformAccount::factory()->create([
            'creator_id' => $creator->id,
            'platform' => Platform::Instagram,
        ]);
        $youtube = PlatformAccount::factory()->create([
            'creator_id' => $creator->id,
            'platform' => Platform::YouTube,
            'handle' => 'styleicon-yt',
        ]);

        MonitoredSubject::factory()->create([
            'creator_id' => $creator->id,
            'subject_type' => MonitoredSubjectType::Creator,
            'platforms' => [Platform::Instagram, Platform::YouTube],
            'active' => true,
        ]);

        return [$instagram, $youtube];
    }

    public function test_cycle_fans_out_one_poll_job_per_roster_account(): void
    {
        [$instagram, $youtube] = $this->rosterWithTwoAccounts();

        Queue::fake();

        (new RunMonitoringCycleJob)->handle();

        $cycle = IngestionCycle::query()->firstOrFail();
        $this->assertSame(CycleStatus::Running, $cycle->status);
        $this->assertSame(2, $cycle->accounts_count);
        // Instagram: profile + content = 2; YouTube: profile + content = 2.
        // Stories are NOT part of full cycles (cost plan rec 2) — they run
        // in the story-only cycle exclusively.
        $this->assertSame(4, $cycle->jobs_expected);
        $this->assertSame(4, $cycle->jobs_pending);

        Queue::assertPushed(PollMonitoredAccountJob::class, 2);
        Queue::assertPushed(
            fn (PollMonitoredAccountJob $job) => $job->platformAccountId === $instagram->id,
        );
        Queue::assertPushed(
            fn (PollMonitoredAccountJob $job) => $job->platformAccountId === $youtube->id,
        );
    }

    public function test_platform_filter_and_inactive_or_openweb_subjects_are_respected(): void
    {
        $creator = Creator::factory()->create();
        PlatformAccount::factory()->create(['creator_id' => $creator->id, 'platform' => Platform::Instagram]);
        PlatformAccount::factory()->create(['creator_id' => $creator->id, 'platform' => Platform::TikTok, 'handle' => 'tk']);

        // Subject watches ONLY TikTok.
        MonitoredSubject::factory()->create([
            'creator_id' => $creator->id,
            'platforms' => [Platform::TikTok],
        ]);

        // Inactive roster entry — ignored.
        MonitoredSubject::factory()->inactive()->create();

        // Deferred open-web subject (DEF-006) — never processed.
        MonitoredSubject::factory()->create([
            'subject_type' => MonitoredSubjectType::Keyword,
            'creator_id' => null,
            'terms' => ['#fashion'],
        ]);

        Queue::fake();

        (new RunMonitoringCycleJob)->handle();

        $cycle = IngestionCycle::query()->firstOrFail();
        $this->assertSame(1, $cycle->accounts_count); // TikTok account only

        Queue::assertPushed(PollMonitoredAccountJob::class, 1);
    }

    public function test_a_second_concurrent_cycle_is_prevented(): void
    {
        $this->rosterWithTwoAccounts();

        Queue::fake();

        (new RunMonitoringCycleJob)->handle();
        (new RunMonitoringCycleJob)->handle(); // duplicate start — must no-op

        $this->assertSame(1, IngestionCycle::query()->count());
        Queue::assertPushed(PollMonitoredAccountJob::class, 2); // still only the first fan-out
    }

    public function test_stories_only_cycle_targets_instagram_accounts_only(): void
    {
        $this->rosterWithTwoAccounts();

        Queue::fake();

        (new RunMonitoringCycleJob(storiesOnly: true))->handle();

        $cycle = IngestionCycle::query()->firstOrFail();
        $this->assertTrue($cycle->stories_only);
        // One BATCHED story job covers the whole Instagram roster chunk
        // (cost plan rec 3) — per-account poll jobs are never dispatched.
        $this->assertSame(1, $cycle->jobs_expected);

        Queue::assertPushed(IngestStoriesBatchJob::class, 1);
        Queue::assertPushed(
            fn (IngestStoriesBatchJob $job) => count($job->platformAccountIds) === 1,
        );
        Queue::assertNotPushed(PollMonitoredAccountJob::class);
    }

    public function test_a_stale_story_cycle_does_not_consume_the_daily_gap_slot(): void
    {
        $this->rosterWithTwoAccounts();
        config(['qds.ingestion.stories_enabled' => true, 'qds.ingestion.stories_per_day' => 1]);

        // A story cycle that started recently but was reaped to STALE (worker
        // died / breaker open / all batch jobs failed) collected nothing, so
        // it must NOT suppress the next slot — stories expire in 24h with no
        // same-day retry (review cost#3).
        IngestionCycle::query()->create([
            'correlation_id' => 'corr-stale-story',
            'stories_only' => true,
            'status' => CycleStatus::Stale,
            'accounts_count' => 1,
            'jobs_expected' => 1,
            'jobs_pending' => 0,
            'jobs_failed' => 1,
            'started_at' => now()->subMinutes(30),
            'finished_at' => now()->subMinutes(10),
        ]);

        Queue::fake();

        (new RunMonitoringCycleJob(storiesOnly: true))->handle();

        // A new story cycle IS created despite the recent stale one.
        $this->assertSame(2, IngestionCycle::query()->count());
        Queue::assertPushed(IngestStoriesBatchJob::class, 1);
    }

    public function test_a_recent_completed_story_cycle_still_consumes_the_gap_slot(): void
    {
        $this->rosterWithTwoAccounts();
        config(['qds.ingestion.stories_enabled' => true, 'qds.ingestion.stories_per_day' => 1]);

        // A cycle that actually polled recently correctly blocks the slot.
        IngestionCycle::query()->create([
            'correlation_id' => 'corr-done-story',
            'stories_only' => true,
            'status' => CycleStatus::Completed,
            'accounts_count' => 1,
            'jobs_expected' => 1,
            'jobs_pending' => 0,
            'jobs_failed' => 0,
            'started_at' => now()->subMinutes(30),
            'finished_at' => now()->subMinutes(20),
        ]);

        Queue::fake();

        (new RunMonitoringCycleJob(storiesOnly: true))->handle();

        $this->assertSame(1, IngestionCycle::query()->count());
        Queue::assertNotPushed(IngestStoriesBatchJob::class);
    }

    public function test_story_cycle_is_gated_by_the_kill_switch(): void
    {
        $this->rosterWithTwoAccounts();

        config(['qds.ingestion.stories_enabled' => false]);

        Queue::fake();

        (new RunMonitoringCycleJob(storiesOnly: true))->handle();

        // No cycle row, no jobs — the paid story actor is never invoked.
        $this->assertSame(0, IngestionCycle::query()->count());
        Queue::assertNotPushed(IngestStoriesBatchJob::class);
    }

    public function test_cycle_completes_when_the_last_slot_finishes(): void
    {
        $account = PlatformAccount::factory()->create([
            'platform' => Platform::Instagram,
            'handle' => 'styleicon.de',
        ]);

        $cycle = IngestionCycle::query()->create([
            'correlation_id' => 'corr-cycle',
            'status' => CycleStatus::Running,
            'accounts_count' => 1,
            'jobs_expected' => 1,
            'jobs_pending' => 1,
            'jobs_failed' => 0,
            'started_at' => now(),
        ]);

        $this->fakeProviderCredentials();
        $this->fakeApifyActor('apify~instagram-profile-scraper', $this->fixture('instagram-profile'));

        IngestProfileJob::dispatchSync($account->id, $cycle->id, 'corr-cycle');

        $cycle->refresh();
        $this->assertSame(CycleStatus::Completed, $cycle->status);
        $this->assertSame(0, $cycle->jobs_pending);
        $this->assertNotNull($cycle->finished_at);
    }

    public function test_a_failed_slot_finishes_the_cycle_as_partial(): void
    {
        $account = PlatformAccount::factory()->create([
            'platform' => Platform::Instagram,
            'handle' => 'styleicon.de',
        ]);

        $cycle = IngestionCycle::query()->create([
            'correlation_id' => 'corr-cycle-f',
            'status' => CycleStatus::Running,
            'accounts_count' => 1,
            'jobs_expected' => 1,
            'jobs_pending' => 1,
            'jobs_failed' => 0,
            'started_at' => now(),
        ]);

        $this->fakeProviderCredentials();
        $this->fakeApifyActor('apify~instagram-profile-scraper', [], 401); // permanent failure

        $job = (new IngestProfileJob($account->id, $cycle->id, 'corr-cycle-f'))->withFakeQueueInteractions();
        app()->call([$job, 'handle']);
        // Fake queue interactions don't invoke failed(); trigger it as the
        // worker would on final failure.
        $job->failed(new \RuntimeException('final failure'));

        $cycle->refresh();
        $this->assertSame(CycleStatus::Partial, $cycle->status);
        $this->assertSame(1, $cycle->jobs_failed);
    }
}
