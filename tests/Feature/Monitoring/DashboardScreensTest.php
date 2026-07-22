<?php

namespace Tests\Feature\Monitoring;

use App\Models\User;
use App\Modules\CRM\Models\Creator;
use App\Modules\CRM\Models\PlatformAccount;
use App\Modules\Monitoring\Livewire\Dashboard\ContentDetail;
use App\Modules\Monitoring\Livewire\Dashboard\CreatorDetail;
use App\Modules\Monitoring\Livewire\Dashboard\CreatorsIndex;
use App\Modules\Monitoring\Livewire\Dashboard\MonitoringOverview;
use App\Modules\Monitoring\Models\ContentItem;
use App\Modules\Monitoring\Models\MetricSnapshot;
use App\Modules\Monitoring\Models\MonitoredSubject;
use App\Modules\Monitoring\Models\SentimentAnalysis;
use App\Platform\Analytics\Contracts\AnalyticsService;
use App\Platform\Ingestion\Jobs\RunCreatorCycleJob;
use App\Shared\Authorization\PermissionsCatalog;
use App\Shared\Enums\ConfidenceLevel;
use App\Shared\Enums\MetricTier;
use App\Shared\Enums\MonitoredSubjectType;
use App\Shared\Enums\Platform;
use App\Shared\Enums\RoleName;
use App\Shared\Enums\SentimentLabel;
use App\Shared\Enums\VerificationStatus;
use App\Shared\ValueObjects\ConfidenceAssessment;
use App\Shared\ValueObjects\MetricValue;
use App\Shared\ValueObjects\Provenance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Module 1 dashboards (REQ-M1-012): server-side rendering, filters,
 * sorting whitelists, pagination, query-string state, unavailable states,
 * metric tiers, the review-correction loop (DP-004), and strict
 * CLIENT_VIEWER isolation from every internal surface (REQ-M3-012 rule).
 */
class DashboardScreensTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
    }

    private function analyst(): User
    {
        return $this->makeUser(RoleName::Analyst);
    }

    public function test_overview_renders_headline_counts_and_the_creator_roster(): void
    {
        $creator = Creator::factory()->create(['display_name' => 'Nova Lang']);
        MonitoredSubject::factory()->create([
            'subject_type' => MonitoredSubjectType::Creator->value,
            'creator_id' => $creator->id,
            'active' => true,
        ]);
        PlatformAccount::factory()->create([
            'creator_id' => $creator->id,
            'platform' => Platform::Instagram,
        ]);

        $this->actingAs($this->analyst());

        $this->get('/monitoring')
            ->assertOk()
            // Compact last-90-day headline totals.
            ->assertSee('Last 90 days')
            ->assertSee('Total posts')
            ->assertSee('Total views')
            ->assertSee('Total likes')
            ->assertSee('Total comments')
            // The creator roster is now the primary content: a card per creator
            // with the person and their platform(s).
            ->assertSee('Nova Lang')
            ->assertSee('Instagram')
            // Freshness note, with the effective poll intervals humanised from
            // the config defaults (campaign 12h, baseline 84h ≈ 3.5 days).
            ->assertSee('Fresh data arrives on a schedule')
            ->assertSee('every 12 hours')
            ->assertSee('every 3.5 days');

        // The heavier reporting figures moved off this page (low cognitive
        // load, user decision) — they live on Exports and per-creator views.
        // Assert on the component itself: the full page's nav legitimately
        // links to the EMV/Reach settings surfaces.
        Livewire::test(MonitoringOverview::class)
            ->assertDontSee('Estimated reach')
            ->assertDontSee('EMV');
    }

    public function test_overview_shows_when_monitoring_data_was_last_updated(): void
    {
        $creator = Creator::factory()->create();
        MonitoredSubject::factory()->create([
            'subject_type' => MonitoredSubjectType::Creator->value,
            'creator_id' => $creator->id,
            'active' => true,
        ]);
        $account = PlatformAccount::factory()->create(['creator_id' => $creator->id]);

        // The tenant-owned snapshot heartbeat is the page's freshness source.
        MetricSnapshot::create([
            'platform_account_id' => $account->id,
            'captured_at' => '2026-07-21 14:32:00',
            'metrics' => [new MetricValue(1000, MetricTier::Public, 'followers')],
            'provenance' => new Provenance('SRC-apify-instagram-scraper', now()->toImmutable(), 'v1'),
        ]);

        $this->actingAs($this->analyst());

        $this->get('/monitoring')
            ->assertOk()
            ->assertSee('Data updated')
            ->assertSee('21.07.2026 14:32 UTC');
    }

    public function test_creators_index_searches_sorts_and_rejects_unknown_sort_columns(): void
    {
        $subjectFor = function (Creator $creator): void {
            MonitoredSubject::factory()->create([
                'subject_type' => MonitoredSubjectType::Creator->value,
                'creator_id' => $creator->id,
                'active' => true,
            ]);
        };

        $anna = Creator::factory()->create(['display_name' => 'Anna Weber']);
        $zoe = Creator::factory()->create(['display_name' => 'Zoe Martin']);
        $subjectFor($anna);
        $subjectFor($zoe);

        // Not on the roster → never listed.
        Creator::factory()->create(['display_name' => 'Unmonitored Person']);

        $this->actingAs($this->analyst());

        Livewire::test(CreatorsIndex::class)
            ->assertSee('Anna Weber')
            ->assertSee('Zoe Martin')
            ->assertDontSee('Unmonitored Person')
            ->set('search', 'Anna')
            ->assertSee('Anna Weber')
            ->assertDontSee('Zoe Martin')
            ->set('search', '')
            ->call('sortBy', 'display_name')
            ->assertSet('sortField', 'display_name')
            // Unknown columns never reach ORDER BY (validated whitelist).
            ->call('sortBy', 'password')
            ->assertSet('sortField', 'display_name')
            ->set('sortField', 'users.email') // tampered query string
            ->assertOk();
    }

    public function test_creators_index_sorts_by_rollup_metrics_and_account_counts(): void
    {
        $roster = function (string $name, int $accounts): Creator {
            $creator = Creator::factory()->create(['display_name' => $name]);
            MonitoredSubject::factory()->create([
                'subject_type' => MonitoredSubjectType::Creator->value,
                'creator_id' => $creator->id,
                'active' => true,
            ]);
            foreach (array_slice([Platform::Instagram, Platform::TikTok], 0, $accounts) as $platform) {
                PlatformAccount::factory()->create(['creator_id' => $creator->id, 'platform' => $platform]);
            }

            return $creator;
        };

        $snapshotFollowers = function (Creator $creator, int $followers): void {
            MetricSnapshot::create([
                'platform_account_id' => $creator->platformAccounts()->first()->id,
                'captured_at' => '2026-06-15 12:00:00',
                'metrics' => [new MetricValue($followers, MetricTier::Public, 'followers')],
                'provenance' => new Provenance('SRC-apify-instagram-scraper', now()->toImmutable(), 'v1'),
            ]);
        };

        $big = $roster('Big Creator', 2);
        $small = $roster('Small Creator', 1);
        $roster('Bare Creator', 1); // no snapshots → no rollup bucket

        $snapshotFollowers($big, 9000);
        $snapshotFollowers($small, 100);

        app(AnalyticsService::class)->refreshRollups();

        $this->actingAs($this->analyst());

        // Followers asc → smallest first; a creator WITHOUT a rollup bucket
        // sorts last in EITHER direction (unavailable never masquerades as
        // the biggest or smallest value).
        Livewire::test(CreatorsIndex::class)
            ->call('sortBy', 'followers')
            ->assertSeeInOrder(['Small Creator', 'Big Creator', 'Bare Creator'])
            ->call('sortBy', 'followers') // toggle → desc
            ->assertSeeInOrder(['Big Creator', 'Small Creator', 'Bare Creator']);

        // Account count sorts on the real column; ties break by name.
        Livewire::test(CreatorsIndex::class)
            ->call('sortBy', 'platform_accounts_count')
            ->assertSeeInOrder(['Bare Creator', 'Small Creator', 'Big Creator'])
            ->call('sortBy', 'platform_accounts_count')
            ->assertSeeInOrder(['Big Creator', 'Bare Creator', 'Small Creator']);
    }

    public function test_creators_index_keeps_filter_state_in_the_query_string(): void
    {
        $this->actingAs($this->analyst());

        Livewire::withQueryParams(['q' => 'anna', 'platform' => 'INSTAGRAM'])
            ->test(CreatorsIndex::class)
            ->assertSet('search', 'anna')
            ->assertSet('platform', 'INSTAGRAM');
    }

    public function test_creator_detail_shows_unavailable_posting_frequency_and_demographics(): void
    {
        $creator = Creator::factory()->create();
        PlatformAccount::factory()->create([
            'creator_id' => $creator->id,
            'platform' => Platform::Instagram,
        ]);

        $this->actingAs($this->analyst());

        $this->get("/monitoring/creators/{$creator->id}")
            ->assertOk()
            ->assertSee($creator->display_name)
            ->assertSee('DEF-001', false)  // audience demographics deferred
            ->assertSee('DEF-002', false)  // contact auto-extraction deferred
            ->assertSee('ADR-0003', false); // history only from own-DB snapshots
    }

    public function test_creator_detail_shows_when_this_creators_data_was_last_updated(): void
    {
        $creator = Creator::factory()->create();
        $account = PlatformAccount::factory()->create([
            'creator_id' => $creator->id,
            'platform' => Platform::Instagram,
            // Profile last fetched BEFORE the snapshot heartbeat, so the
            // account-level snapshot is unambiguously the newest observation.
            'provenance' => new Provenance(
                'SRC-apify-instagram-scraper',
                \Carbon\CarbonImmutable::parse('2026-07-20 08:00:00'),
                'v1',
            ),
        ]);

        // Scoped to THIS creator's accounts — the newest observation of them.
        MetricSnapshot::create([
            'platform_account_id' => $account->id,
            'captured_at' => '2026-07-21 14:32:00',
            'metrics' => [new MetricValue(1000, MetricTier::Public, 'followers')],
            'provenance' => new Provenance('SRC-apify-instagram-scraper', now()->toImmutable(), 'v1'),
        ]);

        $this->actingAs($this->analyst());

        $this->get("/monitoring/creators/{$creator->id}")
            ->assertOk()
            ->assertSee('Data updated')
            ->assertSee('21.07.2026 14:32 UTC');
    }

    public function test_creator_detail_freshness_falls_back_to_the_profile_fetch_when_no_follower_snapshot_exists(): void
    {
        // A creator whose follower count is hidden/absent (e.g. a YouTube
        // channel with hidden subscribers) produces NO account-level
        // MetricSnapshot heartbeat, yet their profile WAS pulled. Freshness must
        // reflect that pull via provenance->fetchedAt, never falsely read "not
        // pulled yet" while the page shows freshly ingested data.
        $creator = Creator::factory()->create();
        PlatformAccount::factory()->create([
            'creator_id' => $creator->id,
            'platform' => Platform::YouTube,
            'follower_count' => null,
            'provenance' => new Provenance(
                'SRC-apify-instagram-scraper',
                \Carbon\CarbonImmutable::parse('2026-07-20 09:15:00'),
                'v1',
            ),
        ]);

        // Deliberately NO MetricSnapshot: a null follower count is never
        // heartbeated by the snapshot scheduler.

        $this->actingAs($this->analyst());

        $this->get("/monitoring/creators/{$creator->id}")
            ->assertOk()
            ->assertSee('Data updated')
            ->assertSee('20.07.2026 09:15 UTC')
            ->assertDontSee('not pulled yet');
    }

    public function test_content_detail_shows_tiered_metrics_and_correction_moves_to_human_corrected(): void
    {
        $account = PlatformAccount::factory()->create(['creator_id' => Creator::factory()->create()->id]);
        $content = ContentItem::factory()->create(['platform_account_id' => $account->id]);

        $sentiment = SentimentAnalysis::factory()->create([
            'content_item_id' => $content->id,
            'label' => SentimentLabel::Neutral,
            'assessment' => new ConfidenceAssessment(
                value: 'NEUTRAL',
                confidenceLevel: ConfidenceLevel::Low,
                signals: ['caption-only'],
                verificationStatus: VerificationStatus::AiAssessed,
            ),
        ]);

        $this->actingAs($this->analyst());

        $this->get("/monitoring/content/{$content->id}")
            ->assertOk()
            ->assertSee('Derived rates')
            // No active reach configuration/enrichment for this content:
            // estimated reach is honest about "not yet" (REQ-M1-006). The
            // retired "Confirmed unique reach / impressions" DEF-003
            // placeholder tile is gone from this page.
            ->assertSee('unavailable')
            ->assertDontSee('DEF-003')
            ->assertDontSee('Confirmed unique reach');

        Livewire::test(ContentDetail::class, ['contentItem' => $content])
            ->set('correctionSentiment', SentimentLabel::Positive->value)
            ->set('reason', 'Clearly positive wording')
            ->call('correct', 'sentiment', $sentiment->id)
            ->assertHasNoErrors();

        $fresh = $sentiment->fresh();
        $this->assertSame(SentimentLabel::Positive, $fresh->label);
        $this->assertSame(VerificationStatus::HumanCorrected, $fresh->assessment->verificationStatus);
    }

    public function test_content_detail_rejects_assessments_of_other_content(): void
    {
        $account = PlatformAccount::factory()->create(['creator_id' => Creator::factory()->create()->id]);
        $content = ContentItem::factory()->create(['platform_account_id' => $account->id]);
        $otherContent = ContentItem::factory()->create(['platform_account_id' => $account->id]);

        $foreign = SentimentAnalysis::factory()->create(['content_item_id' => $otherContent->id]);

        $this->actingAs($this->analyst());

        Livewire::test(ContentDetail::class, ['contentItem' => $content])
            ->set('correctionSentiment', SentimentLabel::Positive->value)
            ->call('correct', 'sentiment', $foreign->id)
            ->assertStatus(404);
    }

    public function test_operations_screen_is_staff_only_and_renders_health_panels(): void
    {
        $this->actingAs($this->analyst());

        $this->get('/monitoring/operations')
            ->assertOk()
            ->assertSee('Provider health')
            ->assertSee('Queue depth')
            ->assertSee('Analytics rollups')
            ->assertSee('SRC-clockworks-tiktok-scraper');
    }

    public function test_creator_detail_run_monitoring_now_queues_an_on_demand_cycle(): void
    {
        config(['qds.ingestion.enabled' => true]);

        $creator = Creator::factory()->create();
        PlatformAccount::factory()->create(['creator_id' => $creator->id]);

        $this->actingAs($this->analyst());

        Queue::fake();

        Livewire::test(CreatorDetail::class, ['creator' => $creator])
            ->call('runMonitoringNow')
            ->assertOk()
            ->assertDispatched('notify', type: 'success');

        $this->assertDatabaseHas('monitored_subjects', ['creator_id' => $creator->id, 'active' => true]);
        Queue::assertPushed(fn (RunCreatorCycleJob $job) => $job->creatorId === $creator->id);
    }

    public function test_creator_detail_run_monitoring_now_requires_the_roster_permission(): void
    {
        config(['qds.ingestion.enabled' => true]);

        $creator = Creator::factory()->create();
        PlatformAccount::factory()->create(['creator_id' => $creator->id]);

        // monitoring.view mounts the page; the run lever needs monitoring.manage.
        $viewer = User::factory()->create();
        $viewer->givePermissionTo(PermissionsCatalog::MONITORING_VIEW);
        $this->actingAs($viewer);

        Queue::fake();

        Livewire::test(CreatorDetail::class, ['creator' => $creator])->assertOk()
            ->call('runMonitoringNow')
            ->assertForbidden();

        Queue::assertNotPushed(RunCreatorCycleJob::class);
    }

    public function test_client_viewer_is_denied_on_every_module1_surface(): void
    {
        $creator = Creator::factory()->create();
        $account = PlatformAccount::factory()->create(['creator_id' => $creator->id]);
        $content = ContentItem::factory()->create(['platform_account_id' => $account->id]);

        $this->actingAs($this->makeUser(RoleName::ClientViewer));

        foreach ([
            '/monitoring',
            '/monitoring/creators',
            "/monitoring/creators/{$creator->id}",
            "/monitoring/content/{$content->id}",
            '/monitoring/review',
            '/monitoring/exports',
            '/monitoring/operations',
            '/dashboard',
        ] as $url) {
            $this->get($url)->assertForbidden();
        }

        // The one surface a client viewer may reach: approved reports.
        $this->get('/reports')->assertOk();
    }
}
