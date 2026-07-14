<?php

namespace Tests\Feature\Enrichment;

use App\Modules\CRM\Models\PlatformAccount;
use App\Modules\Monitoring\Models\ContentItem;
use App\Modules\Monitoring\Models\ReachConfiguration;
use App\Platform\Enrichment\Contracts\ReachEstimator;
use App\Platform\Enrichment\Reach\DefaultReachEstimator;
use App\Platform\Enrichment\Reach\ReachCalculator;
use App\Shared\Enums\MetricTier;
use App\Shared\Enums\Platform;
use App\Shared\ValueObjects\MetricValue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReachCalculatorTest extends TestCase
{
    use RefreshDatabase;

    private function activeConfig(array $params = ['view_weight' => 0.7, 'follower_weight' => 0.1]): ReachConfiguration
    {
        return ReachConfiguration::factory()->active()->create(['params' => $params]);
    }

    private function content(?int $followers, array $metrics): ContentItem
    {
        $account = PlatformAccount::factory()->create([
            'follower_count' => $followers === null ? null : new MetricValue($followers, MetricTier::Public, 'followers'),
        ]);

        return ContentItem::factory()->create([
            'platform_account_id' => $account->id,
            'platform' => Platform::Instagram,
            'public_metrics' => $metrics,
        ]);
    }

    public function test_reach_is_unavailable_without_an_active_configuration(): void
    {
        $content = $this->content(5000, [new MetricValue(1000, MetricTier::Public, 'views')]);
        $this->assertNull(app(ReachCalculator::class)->calculate($content));
        $this->assertDatabaseCount('reach_results', 0);
    }

    public function test_reach_is_view_weight_times_views_plus_follower_weight_times_followers(): void
    {
        $this->activeConfig();
        $content = $this->content(5000, [new MetricValue(1000, MetricTier::Public, 'views')]);

        $result = app(ReachCalculator::class)->calculate($content)->fresh();

        // round(0.7*1000 + 0.1*5000) = 1200
        $this->assertSame(1200.0, $result->value->amount);
        $this->assertSame(MetricTier::Estimated, $result->value->tier);
        $this->assertNotSame('', $result->value->method);
    }

    public function test_a_missing_follower_signal_contributes_nothing_but_still_estimates(): void
    {
        $this->activeConfig();
        $content = $this->content(null, [new MetricValue(1000, MetricTier::Public, 'views')]);

        $result = app(ReachCalculator::class)->calculate($content)->fresh();

        // round(0.7*1000 + 0.1*0) = 700
        $this->assertSame(700.0, $result->value->amount);
    }

    public function test_no_observed_input_at_all_is_unavailable_not_zero(): void
    {
        $this->activeConfig();
        $content = $this->content(null, []);

        $this->assertNull(app(ReachCalculator::class)->calculate($content));
        $this->assertDatabaseCount('reach_results', 0);
    }

    public function test_reach_estimator_binding_is_the_real_estimator(): void
    {
        $this->assertInstanceOf(DefaultReachEstimator::class, app(ReachEstimator::class));
    }
}
