<?php

namespace Tests\Feature\Analytics;

use App\Modules\CRM\Models\Creator;
use App\Modules\CRM\Models\PlatformAccount;
use App\Modules\Monitoring\Models\ContentItem;
use App\Modules\Monitoring\Models\MetricSnapshot;
use App\Platform\Analytics\Contracts\AnalyticsService;
use App\Platform\Analytics\RollupReader;
use App\Shared\Enums\MetricTier;
use App\Shared\Enums\Platform;
use App\Shared\ValueObjects\MetricValue;
use App\Shared\ValueObjects\Provenance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * creatorTotals() narrowed to a creator set (the monitoring "Active
 * seeding only" filter). NULL ⇒ unavailable stays intact (DP-001): an
 * empty set aggregates no rows and must come back null, never zero.
 */
class CreatorTotalsFilterTest extends TestCase
{
    use RefreshDatabase;

    /** Seed one creator whose content carries the given measured view count. */
    private function seedCreatorViews(int $views): Creator
    {
        $creator = Creator::factory()->create();
        $account = PlatformAccount::factory()->create([
            'creator_id' => $creator->id,
            'platform' => Platform::Instagram,
        ]);
        $content = ContentItem::factory()->create([
            'platform_account_id' => $account->id,
            'published_at' => '2026-06-10 12:00:00',
        ]);

        MetricSnapshot::create([
            'content_item_id' => $content->id,
            'captured_at' => '2026-06-12 09:00:00',
            'metrics' => [new MetricValue($views, MetricTier::Public, 'views')],
            'provenance' => new Provenance('SRC-apify-instagram-scraper', now()->toImmutable(), 'v1'),
        ]);

        return $creator;
    }

    public function test_creator_set_narrows_totals_and_null_means_unfiltered(): void
    {
        $a = $this->seedCreatorViews(100);
        $this->seedCreatorViews(999);

        app(AnalyticsService::class)->refreshRollups();
        $reader = app(RollupReader::class);

        // null = today's unfiltered behavior: both creators.
        $this->assertSame(1099.0, (float) $reader->creatorTotals()->views_sum);

        // A one-creator set sums only that creator's buckets.
        $this->assertSame(100.0, (float) $reader->creatorTotals(creatorIds: [$a->id])->views_sum);
    }

    public function test_empty_creator_set_returns_null_sums_never_zero(): void
    {
        $this->seedCreatorViews(100);

        app(AnalyticsService::class)->refreshRollups();

        $totals = app(RollupReader::class)->creatorTotals(creatorIds: []);

        // DP-001: aggregate over no rows is null (unavailable), not zero.
        $this->assertNull($totals->views_sum);
        $this->assertNull($totals->engagement_sum);
    }
}
