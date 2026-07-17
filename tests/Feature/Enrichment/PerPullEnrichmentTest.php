<?php

namespace Tests\Feature\Enrichment;

use App\Modules\CRM\Models\PlatformAccount;
use App\Modules\Monitoring\Models\ContentItem;
use App\Modules\Monitoring\Models\Story;
use App\Platform\Enrichment\Jobs\EnrichContentItemJob;
use App\Platform\Enrichment\Jobs\EnrichStoryJob;
use App\Platform\Enrichment\PerPullEnrichmentDispatcher;
use App\Platform\Ingestion\Jobs\ArchiveStoryMediaJob;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * ADR-0023: the AI check follows the data pull. New content enriches right
 * away (inside the eligibility window, kill switch respected); stories
 * enrich only after their media archive lands; the sweep stays the backstop.
 */
class PerPullEnrichmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        config(['qds.enrichment.enabled' => true]);
    }

    private function content(int $publishedDaysAgo): ContentItem
    {
        return ContentItem::factory()->create([
            'published_at' => CarbonImmutable::now()->subDays($publishedDaysAgo),
        ]);
    }

    public function test_created_content_inside_the_window_is_dispatched(): void
    {
        $fresh = $this->content(publishedDaysAgo: 2);

        app(PerPullEnrichmentDispatcher::class)->dispatchForContent([$fresh->id], 'corr-1');

        Queue::assertPushed(EnrichContentItemJob::class, fn (EnrichContentItemJob $job): bool => $job->contentItemId === $fresh->id && $job->correlationId === 'corr-1');
    }

    public function test_old_backfilled_content_outside_the_window_is_not_dispatched(): void
    {
        $old = $this->content(publishedDaysAgo: 90); // > content_window_days (30)

        app(PerPullEnrichmentDispatcher::class)->dispatchForContent([$old->id], 'corr-2');

        Queue::assertNotPushed(EnrichContentItemJob::class);
    }

    public function test_kill_switch_off_dispatches_nothing(): void
    {
        config(['qds.enrichment.enabled' => false]);
        $fresh = $this->content(publishedDaysAgo: 2);

        $dispatcher = app(PerPullEnrichmentDispatcher::class);
        $dispatcher->dispatchForContent([$fresh->id], 'corr-3');
        $dispatcher->dispatchForStory(1, 'corr-3');

        Queue::assertNotPushed(EnrichContentItemJob::class);
        Queue::assertNotPushed(EnrichStoryJob::class);
    }

    public function test_story_archive_success_dispatches_story_enrichment(): void
    {
        Storage::fake('media');
        Http::fake(['*' => Http::response('BYTES', 200, ['Content-Type' => 'image/jpeg'])]);

        $account = PlatformAccount::factory()->create();
        $story = Story::factory()->create([
            'platform_account_id' => $account->id,
            'media_url' => null,
        ]);

        (new ArchiveStoryMediaJob($story->id, 'https://cdn.example/story.jpg', 'corr-4'))->handle();

        $this->assertNotNull($story->refresh()->media_url);
        Queue::assertPushed(EnrichStoryJob::class, fn (EnrichStoryJob $job): bool => $job->storyId === $story->id && $job->correlationId === 'corr-4');
    }

    public function test_failed_story_archive_dispatches_no_enrichment(): void
    {
        Storage::fake('media');
        Http::fake(['*' => Http::response('gone', 404)]);

        $account = PlatformAccount::factory()->create();
        $story = Story::factory()->create([
            'platform_account_id' => $account->id,
            'media_url' => null,
        ]);

        (new ArchiveStoryMediaJob($story->id, 'https://cdn.example/story.jpg', 'corr-5'))->handle();

        Queue::assertNotPushed(EnrichStoryJob::class);
    }
}
