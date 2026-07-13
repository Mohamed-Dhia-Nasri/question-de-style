<?php

namespace Tests\Feature\Ingestion;

use App\Modules\CRM\Models\Creator;
use App\Modules\CRM\Models\PlatformAccount;
use App\Modules\Monitoring\Models\MetricSnapshot;
use App\Modules\Monitoring\Models\MonitoredSubject;
use App\Modules\Monitoring\Models\Story;
use App\Platform\Enrichment\Models\EnrichmentRun;
use App\Platform\Enrichment\Support\EnrichmentRunStatus;
use App\Platform\Ingestion\Jobs\RefreshDataQualityJob;
use App\Platform\Ingestion\Models\IngestionAlert;
use App\Platform\Ingestion\Support\AlertType;
use App\Shared\Enums\MetricTier;
use App\Shared\Enums\MonitoredSubjectType;
use App\Shared\Enums\Platform;
use App\Shared\ValueObjects\MetricValue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Data-quality monitoring (P4 hardening): the system alerts when the DATA
 * looks wrong even though provider calls succeed — follower zero-drops and
 * implausible falls (silent TikTok scraper breakage), gaps in the snapshot
 * time series — and reaps hard-killed enrichment runs stuck in RUNNING.
 */
class DataQualityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'qds.ingestion.data_quality.enabled' => true,
            'qds.ingestion.data_quality.zero_drop_min_followers' => 100,
            'qds.ingestion.data_quality.drop_alert_ratio' => 0.5,
            'qds.ingestion.data_quality.snapshot_gap_hours' => 26,
        ]);
    }

    /**
     * @param  list<array{followers: int, hoursAgo: int}>  $points
     * @param  bool  $onRoster  enroll the creator on the active roster for this
     *                          account's platform (the detectors only scan
     *                          rostered accounts)
     */
    private function accountWithSeries(Platform $platform, string $handle, array $points, bool $onRoster = true): PlatformAccount
    {
        $creator = Creator::factory()->create();
        $account = PlatformAccount::factory()->create([
            'creator_id' => $creator->id,
            'platform' => $platform,
            'handle' => $handle,
        ]);

        if ($onRoster) {
            MonitoredSubject::factory()->create([
                'creator_id' => $creator->id,
                'subject_type' => MonitoredSubjectType::Creator,
                'platforms' => [$platform],
                'active' => true,
            ]);
        }

        foreach ($points as $point) {
            MetricSnapshot::factory()->create([
                'platform_account_id' => $account->id,
                'captured_at' => now()->subHours($point['hoursAgo']),
                'metrics' => [new MetricValue($point['followers'], MetricTier::Public, 'followers')],
            ]);
        }

        return $account;
    }

    private function openAlert(AlertType $type, string $source): ?IngestionAlert
    {
        return IngestionAlert::query()
            ->where('alert_type', $type->value)
            ->where('source', $source)
            ->whereNull('resolved_at')
            ->first();
    }

    public function test_follower_zero_drop_raises_a_critical_metric_anomaly_alert(): void
    {
        $this->accountWithSeries(Platform::TikTok, 'broken.tiktok', [
            ['followers' => 52_000, 'hoursAgo' => 2],
            ['followers' => 0, 'hoursAgo' => 1],
        ]);

        RefreshDataQualityJob::dispatchSync();

        $alert = $this->openAlert(AlertType::MetricAnomaly, Platform::TikTok->value);
        $this->assertNotNull($alert);
        $this->assertSame('critical', $alert->severity);
        $this->assertStringContainsString('@broken.tiktok', $alert->message);
    }

    public function test_implausible_follower_drop_raises_a_warning_and_resolves_when_clean(): void
    {
        $account = $this->accountWithSeries(Platform::TikTok, 'shaky.tiktok', [
            ['followers' => 40_000, 'hoursAgo' => 2],
            ['followers' => 10_000, 'hoursAgo' => 1],
        ]);

        RefreshDataQualityJob::dispatchSync();

        $alert = $this->openAlert(AlertType::MetricAnomaly, Platform::TikTok->value);
        $this->assertNotNull($alert);
        $this->assertSame('warning', $alert->severity);

        // A later, plausible snapshot resolves the alert on the next scan.
        MetricSnapshot::factory()->create([
            'platform_account_id' => $account->id,
            'captured_at' => now(),
            'metrics' => [new MetricValue(39_500, MetricTier::Public, 'followers')],
        ]);

        RefreshDataQualityJob::dispatchSync();

        $this->assertNull($this->openAlert(AlertType::MetricAnomaly, Platform::TikTok->value));
    }

    public function test_ordinary_fluctuations_and_small_accounts_raise_nothing(): void
    {
        // Normal shrinkage, below the drop ratio.
        $this->accountWithSeries(Platform::Instagram, 'steady.ig', [
            ['followers' => 20_000, 'hoursAgo' => 2],
            ['followers' => 19_400, 'hoursAgo' => 1],
        ]);

        // Tiny account hitting zero — under the min-followers floor.
        $this->accountWithSeries(Platform::Instagram, 'tiny.ig', [
            ['followers' => 40, 'hoursAgo' => 2],
            ['followers' => 0, 'hoursAgo' => 1],
        ]);

        RefreshDataQualityJob::dispatchSync();

        $this->assertNull($this->openAlert(AlertType::MetricAnomaly, Platform::Instagram->value));
    }

    public function test_snapshot_time_series_gap_raises_and_resolves(): void
    {
        $account = $this->accountWithSeries(Platform::Instagram, 'gapped.ig', [
            ['followers' => 12_000, 'hoursAgo' => 80],
            ['followers' => 12_100, 'hoursAgo' => 40],
        ]);

        RefreshDataQualityJob::dispatchSync();

        $alert = $this->openAlert(AlertType::SnapshotGap, Platform::Instagram->value);
        $this->assertNotNull($alert);
        $this->assertStringContainsString('@gapped.ig', $alert->message);

        // A fresh point closes the gap.
        MetricSnapshot::factory()->create([
            'platform_account_id' => $account->id,
            'captured_at' => now(),
            'metrics' => [new MetricValue(12_150, MetricTier::Public, 'followers')],
        ]);

        RefreshDataQualityJob::dispatchSync();

        $this->assertNull($this->openAlert(AlertType::SnapshotGap, Platform::Instagram->value));
    }

    public function test_a_derostered_account_never_raises_a_snapshot_gap(): void
    {
        // An account removed from the roster stops receiving snapshots BY
        // DESIGN, so its last point ages past the gap window forever. It must
        // NOT be flagged — otherwise the alert never auto-resolves and
        // permanently masks real gaps for that platform (review P4#6).
        $this->accountWithSeries(Platform::Instagram, 'left.the.roster', [
            ['followers' => 12_000, 'hoursAgo' => 200],
            ['followers' => 12_100, 'hoursAgo' => 120],
        ], onRoster: false);

        RefreshDataQualityJob::dispatchSync();

        $this->assertNull($this->openAlert(AlertType::SnapshotGap, Platform::Instagram->value));
    }

    public function test_stale_running_enrichment_runs_are_reaped_as_failed(): void
    {
        config(['qds.enrichment.run_stale_after_minutes' => 180]);

        $stale = EnrichmentRun::query()->create([
            'story_id' => Story::factory()->create()->id,
            'correlation_id' => 'corr-stale',
            'status' => EnrichmentRunStatus::Running,
            'started_at' => now()->subHours(6),
        ]);

        $live = EnrichmentRun::query()->create([
            'story_id' => Story::factory()->create()->id,
            'correlation_id' => 'corr-live',
            'status' => EnrichmentRunStatus::Running,
            'started_at' => now()->subMinutes(10),
        ]);

        RefreshDataQualityJob::dispatchSync();

        $stale->refresh();
        $this->assertSame(EnrichmentRunStatus::Failed, $stale->status);
        $this->assertNotNull($stale->finished_at);
        $this->assertStringContainsString('Reaped', (string) $stale->error);

        $this->assertSame(EnrichmentRunStatus::Running, $live->refresh()->status);
    }

    public function test_command_is_gated_on_the_data_quality_flag(): void
    {
        config(['qds.ingestion.data_quality.enabled' => false]);

        $this->accountWithSeries(Platform::TikTok, 'broken.tiktok', [
            ['followers' => 52_000, 'hoursAgo' => 2],
            ['followers' => 0, 'hoursAgo' => 1],
        ]);

        $this->artisan('qds:check-data-quality')->assertSuccessful();

        $this->assertSame(0, IngestionAlert::query()->count());
    }
}
