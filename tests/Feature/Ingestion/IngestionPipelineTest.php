<?php

namespace Tests\Feature\Ingestion;

use App\Modules\CRM\Models\PlatformAccount;
use App\Modules\Monitoring\Models\ContentItem;
use App\Platform\Ingestion\Jobs\IngestContentJob;
use App\Platform\Ingestion\Jobs\IngestProfileJob;
use App\Platform\Ingestion\Models\ProviderCall;
use App\Platform\Ingestion\Models\QuarantinedRecord;
use App\Platform\Ingestion\SourceRegistry;
use App\Platform\Ingestion\Support\CallOutcome;
use App\Shared\Enums\MetricTier;
use App\Shared\Enums\Platform;
use App\Shared\ValueObjects\MetricValue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakesProviderResponses;
use Tests\TestCase;

/**
 * End-to-end ingestion pipeline over fakes: idempotent persistence on the
 * canonical external id (requirement 8), in-place refresh of mutable
 * public metrics (requirement 9), preservation of human corrections
 * (requirement 17), quarantine of invalid records, partial provider
 * failure, and the cross-module profile sync (ownership matrix).
 */
class IngestionPipelineTest extends TestCase
{
    use FakesProviderResponses;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeProviderCredentials();
    }

    private function instagramAccount(): PlatformAccount
    {
        return PlatformAccount::factory()->create([
            'platform' => Platform::Instagram,
            'handle' => 'styleicon.de',
        ]);
    }

    private function fakeInstagramContentActors(?array $posts = null, ?array $reels = null): void
    {
        Http::fake([
            'api.apify.com/v2/acts/apify~instagram-post-scraper/*' => $posts === null
                ? Http::response('actor exploded', 500)
                : Http::response($posts),
            'api.apify.com/v2/acts/apify~instagram-reel-scraper/*' => $reels === null
                ? Http::response('actor exploded', 500)
                : Http::response($reels),
        ]);
    }

    public function test_content_ingestion_is_idempotent_and_refreshes_mutable_metrics(): void
    {
        $account = $this->instagramAccount();

        // First poll returns the fixture; the second poll returns the same
        // records with a changed like count on the first post.
        $updatedPosts = $this->fixture('instagram-posts');
        $updatedPosts[0]['likesCount'] = 9999;

        Http::fake([
            'api.apify.com/v2/acts/apify~instagram-post-scraper/*' => Http::sequence()
                ->push($this->fixture('instagram-posts'))
                ->push($updatedPosts),
            'api.apify.com/v2/acts/apify~instagram-reel-scraper/*' => Http::sequence()
                ->push($this->fixture('instagram-reels'))
                ->push($this->fixture('instagram-reels')),
        ]);

        IngestContentJob::dispatchSync($account->id, null, 'corr-1');

        // 2 valid posts + 2 reels.
        $this->assertSame(4, ContentItem::query()->count());

        IngestContentJob::dispatchSync($account->id, null, 'corr-2');

        // Safe replay: no duplicates (requirement 8; AC-M1-001).
        $this->assertSame(4, ContentItem::query()->count());

        $item = ContentItem::query()
            ->where('external_id', '3412345678901234567')
            ->firstOrFail();

        $likes = collect($item->public_metrics)->first(fn (MetricValue $m) => $m->metric === 'likes');
        $this->assertSame(9999.0, $likes->amount); // metrics updated in place (requirement 9)
        $this->assertSame(MetricTier::Public, $likes->tier);

        // Duplicates were recognized, not re-created.
        $secondRun = ProviderCall::query()
            ->where('correlation_id', 'corr-2')
            ->where('source', SourceRegistry::APIFY_INSTAGRAM_POST_SCRAPER)
            ->firstOrFail();
        $this->assertSame(2, $secondRun->duplicate_count);
        $this->assertSame(0, ContentItem::query()->where('external_id', '3412345678901234567')->count() - 1);
    }

    public function test_human_corrections_survive_subsequent_ingestion_runs(): void
    {
        $account = $this->instagramAccount();

        // Second poll: the provider still returns the old caption + fresh counts.
        $refreshedPosts = $this->fixture('instagram-posts');
        $refreshedPosts[0]['likesCount'] = 6000;

        Http::fake([
            'api.apify.com/v2/acts/apify~instagram-post-scraper/*' => Http::sequence()
                ->push($this->fixture('instagram-posts'))
                ->push($refreshedPosts),
            'api.apify.com/v2/acts/apify~instagram-reel-scraper/*' => Http::response([]),
        ]);

        IngestContentJob::dispatchSync($account->id, null, 'corr-1');

        // An analyst corrects the caption (DP-004); the field is flagged.
        $item = ContentItem::query()->where('external_id', '3412345678901234567')->firstOrFail();
        $item->update(['caption' => 'Korrigierte Beschreibung', 'human_overrides' => ['caption']]);

        IngestContentJob::dispatchSync($account->id, null, 'corr-2');

        $item->refresh();
        $this->assertSame('Korrigierte Beschreibung', $item->caption); // correction preserved (requirement 17)

        $likes = collect($item->public_metrics)->first(fn (MetricValue $m) => $m->metric === 'likes');
        $this->assertSame(6000.0, $likes->amount); // non-corrected fields still refresh
    }

    public function test_invalid_records_are_quarantined_with_sanitized_reason_never_stored(): void
    {
        $account = $this->instagramAccount();

        $this->fakeInstagramContentActors($this->fixture('instagram-posts'), []);
        IngestContentJob::dispatchSync($account->id, null, 'corr-1');

        // 3 malformed post items → quarantine, not content_items.
        $this->assertSame(3, QuarantinedRecord::query()->count());
        $this->assertSame(2, ContentItem::query()->count());

        $quarantined = QuarantinedRecord::query()->where('external_hint', '3412345678901234570')->firstOrFail();
        $this->assertNotSame('', $quarantined->reason);
        $this->assertNotNull($quarantined->expires_at);

        $call = ProviderCall::query()
            ->where('source', SourceRegistry::APIFY_INSTAGRAM_POST_SCRAPER)
            ->firstOrFail();
        $this->assertSame(CallOutcome::Partial, $call->outcome);
        $this->assertSame(3, $call->rejected_count);
        $this->assertSame(3, $call->quarantined_count);
        $this->assertSame(2, $call->accepted_count);
    }

    public function test_partial_provider_failure_keeps_other_providers_and_existing_data_intact(): void
    {
        $account = $this->instagramAccount();

        // Post actor fails hard; reel actor succeeds.
        $this->fakeInstagramContentActors(null, $this->fixture('instagram-reels'));

        IngestContentJob::dispatchSync($account->id, null, 'corr-1');

        // Reels persisted despite the post actor failing.
        $this->assertSame(2, ContentItem::query()->count());

        $outcomes = ProviderCall::query()
            ->where('correlation_id', 'corr-1')
            ->pluck('outcome', 'source');

        $this->assertSame(CallOutcome::Failure, $outcomes[SourceRegistry::APIFY_INSTAGRAM_POST_SCRAPER]);
        $this->assertSame(CallOutcome::Success, $outcomes[SourceRegistry::APIFY_INSTAGRAM_REEL_SCRAPER]);
    }

    public function test_profile_ingestion_updates_public_fields_through_the_crm_contract_only(): void
    {
        $account = PlatformAccount::factory()->create([
            'platform' => Platform::Instagram,
            'handle' => 'styleicon.de',
            'bio' => 'Alte Bio',
            'creator_id' => null,
        ]);

        $this->fakeApifyActor('apify~instagram-profile-scraper', $this->fixture('instagram-profile'));

        IngestProfileJob::dispatchSync($account->id, null, 'corr-1');

        $account->refresh();
        $this->assertSame('Fashion & Beauty aus München', $account->bio);
        $this->assertSame(125000.0, $account->follower_count?->amount);
        $this->assertSame('followers', $account->follower_count?->metric);
        // Identity fields untouched (CRM-owned): handle + creator link.
        $this->assertSame('styleicon.de', $account->handle);
        $this->assertNull($account->creator_id);
        // Fresh provenance names the profile scraper (DP-002).
        $this->assertSame(SourceRegistry::APIFY_INSTAGRAM_PROFILE_SCRAPER, $account->provenance->source);
    }
}
