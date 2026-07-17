<?php

namespace Tests\Feature\Monitoring;

use App\Modules\CRM\Models\Creator;
use App\Modules\CRM\Models\PlatformAccount;
use App\Modules\Monitoring\Livewire\Dashboard\CreatorDetail;
use App\Modules\Monitoring\Models\ContentItem;
use App\Shared\Enums\MetricTier;
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
