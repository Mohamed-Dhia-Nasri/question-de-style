<?php

namespace Tests\Feature\Monitoring;

use App\Modules\CRM\Models\Creator;
use App\Modules\CRM\Models\PlatformAccount;
use App\Modules\CRM\Models\SeedingCampaign;
use App\Modules\Monitoring\Livewire\Dashboard\MonitoringOverview;
use App\Modules\Monitoring\Models\ContentItem;
use App\Modules\Monitoring\Models\MonitoredSubject;
use App\Shared\Enums\MetricTier;
use App\Shared\Enums\MonitoredSubjectType;
use App\Shared\Enums\Platform;
use App\Shared\Enums\RoleName;
use App\Shared\Enums\SeedingCampaignStatus;
use App\Shared\ValueObjects\MetricValue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The roster-first Monitoring Overview and its "In active seeding" toggle
 * (spec 2026-07-17): the toggle re-scopes both the headline counts and the
 * creator grid to creators enrolled in ACTIVE/SHIPPING seeding. Toggle OFF
 * counts the whole roster; toggle ON with an empty set filters to zero, never
 * silently unfiltered. Platform + name search narrow the grid, and enrolled
 * creators carry the "In active seeding" tag.
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

    private function rosterCreator(string $name): Creator
    {
        $creator = Creator::factory()->create(['display_name' => $name]);
        MonitoredSubject::factory()->create([
            'subject_type' => MonitoredSubjectType::Creator->value,
            'creator_id' => $creator->id,
            'active' => true,
        ]);

        return $creator;
    }

    /**
     * One enrolled + one unenrolled roster creator, each with a platform
     * account and a content item published inside the 90-day window.
     *
     * @return array{seeded: Creator, other: Creator}
     */
    private function seedWorld(): array
    {
        $world = [];

        foreach (['seeded' => 'Seeded Sam', 'other' => 'Plain Pat'] as $key => $name) {
            $creator = $this->rosterCreator($name);
            $account = PlatformAccount::factory()->create([
                'creator_id' => $creator->id,
                'platform' => Platform::Instagram,
            ]);
            ContentItem::factory()->create([
                'platform_account_id' => $account->id,
                'published_at' => now()->subDays(3),
            ]);

            $world[$key] = $creator;
        }

        $run = SeedingCampaign::factory()->create(['status' => SeedingCampaignStatus::Active]);
        $run->creators()->attach([$world['seeded']->id]);

        return $world;
    }

    public function test_toggle_off_counts_the_whole_roster(): void
    {
        $this->seedWorld();

        Livewire::test(MonitoringOverview::class)
            ->assertSet('activeSeedingOnly', false)
            ->assertViewHas('totalPosts', 2)
            ->assertViewHas('seedingSetEmpty', fn (mixed $empty): bool => $empty === false)
            // Both creators appear as cards.
            ->assertSee('Seeded Sam')
            ->assertSee('Plain Pat');
    }

    public function test_toggle_on_scopes_the_totals_and_the_grid_to_enrolled_creators(): void
    {
        $this->seedWorld();

        Livewire::test(MonitoringOverview::class)
            ->set('activeSeedingOnly', true)
            ->assertViewHas('totalPosts', 1)
            ->assertViewHas('seedingSetEmpty', fn (mixed $empty): bool => $empty === false)
            // Only the enrolled creator's card remains.
            ->assertSee('Seeded Sam')
            ->assertDontSee('Plain Pat');
    }

    public function test_toggle_on_with_no_active_seeding_filters_to_zero_not_unfiltered(): void
    {
        $this->seedWorld();
        // Retire the only active run: enrolled creators exist, but no ACTIVE/SHIPPING campaign.
        SeedingCampaign::query()->update(['status' => SeedingCampaignStatus::Completed->value]);

        Livewire::test(MonitoringOverview::class)
            ->set('activeSeedingOnly', true)
            ->assertViewHas('totalPosts', 0)
            ->assertViewHas('seedingSetEmpty', fn (mixed $empty): bool => $empty === true)
            ->assertSee('No creators are currently in an active seeding.')
            ->assertDontSee('Seeded Sam');
    }

    public function test_the_headline_totals_sum_each_posts_latest_metrics_over_90_days(): void
    {
        $creator = $this->rosterCreator('Metric Mabel');
        $account = PlatformAccount::factory()->create([
            'creator_id' => $creator->id,
            'platform' => Platform::Instagram,
        ]);

        // Two posts, each counted ONCE at its latest reading (public_metrics).
        foreach ([[1000, 80, 20], [500, 40, 10]] as [$views, $likes, $comments]) {
            ContentItem::factory()->create([
                'platform_account_id' => $account->id,
                'published_at' => now()->subDays(3),
                'public_metrics' => [
                    new MetricValue($views, MetricTier::Public, 'views'),
                    new MetricValue($likes, MetricTier::Public, 'likes'),
                    new MetricValue($comments, MetricTier::Public, 'comments'),
                ],
            ]);
        }

        Livewire::test(MonitoringOverview::class)
            ->assertViewHas('totalPosts', 2)
            ->assertViewHas('totalViews', fn (mixed $v): bool => (int) $v === 1500)   // 1000 + 500
            ->assertViewHas('totalLikes', fn (mixed $v): bool => (int) $v === 120)    // 80 + 40
            ->assertViewHas('totalComments', fn (mixed $v): bool => (int) $v === 30)  // 20 + 10
            ->assertSee('Total posts')
            ->assertSee('Total views')
            ->assertSee('1,500'); // views, thousands-formatted
    }

    public function test_a_post_older_than_90_days_is_excluded_from_the_totals(): void
    {
        $creator = $this->rosterCreator('Old Olive');
        $account = PlatformAccount::factory()->create([
            'creator_id' => $creator->id,
            'platform' => Platform::Instagram,
        ]);
        ContentItem::factory()->create([
            'platform_account_id' => $account->id,
            'published_at' => now()->subDays(120), // outside the window
            'public_metrics' => [new MetricValue(9999, MetricTier::Public, 'views')],
        ]);

        Livewire::test(MonitoringOverview::class)
            ->assertViewHas('totalPosts', 0)
            ->assertViewHas('totalViews', fn (mixed $v): bool => $v === null); // nothing in window
    }

    public function test_the_in_active_seeding_tag_shows_only_for_enrolled_creators(): void
    {
        $seeded = $this->rosterCreator('Seeded Sam');
        $run = SeedingCampaign::factory()->create(['status' => SeedingCampaignStatus::Active]);
        $run->creators()->attach([$seeded->id]);

        Livewire::test(MonitoringOverview::class)
            ->assertSee('Seeded Sam')
            ->assertSee('In active seeding');
    }

    public function test_unseeded_creators_carry_no_seeding_tag(): void
    {
        $this->rosterCreator('Plain Pat');

        Livewire::test(MonitoringOverview::class)
            ->assertSee('Plain Pat')
            ->assertDontSee('In active seeding');
    }

    public function test_search_narrows_the_creator_grid(): void
    {
        $this->rosterCreator('Anna Weber');
        $this->rosterCreator('Zoe Martin');

        Livewire::test(MonitoringOverview::class)
            ->assertSee('Anna Weber')
            ->assertSee('Zoe Martin')
            ->set('search', 'Anna')
            ->assertSee('Anna Weber')
            ->assertDontSee('Zoe Martin');
    }

    public function test_the_roster_subtitle_says_matching_when_filtered_and_on_the_roster_when_not(): void
    {
        // $creators->total() is the count AFTER filters, so the subtitle must
        // not call a narrowed subset "on the roster" — that would misreport the
        // true roster size.
        $this->rosterCreator('Anna Weber');
        $this->rosterCreator('Zoe Martin');

        Livewire::test(MonitoringOverview::class)
            ->assertSee('on the roster')
            ->assertDontSee('matching')
            ->set('search', 'Anna')
            ->assertSee('matching')
            ->assertDontSee('on the roster');
    }

    public function test_an_out_of_range_page_does_not_show_the_add_creators_empty_state(): void
    {
        // 13 roster creators paginate to two pages of 12. Page 3 is out of
        // range: the current page is empty but the roster is NOT. The empty
        // state gates on total(), so it must NOT falsely tell the operator to
        // add creators over a populated roster (paginate() never clamps page).
        for ($i = 0; $i < 13; $i++) {
            $this->rosterCreator(sprintf('Creator %02d', $i));
        }

        Livewire::test(MonitoringOverview::class)
            ->call('setPage', 3)
            ->assertDontSee('Add creators to the roster')
            ->assertDontSee('No monitored creators match');
    }

    public function test_the_platform_filter_narrows_the_creator_grid(): void
    {
        $ivy = $this->rosterCreator('Insta Ivy');
        PlatformAccount::factory()->create(['creator_id' => $ivy->id, 'platform' => Platform::Instagram]);

        $tom = $this->rosterCreator('Tube Tom');
        PlatformAccount::factory()->create(['creator_id' => $tom->id, 'platform' => Platform::YouTube]);

        Livewire::test(MonitoringOverview::class)
            ->set('platform', Platform::YouTube->value)
            ->assertSee('Tube Tom')
            ->assertDontSee('Insta Ivy');
    }

    public function test_url_round_trip_restores_the_toggle(): void
    {
        Livewire::withQueryParams(['activeSeedingOnly' => true])
            ->test(MonitoringOverview::class)
            ->assertSet('activeSeedingOnly', true);
    }

    public function test_toggle_intersects_with_the_platform_filter_for_the_posts_total(): void
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
            'published_at' => now()->subDays(3),
        ]);

        Livewire::test(MonitoringOverview::class)
            ->set('activeSeedingOnly', true)
            ->set('platform', Platform::TikTok->value)
            ->assertViewHas('totalPosts', 1)   // TikTok ∩ enrolled — not the Instagram item
            ->assertSee('Seeded Sam')
            ->assertDontSee('Plain Pat');
    }

    public function test_the_empty_seeding_notice_shows_only_when_toggled_on_with_no_active_seeding(): void
    {
        $this->seedWorld();

        Livewire::test(MonitoringOverview::class)
            ->assertDontSee('No creators are currently in an active seeding.');

        // Enrolled creators exist → no notice even with the toggle on.
        Livewire::test(MonitoringOverview::class)
            ->set('activeSeedingOnly', true)
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
        $this->rosterCreator('Tenant A Creator');

        Livewire::test(MonitoringOverview::class)
            ->set('activeSeedingOnly', true)
            // Tenant B's enrollment must not leak in — the set reads empty here.
            ->assertViewHas('seedingSetEmpty', fn (mixed $empty): bool => $empty === true)
            ->assertViewHas('totalPosts', 0);
    }
}
