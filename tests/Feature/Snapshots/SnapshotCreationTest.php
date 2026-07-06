<?php

namespace Tests\Feature\Snapshots;

use App\Modules\CRM\Models\Creator;
use App\Modules\CRM\Models\PlatformAccount;
use App\Modules\Monitoring\Models\ContentItem;
use App\Modules\Monitoring\Models\MetricSnapshot;
use App\Modules\Monitoring\Models\MonitoredSubject;
use App\Platform\Snapshots\DatabaseSnapshotScheduler;
use App\Platform\Snapshots\Jobs\CreateSnapshotsJob;
use App\Shared\Enums\MetricTier;
use App\Shared\Enums\Platform;
use App\Shared\ValueObjects\MetricValue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * SVC-SnapshotScheduler (ADR-0003, REQ-M1-007, AC-M1-008/021): recurring
 * timestamped snapshots of CURRENT ingested PUBLIC counts, account-level
 * and content-level, carrying Provenance; series accumulate from first
 * collection onward — no fabricated backfill, no duplicate points inside
 * the minimum interval, no concurrent double-runs.
 */
class SnapshotCreationTest extends TestCase
{
    use RefreshDatabase;

    private function rosterAccount(array $accountAttributes = []): PlatformAccount
    {
        $creator = Creator::factory()->create();

        $account = PlatformAccount::factory()->create([
            'creator_id' => $creator->id,
            'platform' => Platform::Instagram,
            ...$accountAttributes,
        ]);

        MonitoredSubject::factory()->create([
            'creator_id' => $creator->id,
            'platforms' => [Platform::Instagram],
        ]);

        return $account;
    }

    public function test_account_and_content_snapshots_are_captured_with_provenance(): void
    {
        $account = $this->rosterAccount();

        $content = ContentItem::factory()->create([
            'platform_account_id' => $account->id,
            'published_at' => now()->subDays(2),
        ]);

        $captured = app(DatabaseSnapshotScheduler::class)->captureDueSnapshots();

        $this->assertSame(2, $captured);

        $accountSnapshot = MetricSnapshot::query()->whereNotNull('platform_account_id')->firstOrFail();
        $this->assertSame($account->id, $accountSnapshot->platform_account_id);
        $this->assertSame($account->follower_count->amount, $accountSnapshot->metrics[0]->amount);
        $this->assertSame($account->provenance->source, $accountSnapshot->provenance->source);
        $this->assertNotNull($accountSnapshot->captured_at);

        $contentSnapshot = MetricSnapshot::query()->whereNotNull('content_item_id')->firstOrFail();
        $this->assertSame($content->id, $contentSnapshot->content_item_id);
        $this->assertCount(count($content->public_metrics), $contentSnapshot->metrics);
        $this->assertSame($content->provenance->source, $contentSnapshot->provenance->source);
    }

    public function test_snapshots_accumulate_over_time_but_never_duplicate_within_the_interval(): void
    {
        config(['qds.snapshots.min_interval_minutes' => 55]);

        $this->rosterAccount();

        $scheduler = app(DatabaseSnapshotScheduler::class);

        $this->assertSame(1, $scheduler->captureDueSnapshots());
        // Immediate re-run: inside the minimum interval → no new point.
        $this->assertSame(0, $scheduler->captureDueSnapshots());

        // Next cycle: a new point accrues — the series IS the history.
        $this->travel(1)->hours();
        $this->assertSame(1, $scheduler->captureDueSnapshots());
        $this->travelBack();

        $this->assertSame(2, MetricSnapshot::query()->count());
    }

    public function test_no_snapshot_is_fabricated_without_ingested_counts(): void
    {
        // Roster account whose profile was never successfully polled.
        $this->rosterAccount(['follower_count' => null]);

        $this->assertSame(0, app(DatabaseSnapshotScheduler::class)->captureDueSnapshots());
        $this->assertSame(0, MetricSnapshot::query()->count()); // never a fabricated zero
    }

    public function test_non_roster_accounts_are_not_snapshotted(): void
    {
        // Account exists but no active MonitoredSubject references its creator.
        PlatformAccount::factory()->create([
            'follower_count' => new MetricValue(1000, MetricTier::Public, 'followers'),
        ]);

        $this->assertSame(0, app(DatabaseSnapshotScheduler::class)->captureDueSnapshots());
    }

    public function test_content_outside_the_snapshot_window_is_skipped(): void
    {
        config(['qds.snapshots.content_window_days' => 30]);

        $account = $this->rosterAccount(['follower_count' => null]);

        ContentItem::factory()->create([
            'platform_account_id' => $account->id,
            'published_at' => now()->subDays(90), // long past the window
        ]);

        $this->assertSame(0, app(DatabaseSnapshotScheduler::class)->captureDueSnapshots());
    }

    public function test_queued_snapshot_job_runs_the_scheduler(): void
    {
        $this->rosterAccount();

        (new CreateSnapshotsJob)->handle(app(DatabaseSnapshotScheduler::class));

        $this->assertSame(1, MetricSnapshot::query()->count());
    }
}
