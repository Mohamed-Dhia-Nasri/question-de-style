<?php

namespace Tests\Feature\Monitoring;

use App\Models\User;
use App\Modules\CRM\Models\Creator;
use App\Modules\CRM\Models\PlatformAccount;
use App\Modules\Monitoring\Models\ContentItem;
use App\Modules\Monitoring\Models\ReachConfiguration;
use App\Modules\Monitoring\Models\ReachResult;
use App\Shared\Enums\MetricTier;
use App\Shared\Enums\RoleName;
use App\Shared\ValueObjects\ReachEstimate;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Content-detail "Estimated reach" tile (Task B5, REQ-M1-006, ADR-0022):
 * shows the latest stored reach_results value (amount, ESTIMATED tier,
 * disclosed method) once one exists; "unavailable" otherwise.
 */
class ContentDetailReachTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
    }

    private function staffUser(): User
    {
        return $this->makeUser(RoleName::Analyst);
    }

    private function contentItem(): ContentItem
    {
        $account = PlatformAccount::factory()->create(['creator_id' => Creator::factory()->create()->id]);

        return ContentItem::factory()->create(['platform_account_id' => $account->id]);
    }

    public function test_content_detail_shows_the_latest_stored_reach_result(): void
    {
        $content = $this->contentItem();
        $config = ReachConfiguration::factory()->active()->create();

        // An older result should be superseded by the latest one shown.
        ReachResult::query()->create([
            'content_item_id' => $content->id,
            'reach_configuration_id' => $config->id,
            'formula_version' => $config->formula_version,
            'value' => new ReachEstimate(10.0, MetricTier::Estimated, 'qds-estimated-reach v0'),
            'inputs' => ['views' => 100, 'followers' => 200],
            'calculated_at' => CarbonImmutable::now()->subDay(),
        ]);
        ReachResult::query()->create([
            'content_item_id' => $content->id,
            'reach_configuration_id' => $config->id,
            'formula_version' => $config->formula_version,
            'value' => new ReachEstimate(48213.0, MetricTier::Estimated, 'qds-estimated-reach v1'),
            'inputs' => ['views' => 40000, 'followers' => 12000],
            'calculated_at' => CarbonImmutable::now(),
        ]);

        $this->actingAs($this->staffUser());

        $this->get("/monitoring/content/{$content->id}")
            ->assertOk()
            ->assertSee('48,213')
            ->assertDontSee('method: qds-estimated-reach v0')
            ->assertSee('Estimate')
            ->assertSee('method: qds-estimated-reach v1');
    }

    public function test_content_detail_shows_unavailable_when_no_reach_result_exists(): void
    {
        $content = $this->contentItem();

        $this->actingAs($this->staffUser());

        $this->get("/monitoring/content/{$content->id}")
            ->assertOk()
            ->assertSee('Estimated reach is unavailable', false);
    }
}
