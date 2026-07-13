<?php

namespace Tests\Feature\Ingestion;

use App\Modules\Monitoring\Livewire\Operations\MonitoringPlanSettings;
use App\Platform\Ingestion\Jobs\IngestStoriesBatchJob;
use App\Platform\Ingestion\Jobs\RunMonitoringCycleJob;
use App\Platform\Ingestion\Models\IngestionCycle;
use App\Platform\Ingestion\Models\MonitoringPlanSetting;
use App\Platform\Ingestion\Support\AdaptiveCadence;
use App\Platform\Ingestion\Support\CadenceSettings;
use App\Platform\Ingestion\Support\CycleStatus;
use App\Platform\Ingestion\Support\IngestionCostEstimator;
use App\Shared\Enums\RoleName;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Operator-chosen monitoring plan (product-owner decision 2026-07-08):
 * DB-backed frequencies override config defaults, the story per-day gate
 * spreads polls across the cron's slots, and the /monitoring/plan page is
 * gated on monitoring.manage with a live cost estimate.
 */
class MonitoringPlanTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
    }

    public function test_saved_plan_overrides_config_defaults(): void
    {
        config([
            'qds.ingestion.baseline_content_interval_hours' => 84,
            'qds.ingestion.stories_per_day' => 1,
        ]);

        $settings = app(CadenceSettings::class);
        $this->assertSame(84, $settings->baselineContentIntervalHours());
        $this->assertSame(1, $settings->storiesPerDay());

        MonitoringPlanSetting::query()->create([
            'baseline_content_interval_hours' => 24,
            'campaign_content_interval_hours' => 6,
            'stories_per_day' => 3,
            'profile_poll_interval_hours' => 720,
            'apify_plan' => 'SCALE',
        ]);

        $fresh = new CadenceSettings;
        $this->assertSame(24, $fresh->baselineContentIntervalHours());
        $this->assertSame(6, $fresh->campaignContentIntervalHours());
        $this->assertSame(3, $fresh->storiesPerDay());
        $this->assertSame(720, $fresh->profilePollIntervalHours());
        $this->assertSame('SCALE', $fresh->apifyPlan());
    }

    public function test_story_cycles_respect_the_per_day_plan(): void
    {
        config(['qds.ingestion.stories_per_day' => 1]);

        // A story cycle ran 4 hours ago (the previous cron slot).
        IngestionCycle::query()->create([
            'correlation_id' => 'corr-prev-story',
            'status' => CycleStatus::Completed,
            'stories_only' => true,
            'accounts_count' => 0,
            'jobs_expected' => 0,
            'jobs_pending' => 0,
            'jobs_failed' => 0,
            'started_at' => CarbonImmutable::now()->subHours(4),
            'finished_at' => CarbonImmutable::now()->subHours(4),
        ]);

        Queue::fake();

        (new RunMonitoringCycleJob(storiesOnly: true))->handle(app(AdaptiveCadence::class));

        // 1/day plan → the 4h-old cycle blocks this slot entirely.
        $this->assertSame(1, IngestionCycle::query()->where('stories_only', true)->count());
        Queue::assertNotPushed(IngestStoriesBatchJob::class);

        // At 6/day every slot is allowed.
        config(['qds.ingestion.stories_per_day' => 6]);

        (new RunMonitoringCycleJob(storiesOnly: true))->handle(app(AdaptiveCadence::class));

        $this->assertSame(2, IngestionCycle::query()->where('stories_only', true)->count());
    }

    public function test_zero_stories_per_day_disables_story_cycles(): void
    {
        config(['qds.ingestion.stories_per_day' => 0]);

        (new RunMonitoringCycleJob(storiesOnly: true))->handle(app(AdaptiveCadence::class));

        $this->assertSame(0, IngestionCycle::query()->count());
    }

    public function test_the_estimator_prices_a_known_scenario(): void
    {
        $settings = new CadenceSettings(new MonitoringPlanSetting([
            'baseline_content_interval_hours' => 84,
            'campaign_content_interval_hours' => 12,
            'stories_per_day' => 1,
            'profile_poll_interval_hours' => 168,
            'apify_plan' => 'STARTER',
        ]));

        $estimate = app(IngestionCostEstimator::class)->estimate($settings, [
            'ig_accounts' => 300,
            'tt_accounts' => 150,
            'campaign_ig' => 30,
            'campaign_tt' => 15,
            'story_active_ig' => 0,
        ], ['items_per_window_ig' => 14, 'items_per_window_tt' => 7, 'active_pct' => 60]);

        // Sanity bounds, not exact figures: the tiered plan lands in the
        // low hundreds — an order of magnitude under every-cycle polling.
        $this->assertGreaterThan(100, $estimate['total']);
        $this->assertLessThan(600, $estimate['total']);
        $this->assertSame(0.0, $estimate['stories']);
        $this->assertSame(29, $estimate['plan_fee']);
        $this->assertFalse($estimate['approx']);
    }

    public function test_the_per_service_sheet_splits_costs_per_creator(): void
    {
        config([
            'qds.enrichment.enabled' => false,
            'qds.enrichment.sweep_batch' => 50,
            'qds.ingestion.campaign_refresh.enabled' => true,
        ]);

        $settings = new CadenceSettings(new MonitoringPlanSetting([
            'baseline_content_interval_hours' => 84,
            'campaign_content_interval_hours' => 12,
            'stories_per_day' => 0,
            'profile_poll_interval_hours' => 168,
            'apify_plan' => 'STARTER',
        ]));

        $roster = [
            'ig_accounts' => 300,
            'tt_accounts' => 150,
            'campaign_ig' => 30,
            'campaign_tt' => 15,
            'story_active_ig' => 0,
        ];

        $estimator = app(IngestionCostEstimator::class);
        $estimate = $estimator->estimate($settings, $roster);
        $rows = collect($estimator->perService($settings, $roster, $estimate))->keyBy('service');

        // Apify rows split the estimate over the accounts they cover.
        $this->assertSame(round($estimate['content_ig'] / 300, 4), $rows['Instagram posts & reels']['per_creator']);
        $this->assertSame(round($estimate['content_tt'] / 150, 4), $rows['TikTok videos']['per_creator']);
        $this->assertStringContainsString('$0.0023 per post/reel', $rows['Instagram posts & reels']['unit']);

        // Stories are off: row stays visible but inactive, nobody covered.
        $this->assertFalse($rows['Instagram stories']['active']);
        $this->assertNull($rows['Instagram stories']['per_creator']);

        // OCR prices Google Vision list rates, capped by the sweep batch
        // (50 × 4/day × 30 = 6,000 images < 450 accounts × 20 items).
        $ocr = $rows['Image text & logos (OCR)'];
        $this->assertFalse($ocr['active']); // enrichment disabled → dimmed
        $this->assertSame(18.0, $ocr['monthly']); // 6,000 × $0.003
        $this->assertSame(0.04, $ocr['per_creator']);

        // Enabling enrichment with a Vision key marks the row live.
        config(['qds.enrichment.enabled' => true, 'services.google_vision.api_key' => 'test-key']);
        $rows = collect($estimator->perService($settings, $roster, $estimate))->keyBy('service');
        $this->assertTrue($rows['Image text & logos (OCR)']['active']);

        // Speech is key-gated like the other Google rows and priced at
        // $0.024/min over the same sweep-capped video volume (1,200 min).
        $this->assertFalse($rows['Spoken brand mentions']['active']); // no Speech key set
        $this->assertSame(28.8, $rows['Spoken brand mentions']['monthly']);

        config(['services.google_speech.api_key' => 'test-speech-key']);
        $rows = collect($estimator->perService($settings, $roster, $estimate))->keyBy('service');
        $this->assertTrue($rows['Spoken brand mentions']['active']);
    }

    public function test_the_plan_page_lists_the_per_service_sheet(): void
    {
        $this->actingAs($this->makeUser(RoleName::Admin));

        $this->get('/monitoring/plan')
            ->assertOk()
            ->assertSee('What each service costs')
            ->assertSee('Per creator / mo')
            ->assertSee('Image text &amp; logos (OCR)', false)
            ->assertSee('TikTok videos')
            ->assertSee('Spoken brand mentions');
    }

    public function test_the_plan_page_saves_a_new_settings_row(): void
    {
        $this->actingAs($this->makeUser(RoleName::Admin));

        Livewire::test(MonitoringPlanSettings::class)
            ->set('baseline', 24)
            ->set('campaign', 12)
            ->set('stories', 2)
            ->set('profile', 168)
            ->set('apifyPlan', 'SCALE')
            ->call('save')
            ->assertHasNoErrors();

        $row = MonitoringPlanSetting::current();
        $this->assertSame(24, $row->baseline_content_interval_hours);
        $this->assertSame(12, $row->campaign_content_interval_hours);
        $this->assertSame(2, $row->stories_per_day);
        $this->assertSame('SCALE', $row->apify_plan);
    }

    public function test_the_plan_page_is_gated_on_monitoring_manage(): void
    {
        $this->actingAs($this->makeUser(RoleName::ClientViewer));

        $this->get('/monitoring/plan')->assertForbidden();

        $this->actingAs($this->makeUser(RoleName::Admin));

        $this->get('/monitoring/plan')
            ->assertOk()
            ->assertSee('Estimated monthly cost')
            ->assertSee('These settings control your external data costs.')
            ->assertSee('Creators in an active campaign');
    }

    public function test_the_monitoring_page_links_to_the_plan(): void
    {
        $this->actingAs($this->makeUser(RoleName::Admin));

        $this->get('/monitoring')
            ->assertOk()
            ->assertSee('Plan &amp; cost', false)
            ->assertSee(route('monitoring.plan'), false);
    }
}
