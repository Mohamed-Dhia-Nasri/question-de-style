<?php

namespace Tests\Feature\Monitoring;

use App\Modules\CRM\Models\Creator;
use App\Modules\CRM\Models\PlatformAccount;
use App\Modules\CRM\Models\SeedingCampaign;
use App\Modules\Monitoring\Livewire\Dashboard\MonitoringOverview;
use App\Modules\Monitoring\Models\ContentItem;
use App\Modules\Monitoring\Models\Mention;
use App\Modules\Monitoring\Models\MetricSnapshot;
use App\Modules\Monitoring\Models\MonitoredSubject;
use App\Modules\Monitoring\Models\Story;
use App\Platform\Analytics\Contracts\AnalyticsService;
use App\Shared\Enums\MetricTier;
use App\Shared\Enums\MonitoredSubjectType;
use App\Shared\Enums\Platform;
use App\Shared\Enums\RoleName;
use App\Shared\Enums\SeedingCampaignStatus;
use App\Shared\ValueObjects\MetricValue;
use App\Shared\ValueObjects\Provenance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The "Active seeding only" toggle (spec 2026-07-17): re-scopes roster,
 * new-content, active-stories, mentions-by-type and the creatorTotals KPI
 * to creators enrolled in ACTIVE/SHIPPING seeding. Toggle OFF must be
 * byte-identical to today; toggle ON with an empty set filters to zero,
 * never silently unfiltered.
 */
class MonitoringSeedingFilterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
        $this->actingAs($this->makeUser(RoleName::Analyst));
    }

    public function test_the_overview_shows_the_engagement_breakdown_by_type(): void
    {
        $creator = Creator::factory()->create();
        $account = PlatformAccount::factory()->create(['creator_id' => $creator->id, 'platform' => Platform::Instagram]);
        $content = ContentItem::factory()->create(['platform_account_id' => $account->id]);

        MetricSnapshot::create([
            'content_item_id' => $content->id,
            'captured_at' => now(),
            'metrics' => [
                new MetricValue(400, MetricTier::Public, 'likes'),
                new MetricValue(90, MetricTier::Public, 'comments'),
                new MetricValue(30, MetricTier::Public, 'shares'),
                new MetricValue(20, MetricTier::Public, 'saves'),
            ],
            'provenance' => new Provenance('SRC-apify-instagram-profile-scraper', now()->toImmutable(), 'v1'),
        ]);

        app(AnalyticsService::class)->refreshRollups();

        Livewire::test(MonitoringOverview::class)
            ->assertSee('Engagement')
            ->assertSee('Likes')
            ->assertSee('Comments')
            ->assertSee('Shares')
            ->assertSee('Saves')
            ->assertSee('400')   // likes
            ->assertSee('90')    // comments
            ->assertSee('540');  // engagement total = 400+90+30+20
    }

    public function test_week_grain_overview_aligns_the_label_and_live_counts_to_whole_weeks(): void
    {
        // 2026-07-13 is a Monday; 2026-07-15 is the Wednesday of the same ISO
        // week. A mention on the Monday must be reflected consistently: the
        // week-grain KPI rollups always count it, so the range label and the
        // live mentions-by-type count must too (M14/M25).
        $creator = Creator::factory()->create();
        $subject = MonitoredSubject::factory()->create([
            'subject_type' => MonitoredSubjectType::Creator->value,
            'creator_id' => $creator->id,
            'active' => true,
        ]);
        $account = PlatformAccount::factory()->create(['creator_id' => $creator->id, 'platform' => Platform::Instagram]);
        $content = ContentItem::factory()->create(['platform_account_id' => $account->id]);
        $mention = Mention::factory()->create([
            'monitored_subject_id' => $subject->id,
            'content_item_id' => $content->id,
        ]);
        DB::table('mentions')->where('id', $mention->id)->update(['created_at' => '2026-07-13 09:00:00']);

        Livewire::test(MonitoringOverview::class)
            ->set('from', '2026-07-15')
            ->assertViewHas('rangeLabel', fn (string $label) => str_contains($label, '13 Jul 2026'))
            ->assertViewHas('mentionsByType', fn (Collection $c) => (int) $c->sum() === 1);
    }

    /**
     * One enrolled + one unenrolled roster creator, each with content,
     * an active story and a mention.
     *
     * @return array{seeded: Creator, other: Creator}
     */
    private function seedWorld(): array
    {
        $world = [];

        foreach (['seeded', 'other'] as $key) {
            $creator = Creator::factory()->create();
            $subject = MonitoredSubject::factory()->create([
                'subject_type' => MonitoredSubjectType::Creator->value,
                'creator_id' => $creator->id,
                'active' => true,
            ]);
            $account = PlatformAccount::factory()->create([
                'creator_id' => $creator->id,
                'platform' => Platform::Instagram,
            ]);
            $content = ContentItem::factory()->create(['platform_account_id' => $account->id]);
            Story::factory()->create(['platform_account_id' => $account->id]);
            Mention::factory()->create([
                'monitored_subject_id' => $subject->id,
                'content_item_id' => $content->id,
            ]);

            $world[$key] = $creator;
        }

        $run = SeedingCampaign::factory()->create(['status' => SeedingCampaignStatus::Active]);
        $run->creators()->attach([$world['seeded']->id]);

        return $world;
    }

    public function test_toggle_off_counts_all_roster_creators(): void
    {
        $this->seedWorld();

        Livewire::test(MonitoringOverview::class)
            ->assertSet('activeSeedingOnly', false)
            ->assertViewHas('rosterCount', 2)
            ->assertViewHas('newContent', 2)
            ->assertViewHas('activeStories', 2)
            ->assertViewHas('seedingSetEmpty', fn (mixed $empty): bool => $empty === false)
            ->assertViewHas('mentionsByType', fn (Collection $byType): bool => (int) $byType->sum() === 2);
    }

    public function test_toggle_on_scopes_every_creator_keyed_card_to_enrolled_creators(): void
    {
        $this->seedWorld();

        Livewire::test(MonitoringOverview::class)
            ->set('activeSeedingOnly', true)
            ->assertViewHas('rosterCount', 1)
            ->assertViewHas('newContent', 1)
            ->assertViewHas('activeStories', 1)
            ->assertViewHas('seedingSetEmpty', fn (mixed $empty): bool => $empty === false)
            ->assertViewHas('mentionsByType', fn (Collection $byType): bool => (int) $byType->sum() === 1);
    }

    public function test_toggle_on_with_no_active_seeding_filters_to_zero_not_unfiltered(): void
    {
        $this->seedWorld();
        // Retire the only active run: enrolled creators exist, but no ACTIVE/SHIPPING campaign.
        SeedingCampaign::query()->update(['status' => SeedingCampaignStatus::Completed->value]);

        Livewire::test(MonitoringOverview::class)
            ->set('activeSeedingOnly', true)
            ->assertViewHas('rosterCount', 0)
            ->assertViewHas('newContent', 0)
            ->assertViewHas('activeStories', 0)
            ->assertViewHas('seedingSetEmpty', fn (mixed $empty): bool => $empty === true)
            ->assertViewHas('mentionsByType', fn (Collection $byType): bool => (int) $byType->sum() === 0)
            // KPI aggregate over no rows: null (unavailable), never zero (DP-001).
            ->assertViewHas('creatorTotals', fn (object $totals): bool => $totals->views_sum === null);
    }

    public function test_url_round_trip_restores_the_toggle(): void
    {
        Livewire::withQueryParams(['activeSeedingOnly' => true])
            ->test(MonitoringOverview::class)
            ->assertSet('activeSeedingOnly', true);
    }

    public function test_toggle_intersects_with_the_platform_filter(): void
    {
        $world = $this->seedWorld(); // seeded creator has 1 Instagram content item

        // Give the enrolled creator a TikTok item too.
        $ttAccount = PlatformAccount::factory()->create([
            'creator_id' => $world['seeded']->id,
            'platform' => Platform::TikTok,
        ]);
        ContentItem::factory()->create([
            'platform_account_id' => $ttAccount->id,
            'platform' => Platform::TikTok,
        ]);

        Livewire::test(MonitoringOverview::class)
            ->set('activeSeedingOnly', true)
            ->set('platform', Platform::TikTok->value)
            ->assertViewHas('newContent', 1)   // TikTok ∩ enrolled — not the Instagram item
            // Roster card ignores platform by design; it responds only to the toggle.
            ->assertViewHas('rosterCount', 1);
    }

    public function test_page_renders_toggle_and_scoped_view_with_brand_only_reach_state(): void
    {
        $this->seedWorld();

        Livewire::test(MonitoringOverview::class)
            ->assertSee('Active seeding only')
            ->assertDontSee('Aggregated by brand — not available for the seeding filter.')
            ->set('activeSeedingOnly', true)
            // Reach/EMV: message only, no brand-wide number, distinct from
            // the generic rollup-unavailable copy.
            ->assertSee('Aggregated by brand — not available for the seeding filter.')
            ->assertDontSee('No estimated reach for the selected dates yet');
    }

    public function test_empty_set_notice_shows_only_when_toggled_on_with_no_active_seeding(): void
    {
        $this->seedWorld();

        $component = Livewire::test(MonitoringOverview::class)
            ->assertDontSee('No creators are currently in an active seeding.');

        // Enrolled creators exist → no notice even when counts are zeroed
        // by a restrictive date range.
        $component->set('activeSeedingOnly', true)
            ->set('from', '2030-01-01')
            ->set('to', '2030-01-02')
            ->assertDontSee('No creators are currently in an active seeding.');

        SeedingCampaign::query()->update(['status' => SeedingCampaignStatus::Completed->value]);

        Livewire::test(MonitoringOverview::class)
            ->set('activeSeedingOnly', true)
            ->assertSee('No creators are currently in an active seeding.');
    }

    public function test_cross_tenant_active_seeding_never_leaks_into_this_tenants_page(): void
    {
        // Tenant B: fully active seeding world.
        $tenantB = $this->makeTenant('Tenant B');
        $this->withTenant($tenantB, function (): void {
            $creator = Creator::factory()->create();
            MonitoredSubject::factory()->create([
                'subject_type' => MonitoredSubjectType::Creator->value,
                'creator_id' => $creator->id,
                'active' => true,
            ]);
            $run = SeedingCampaign::factory()->create(['status' => SeedingCampaignStatus::Active]);
            $run->creators()->attach([$creator->id]);
        });

        // Tenant A (default): roster creator, NO active seeding.
        $creatorA = Creator::factory()->create();
        MonitoredSubject::factory()->create([
            'subject_type' => MonitoredSubjectType::Creator->value,
            'creator_id' => $creatorA->id,
            'active' => true,
        ]);

        Livewire::test(MonitoringOverview::class)
            ->set('activeSeedingOnly', true)
            ->assertViewHas('rosterCount', 0)       // tenant B's enrollment must not count here
            ->assertViewHas('seedingSetEmpty', fn (mixed $empty): bool => $empty === true);
    }
}
