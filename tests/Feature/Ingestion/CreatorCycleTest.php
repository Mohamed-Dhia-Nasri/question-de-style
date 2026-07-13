<?php

namespace Tests\Feature\Ingestion;

use App\Modules\CRM\Models\Creator;
use App\Modules\CRM\Models\PlatformAccount;
use App\Modules\Monitoring\Models\MonitoredSubject;
use App\Platform\Ingestion\Contracts\IngestionService;
use App\Platform\Ingestion\Jobs\PollMonitoredAccountJob;
use App\Platform\Ingestion\Jobs\RunCreatorCycleJob;
use App\Platform\Ingestion\Jobs\RunMonitoringCycleJob;
use App\Platform\Ingestion\Models\IngestionCycle;
use App\Platform\Ingestion\Support\AdaptiveCadence;
use App\Platform\Ingestion\Support\CycleStatus;
use App\Shared\Enums\Platform;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * On-demand single-creator monitoring runs (operator "run monitoring now"):
 * the fan-out covers exactly the creator's accounts, bookkeeping rides the
 * ordinary IngestionCycle row scoped by creator_id, duplicates are
 * prevented per creator, and — critically — a running creator cycle never
 * blocks the scheduled whole-roster cycle.
 */
class CreatorCycleTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: Creator, 1: PlatformAccount, 2: PlatformAccount} */
    private function creatorWithTwoAccounts(): array
    {
        $creator = Creator::factory()->create();

        $instagram = PlatformAccount::factory()->create([
            'creator_id' => $creator->id,
            'platform' => Platform::Instagram,
        ]);
        $youtube = PlatformAccount::factory()->create([
            'creator_id' => $creator->id,
            'platform' => Platform::YouTube,
            'handle' => 'ondemand-yt',
        ]);

        return [$creator, $instagram, $youtube];
    }

    public function test_the_service_queues_one_creator_cycle_job(): void
    {
        Queue::fake();

        app(IngestionService::class)->startCreatorCycle(42);

        Queue::assertPushed(fn (RunCreatorCycleJob $job) => $job->creatorId === 42);
    }

    public function test_the_run_polls_exactly_the_creators_accounts(): void
    {
        [$creator, $instagram, $youtube] = $this->creatorWithTwoAccounts();

        // Another creator's account must NOT be swept into the run.
        $other = PlatformAccount::factory()->create(['handle' => 'unrelated.ig']);

        Queue::fake();

        (new RunCreatorCycleJob($creator->id))->handle();

        $cycle = IngestionCycle::query()->sole();
        $this->assertSame($creator->id, $cycle->creator_id);
        $this->assertSame(CycleStatus::Running, $cycle->status);
        $this->assertSame(2, $cycle->accounts_count);
        // Instagram: profile + content + stories = 3; YouTube: profile + content = 2.
        $this->assertSame(5, $cycle->jobs_expected);
        $this->assertSame(5, $cycle->jobs_pending);

        Queue::assertPushed(PollMonitoredAccountJob::class, 2);
        Queue::assertPushed(fn (PollMonitoredAccountJob $job) => $job->platformAccountId === $instagram->id);
        Queue::assertPushed(fn (PollMonitoredAccountJob $job) => $job->platformAccountId === $youtube->id);
        Queue::assertNotPushed(fn (PollMonitoredAccountJob $job) => $job->platformAccountId === $other->id);
    }

    public function test_a_second_concurrent_run_for_the_same_creator_is_a_no_op(): void
    {
        [$creator] = $this->creatorWithTwoAccounts();

        Queue::fake();

        (new RunCreatorCycleJob($creator->id))->handle();
        (new RunCreatorCycleJob($creator->id))->handle();

        $this->assertSame(1, IngestionCycle::query()->count());
        Queue::assertPushed(PollMonitoredAccountJob::class, 2);
    }

    public function test_a_running_creator_cycle_never_blocks_the_scheduled_roster_cycle(): void
    {
        [$creator] = $this->creatorWithTwoAccounts();

        MonitoredSubject::factory()->create([
            'creator_id' => $creator->id,
            'platforms' => [Platform::Instagram, Platform::YouTube],
        ]);

        Queue::fake();

        (new RunCreatorCycleJob($creator->id))->handle();
        (new RunMonitoringCycleJob)->handle(app(AdaptiveCadence::class));

        // One creator-scoped cycle AND one global cycle — the on-demand run
        // must not trip the global duplicate-cycle guard.
        $this->assertSame(1, IngestionCycle::query()->whereNotNull('creator_id')->count());
        $this->assertSame(1, IngestionCycle::query()->whereNull('creator_id')->count());
    }

    public function test_a_creator_without_accounts_completes_immediately(): void
    {
        $creator = Creator::factory()->create();

        Queue::fake();

        (new RunCreatorCycleJob($creator->id))->handle();

        $cycle = IngestionCycle::query()->sole();
        $this->assertSame(CycleStatus::Completed, $cycle->status);
        $this->assertNotNull($cycle->finished_at);
        Queue::assertNotPushed(PollMonitoredAccountJob::class);
    }
}
