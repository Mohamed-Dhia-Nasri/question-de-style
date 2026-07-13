<?php

namespace Tests\Feature\Ingestion;

use App\Modules\CRM\Models\Creator;
use App\Modules\CRM\Models\PlatformAccount;
use App\Modules\CRM\Models\SeedingCampaign;
use App\Modules\Monitoring\Models\ContentItem;
use App\Modules\Monitoring\Models\Story;
use App\Platform\Ingestion\Models\ProviderCall;
use App\Platform\Ingestion\SourceRegistry;
use App\Platform\Ingestion\Support\AdaptiveCadence;
use App\Platform\Ingestion\Support\CallOutcome;
use App\Shared\Enums\Platform;
use App\Shared\Enums\SeedingCampaignStatus;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Adaptive cadence (cost plan rec 7): dormant accounts drop to one poll
 * per demoted interval, campaign-attached creators are never demoted, and
 * story polls require recent story activity (plus a daily probe).
 */
class AdaptiveCadenceTest extends TestCase
{
    use RefreshDatabase;

    private function account(): PlatformAccount
    {
        return PlatformAccount::factory()->create([
            'creator_id' => Creator::factory(),
            'platform' => Platform::Instagram,
        ]);
    }

    private function contentCall(PlatformAccount $account, CarbonImmutable $at, string $operation = 'content.fetch'): void
    {
        ProviderCall::query()->create([
            'source' => SourceRegistry::APIFY_INSTAGRAM_POST_SCRAPER,
            'operation' => $operation,
            'correlation_id' => 'corr-history',
            'platform_account_id' => $account->id,
            'started_at' => $at,
            'finished_at' => $at,
            'outcome' => CallOutcome::Success,
        ]);
    }

    public function test_a_never_polled_account_is_always_active(): void
    {
        $this->assertTrue(app(AdaptiveCadence::class)->shouldPollContent($this->account()));
    }

    public function test_an_account_with_recent_content_is_active(): void
    {
        $account = $this->account();
        ContentItem::factory()->create([
            'platform_account_id' => $account->id,
            'published_at' => CarbonImmutable::now()->subDays(2),
        ]);

        $this->assertTrue(app(AdaptiveCadence::class)->shouldPollContent($account));
    }

    public function test_a_dormant_account_is_demoted_to_one_poll_per_interval(): void
    {
        $account = $this->account();

        // Watched for a month, newest content long outside the window.
        $this->contentCall($account, CarbonImmutable::now()->subDays(30));
        ContentItem::factory()->create([
            'platform_account_id' => $account->id,
            'published_at' => CarbonImmutable::now()->subDays(40),
        ]);

        // No poll within the demoted interval yet → the daily poll runs.
        $this->assertTrue(app(AdaptiveCadence::class)->shouldPollContent($account));

        // A poll two hours ago inside the interval → skip this cycle.
        $this->contentCall($account, CarbonImmutable::now()->subHours(2));
        $this->assertFalse(app(AdaptiveCadence::class)->shouldPollContent($account));
    }

    public function test_a_campaign_attached_creator_uses_the_fast_campaign_tier(): void
    {
        config([
            'qds.ingestion.campaign_content_interval_hours' => 12,
            'qds.ingestion.baseline_content_interval_hours' => 84,
        ]);

        $account = $this->account();

        // Dormant on paper (old content, watched for a month) — but the
        // campaign tier ignores dormancy entirely.
        $this->contentCall($account, CarbonImmutable::now()->subDays(30));
        $this->contentCall($account, CarbonImmutable::now()->subHours(13));
        ContentItem::factory()->create([
            'platform_account_id' => $account->id,
            'published_at' => CarbonImmutable::now()->subDays(40),
        ]);

        SeedingCampaign::factory()
            ->create(['status' => SeedingCampaignStatus::Active])
            ->creators()->attach($account->creator_id);

        // Last poll 13h ago > the 12h campaign interval → due, even though
        // the 84h baseline and the dormancy stretch would both say no.
        $this->assertTrue(app(AdaptiveCadence::class)->shouldPollContent($account));

        // Polled 1h ago → inside the campaign interval → not due.
        $this->contentCall($account, CarbonImmutable::now()->subHour());
        $this->assertFalse(app(AdaptiveCadence::class)->shouldPollContent($account));

        // Campaign tier at "every cycle" (<=6h) always polls.
        config(['qds.ingestion.campaign_content_interval_hours' => 6]);
        $this->assertTrue(app(AdaptiveCadence::class)->shouldPollContent($account));
    }

