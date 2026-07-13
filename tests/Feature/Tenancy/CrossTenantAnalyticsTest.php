<?php

namespace Tests\Feature\Tenancy;

use App\Models\Tenant;
use App\Modules\CRM\Models\Brand;
use App\Modules\CRM\Models\Campaign;
use App\Modules\CRM\Models\Client;
use App\Modules\CRM\Models\Creator;
use App\Modules\CRM\Models\PlatformAccount;
use App\Modules\Monitoring\Models\ContentItem;
use App\Modules\Monitoring\Models\Mention;
use App\Modules\Monitoring\Models\MetricSnapshot;
use App\Modules\Monitoring\Models\MonitoredSubject;
use App\Platform\Analytics\Contracts\AnalyticsService;
use App\Platform\Analytics\RollupReader;
use App\Shared\Enums\MetricTier;
use App\Shared\Enums\Platform;
use App\Shared\Tenancy\TenantContext;
use App\Shared\ValueObjects\MetricValue;
use App\Shared\ValueObjects\Provenance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ADR-0019 acceptance criteria 11 + 14: analytics are tenant-isolated and
 * Tenant B records never influence Tenant A totals. RollupReader reads the
 * shared rollup_* materialized views with a RAW query builder (no Eloquent
 * scope), so isolation is intrinsic to the reader — this proves it end to
 * end through a real rollup refresh over data seeded in TWO tenants.
 */
class CrossTenantAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    /** Seed one measured, campaign-attributed mention with the given view count in the active tenant. */
    private function seedMention(int $views): void
    {
        $client = Client::factory()->create();
        $brand = Brand::factory()->create(['client_id' => $client->id]);
        $campaign = Campaign::factory()->create(['brand_id' => $brand->id]);

        $creator = Creator::factory()->create();
        $account = PlatformAccount::factory()->create([
            'creator_id' => $creator->id,
            'platform' => Platform::Instagram,
        ]);
        $content = ContentItem::factory()->create(['platform_account_id' => $account->id]);

        MetricSnapshot::create([
            'content_item_id' => $content->id,
            'captured_at' => '2026-06-12 09:00:00',
            'metrics' => [new MetricValue($views, MetricTier::Public, 'views')],
            'provenance' => new Provenance('SRC-apify-instagram-scraper', now()->toImmutable(), 'v1'),
        ]);

        Mention::factory()->create([
            'monitored_subject_id' => MonitoredSubject::factory()->create(['creator_id' => $creator->id])->id,
            'content_item_id' => $content->id,
            'story_id' => null,
            'campaign_id' => $campaign->id,
        ]);
    }

    public function test_tenant_b_mentions_never_reach_tenant_a_totals(): void
    {
        $context = app(TenantContext::class);
        $tenantA = $this->defaultTenant;
        $tenantB = $this->makeTenant('Tenant B');

        // Tenant A: 100 views. Tenant B: 999 views.
        $context->runAs($tenantA, fn () => $this->seedMention(100));
        $context->runAs($tenantB, fn () => $this->seedMention(999));

        app(AnalyticsService::class)->refreshRollups();

        $reader = app(RollupReader::class);

        // Reading under Tenant A sees ONLY the 100 views — never 100+999.
        $totalsA = $context->runAs($tenantA, fn () => $reader->mentionTotals());
        $this->assertSame(1, $totalsA->mention_count);
        $this->assertSame(100.0, (float) $totalsA->total_views);

        // Reading under Tenant B sees ONLY its own 999.
        $totalsB = $context->runAs($tenantB, fn () => $reader->mentionTotals());
        $this->assertSame(1, $totalsB->mention_count);
        $this->assertSame(999.0, (float) $totalsB->total_views);

        // Platform context (no bound tenant) legitimately spans both — the
        // aggregate is the sum, confirming the isolation above is the tenant
        // predicate at work, not empty data.
        $totalsPlatform = $context->runAs(null, fn () => $reader->mentionTotals());
        $this->assertSame(2, $totalsPlatform->mention_count);
        $this->assertSame(1099.0, (float) $totalsPlatform->total_views);
    }

    public function test_creator_totals_and_buckets_are_tenant_scoped(): void
    {
        $context = app(TenantContext::class);
        $tenantA = $this->defaultTenant;
        $tenantB = $this->makeTenant('Tenant B');

        $context->runAs($tenantA, fn () => $this->seedMention(100));
        $context->runAs($tenantB, fn () => $this->seedMention(999));

        app(AnalyticsService::class)->refreshRollups();

        $reader = app(RollupReader::class);

        // latestCreatorBuckets returns per-creator rows; under A only A's
        // creator appears, so B's roster size/identity never leaks.
        $bucketsA = $context->runAs($tenantA, fn () => $reader->latestCreatorBuckets('week'));
        $bucketsB = $context->runAs($tenantB, fn () => $reader->latestCreatorBuckets('week'));

        $this->assertCount(1, $bucketsA);
        $this->assertCount(1, $bucketsB);
        $this->assertNotEquals(
            $bucketsA->first()->creator_id,
            $bucketsB->first()->creator_id,
            'Each tenant must see only its own creator in the rollup',
        );
    }
}
