<?php

namespace Tests\Feature\Monitoring;

use App\Modules\CRM\Models\Creator;
use App\Modules\CRM\Models\PlatformAccount;
use App\Modules\Monitoring\Livewire\Dashboard\CreatorDetail;
use App\Modules\Monitoring\Models\ContentItem;
use App\Shared\Enums\MetricTier;
use App\Shared\Enums\Platform;
use App\Shared\Enums\RoleName;
use App\Shared\ValueObjects\MetricValue;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Creator detail shows the ADR-0024 engagement trend as a DERIVED tile —
 * signed percent when both windows have data, unavailable otherwise.
 */
class CreatorDetailTrendTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
    }

    private function seedPost(PlatformAccount $account, int $daysAgo, float $likes): void
    {
        ContentItem::factory()->create([
            'platform_account_id' => $account->id,
            'platform' => $account->platform,
            'published_at' => CarbonImmutable::now()->subDays($daysAgo),
            'public_metrics' => [new MetricValue($likes, MetricTier::Public, 'likes')],
        ]);
    }

    public function test_changing_the_platform_filter_resets_the_content_paginator(): void
    {
        $creator = Creator::factory()->create();
        $insta = PlatformAccount::factory()->create(['creator_id' => $creator->id, 'platform' => Platform::Instagram->value]);
        $tiktok = PlatformAccount::factory()->create(['creator_id' => $creator->id, 'platform' => Platform::TikTok->value]);

        ContentItem::factory()->count(15)->create(['platform_account_id' => $insta->id, 'platform' => Platform::Instagram->value]);
        ContentItem::factory()->create(['platform_account_id' => $tiktok->id, 'platform' => Platform::TikTok->value]);

        Livewire::actingAs($this->makeUser(RoleName::Analyst))
            ->test(CreatorDetail::class, ['creator' => $creator])
            ->call('setPage', 2, 'content')
            ->assertSet('paginators.content', 2)
            ->set('platform', Platform::TikTok->value)
            ->assertSet('paginators.content', 1);
    }

    public function test_average_and_median_recent_views_include_observed_zeroes(): void
    {
        $creator = Creator::factory()->create();
        $account = PlatformAccount::factory()->create(['creator_id' => $creator->id]);

        ContentItem::factory()->create([
            'platform_account_id' => $account->id,
            'platform' => $account->platform,
            'published_at' => CarbonImmutable::now()->subDay(),
            'public_metrics' => [new MetricValue(0.0, MetricTier::Public, 'views')],
        ]);
        ContentItem::factory()->create([
            'platform_account_id' => $account->id,
            'platform' => $account->platform,
            'published_at' => CarbonImmutable::now()->subDays(2),
            'public_metrics' => [new MetricValue(100.0, MetricTier::Public, 'views')],
        ]);

        // Observed zero is real data — averaging [0, 100] is 50, not 100 (M13).
        Livewire::actingAs($this->makeUser(RoleName::Analyst))
            ->test(CreatorDetail::class, ['creator' => $creator])
            ->assertViewHas('averagePerformance', fn ($m) => $m !== null && abs($m->amount - 50.0) < 1e-9)
            ->assertViewHas('medianPerformance', fn ($m) => $m !== null && abs($m->amount - 50.0) < 1e-9);
    }

    public function test_trend_tile_shows_signed_percent_with_derived_badge(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-07-17 12:00:00'));

        $creator = Creator::factory()->create();
        $account = PlatformAccount::factory()->create(['creator_id' => $creator->id]);
        $this->seedPost($account, 40, 100.0); // previous avg 100
        $this->seedPost($account, 10, 150.0); // current avg 150 → +50%

        Livewire::actingAs($this->makeUser(RoleName::Analyst))
            ->test(CreatorDetail::class, ['creator' => $creator])
            ->assertSee('Engagement trend')
            ->assertSee('+50%')
            ->assertSee('last 30 days');
    }

    public function test_trend_tile_is_unavailable_without_enough_history(): void
    {
        $creator = Creator::factory()->create();
        PlatformAccount::factory()->create(['creator_id' => $creator->id]);

        Livewire::actingAs($this->makeUser(RoleName::Analyst))
            ->test(CreatorDetail::class, ['creator' => $creator])
            ->assertSee('Engagement trend')
            ->assertSee('Not enough posts in the two comparison windows yet.');
    }
}