    public function test_baseline_creators_poll_on_the_plan_baseline_interval(): void
    {
        config(['qds.ingestion.baseline_content_interval_hours' => 84]);

        $account = $this->account();
        ContentItem::factory()->create([
            'platform_account_id' => $account->id,
            'published_at' => CarbonImmutable::now()->subDays(2), // NOT dormant
        ]);

        // Polled 2 days ago — inside the 84h (2×/week) baseline → not due.
        $this->contentCall($account, CarbonImmutable::now()->subHours(48));
        $this->assertFalse(app(AdaptiveCadence::class)->shouldPollContent($account));

        // Polled 4 days ago — past the baseline → due.
        config(['qds.ingestion.baseline_content_interval_hours' => 84]);
        ProviderCall::query()->delete();
        $this->contentCall($account, CarbonImmutable::now()->subHours(96));
        $this->assertTrue(app(AdaptiveCadence::class)->shouldPollContent($account));
    }

    public function test_story_polls_require_recent_story_activity(): void
    {
        $account = $this->account();
        $cadence = app(AdaptiveCadence::class);

        // Never story-polled: probe.
        $this->assertTrue($cadence->shouldPollStories($account));

        // Probed within the interval, still no stories: skip.
        $this->contentCall($account, CarbonImmutable::now()->subHours(3), operation: 'stories.fetch');
        $this->assertFalse($cadence->shouldPollStories($account));

        // A story seen this week re-activates full story cadence.
        Story::factory()->create(['platform_account_id' => $account->id]);
        $this->assertTrue($cadence->shouldPollStories($account));
    }

    public function test_profile_polls_run_on_their_own_slower_interval(): void
    {
        config(['qds.ingestion.profile_poll_interval_hours' => 168]);

        $account = $this->account();
        $cadence = app(AdaptiveCadence::class);

        // Never successfully polled: always fetch.
        $this->assertTrue($cadence->shouldPollProfile($account));

        // Fresh successful profile fetch: skip until the interval elapses.
        $this->contentCall($account, CarbonImmutable::now()->subHours(2), operation: 'profile.fetch');
        $this->assertFalse($cadence->shouldPollProfile($account));

        // Interval 0 = legacy behavior (profile on every cycle).
        config(['qds.ingestion.profile_poll_interval_hours' => 0]);
        $this->assertTrue($cadence->shouldPollProfile($account));

        // A fetch older than the interval is due again (2h-old success,
        // 1h interval).
        config(['qds.ingestion.profile_poll_interval_hours' => 1]);
        $this->assertTrue($cadence->shouldPollProfile($account));
    }

    public function test_disabled_adaptive_flag_removes_only_the_dormancy_stretch(): void
    {
        // The adaptive flag governs DORMANCY, not the plan tiers: with an
        // every-cycle baseline and adaptive off, everything polls.
        config([
            'qds.ingestion.adaptive.enabled' => false,
            'qds.ingestion.baseline_content_interval_hours' => 6,
        ]);

        $account = $this->account();
        $this->contentCall($account, CarbonImmutable::now()->subDays(30));
        $this->contentCall($account, CarbonImmutable::now()->subHours(1));

        $this->assertTrue(app(AdaptiveCadence::class)->shouldPollContent($account));
        $this->assertTrue(app(AdaptiveCadence::class)->shouldPollStories($account));
    }
}
