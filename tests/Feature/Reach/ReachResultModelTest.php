<?php

namespace Tests\Feature\Reach;

use App\Modules\CRM\Models\PlatformAccount;
use App\Modules\Monitoring\Models\ContentItem;
use App\Modules\Monitoring\Models\ReachConfiguration;
use App\Modules\Monitoring\Models\ReachResult;
use App\Shared\Enums\MetricTier;
use App\Shared\ValueObjects\ReachEstimate;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

class ReachResultModelTest extends TestCase
{
    use RefreshDatabase;

    private function makeResult(): ReachResult
    {
        $config = ReachConfiguration::factory()->active()->create();
        $account = PlatformAccount::factory()->create();
        $content = ContentItem::factory()->create(['platform_account_id' => $account->id]);

        return ReachResult::query()->create([
            'content_item_id' => $content->id,
            'reach_configuration_id' => $config->id,
            'formula_version' => $config->formula_version,
            'value' => new ReachEstimate(1200.0, MetricTier::Estimated, 'qds-estimated-reach v1'),
            'inputs' => ['views' => 1000, 'followers' => 5000],
            'calculated_at' => CarbonImmutable::now(),
        ]);
    }

    public function test_value_round_trips_as_a_reach_estimate(): void
    {
        $result = $this->makeResult()->fresh();
        $this->assertInstanceOf(ReachEstimate::class, $result->value);
        $this->assertSame(1200.0, $result->value->amount);
        $this->assertSame(MetricTier::Estimated, $result->value->tier);
    }

    public function test_is_append_only(): void
    {
        $result = $this->makeResult();
        $this->expectException(LogicException::class);
        $result->update(['formula_version' => 'changed']);
    }

    public function test_configuration_relation_resolves(): void
    {
        $result = $this->makeResult();
        $this->assertInstanceOf(ReachConfiguration::class, $result->configuration);
    }
}
