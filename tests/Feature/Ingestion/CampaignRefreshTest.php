<?php

namespace Tests\Feature\Ingestion;

use App\Modules\CRM\Models\Creator;
use App\Modules\CRM\Models\PlatformAccount;
use App\Modules\CRM\Models\SeedingCampaign;
use App\Modules\CRM\Models\Shipment;
use App\Modules\Monitoring\Models\ContentItem;
use App\Platform\Ingestion\Jobs\RefreshCampaignContentJob;
use App\Platform\Ingestion\Models\ProviderCall;
use App\Platform\Ingestion\Observability\ProviderCallRecorder;
use App\Platform\Ingestion\Observability\ProviderCircuitBreaker;
use App\Platform\Ingestion\Persistence\ContentItemPersister;
use App\Platform\Ingestion\Providers\Instagram\InstagramDirectUrlAdapter;
use App\Shared\Enums\Platform;
use App\Shared\Enums\SeedingCampaignStatus;
use App\Shared\ValueObjects\MetricValue;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakesProviderResponses;
use Tests\TestCase;

/**
 * Campaign-linked metric refresh (cost plan follow-up to rec 1): content
 * matched to a producing seeding campaign keeps its metrics live via
 * direct post URLs after aging out of the roster refresh window — and
 * nothing else is ever fetched.
 */
class CampaignRefreshTest extends TestCase
{
    use FakesProviderResponses;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeProviderCredentials();
        config(['qds.ingestion.refresh_window_days' => 14]);
    }

    /** @return array{0: ContentItem, 1: PlatformAccount} */
    private function linkedOldItem(SeedingCampaignStatus $status, string $externalId, string $permalink): array
    {
        $creator = Creator::factory()->create();
        $account = PlatformAccount::factory()->create([
            'creator_id' => $creator->id,
            'platform' => Platform::Instagram,
        ]);

        $item = ContentItem::factory()->create([
            'platform_account_id' => $account->id,
            'platform' => Platform::Instagram,
            'external_id' => $externalId,
            'permalink' => $permalink,
            'published_at' => CarbonImmutable::now()->subDays(60),
        ]);

        $campaign = SeedingCampaign::factory()->create(['status' => $status]);
        $shipment = Shipment::factory()->create([
            'seeding_campaign_id' => $campaign->id,
            'creator_id' => $creator->id,
        ]);
        $shipment->resultingContent()->attach($item->id);

        return [$item, $account];
    }

    private function runJob(): void
    {
        (new RefreshCampaignContentJob('corr-refresh'))->handle(
            app(InstagramDirectUrlAdapter::class),
            app(ProviderCallRecorder::class),
            app(ContentItemPersister::class),
            app(ProviderCircuitBreaker::class),
        );
    }

    public function test_linked_aged_out_content_gets_its_metrics_refreshed(): void
    {
        [$item] = $this->linkedOldItem(
            SeedingCampaignStatus::Active,
            'post-refresh-1',
            'https://www.instagram.com/p/REFRESH1/',
        );

        $this->fakeApifyActor('apify~instagram-scraper', [[
            'id' => 'post-refresh-1',
            'type' => 'Image',
            'caption' => 'refreshed',
            'url' => 'https://www.instagram.com/p/REFRESH1/',
            'timestamp' => '2026-05-08T10:00:00.000Z',
            'likesCount' => 9999,
            'commentsCount' => 111,
        ]]);

        $this->runJob();

        $item->refresh();

        $likes = collect($item->public_metrics)
            ->first(fn (MetricValue $m): bool => $m->metric === 'likes');

        $this->assertSame(9999.0, $likes?->amount);

        $call = ProviderCall::query()->where('operation', 'content.refresh')->sole();
        $this->assertSame('SRC-apify-instagram-scraper', $call->source);
    }

    public function test_unlinked_or_in_window_content_is_never_fetched(): void
    {
        // In-window item linked to an active campaign: normal cycles cover it.
        [$inWindow] = $this->linkedOldItem(
            SeedingCampaignStatus::Active,
            'post-in-window',
            'https://www.instagram.com/p/INWINDOW/',
        );
        $inWindow->update(['published_at' => CarbonImmutable::now()->subDays(3)]);

        // Old item with a permalink but no campaign link at all.
        ContentItem::factory()->create([
            'platform' => Platform::Instagram,
            'external_id' => 'post-unlinked',
            'permalink' => 'https://www.instagram.com/p/UNLINKED/',
            'published_at' => CarbonImmutable::now()->subDays(60),
        ]);

        Http::fake();

        $this->runJob();

        Http::assertNothingSent();
    }

    public function test_draft_and_long_settled_campaigns_do_not_qualify(): void
    {
        $this->linkedOldItem(
            SeedingCampaignStatus::Draft,
            'post-draft',
            'https://www.instagram.com/p/DRAFT/',
        );

        [$settled] = $this->linkedOldItem(
            SeedingCampaignStatus::Completed,
            'post-settled',
            'https://www.instagram.com/p/SETTLED/',
        );
        // Completed long past the settle window (updated_at is the proxy).
        SeedingCampaign::query()
            ->whereHas('shipments', fn ($q) => $q->whereHas(
                'resultingContent',
                fn ($c) => $c->whereKey($settled->id),
            ))
            ->update(['updated_at' => CarbonImmutable::now()->subDays(90)]);

        Http::fake();

        $this->runJob();

        Http::assertNothingSent();
    }

    public function test_the_command_is_gated_on_its_config_flags(): void
    {
        config(['qds.ingestion.enabled' => true, 'qds.ingestion.campaign_refresh.enabled' => false]);

        Queue::fake();

        $this->artisan('qds:refresh-campaign-content')->assertSuccessful();

        Queue::assertNotPushed(RefreshCampaignContentJob::class);

        config(['qds.ingestion.campaign_refresh.enabled' => true]);

        $this->artisan('qds:refresh-campaign-content')->assertSuccessful();

        Queue::assertPushed(RefreshCampaignContentJob::class, 1);
    }
}
