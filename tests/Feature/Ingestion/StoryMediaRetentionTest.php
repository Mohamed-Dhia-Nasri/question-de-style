<?php

namespace Tests\Feature\Ingestion;

use App\Modules\CRM\Models\PlatformAccount;
use App\Modules\Monitoring\Models\Story;
use App\Platform\Ingestion\DTO\StoryData;
use App\Platform\Ingestion\Persistence\StoryPersister;
use App\Shared\Enums\Platform;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Media storage lifecycle (P4 hardening, DP-005 retention limits): archived
 * story media past qds.ingestion.media_retention_days is deleted from the
 * private media disk; the story row and its metrics history are kept, and
 * pruned media is never re-archived.
 */
class StoryMediaRetentionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('media');
        config(['qds.ingestion.media_retention_days' => 90]);
    }

    private function storyWithArchivedMedia(string $path, \DateTimeInterface $capturedAt): Story
    {
        Storage::disk('media')->put($path, 'FAKE-BYTES');

        return Story::factory()->create([
            'media_url' => $path,
            'captured_at' => $capturedAt,
        ]);
    }

    public function test_media_older_than_retention_is_deleted_and_story_kept(): void
    {
        $old = $this->storyWithArchivedMedia('stories/instagram/1/old.mp4', now()->subDays(120));
        $fresh = $this->storyWithArchivedMedia('stories/instagram/1/fresh.mp4', now()->subDays(5));

        $this->artisan('qds:prune-story-media')
            ->expectsOutputToContain('Pruned archived media for 1 stories')
            ->assertSuccessful();

        Storage::disk('media')->assertMissing('stories/instagram/1/old.mp4');
        Storage::disk('media')->assertExists('stories/instagram/1/fresh.mp4');

        $old->refresh();
        $fresh->refresh();

        // The story row (metrics, provenance) survives — only the file goes.
        $this->assertNull($old->media_url);
        $this->assertNotNull($old->media_pruned_at);
        $this->assertNotNull($old->public_metrics);

        $this->assertSame('stories/instagram/1/fresh.mp4', $fresh->media_url);
        $this->assertNull($fresh->media_pruned_at);
    }

    public function test_pruning_is_disabled_when_retention_is_zero(): void
    {
        config(['qds.ingestion.media_retention_days' => 0]);

        $old = $this->storyWithArchivedMedia('stories/instagram/1/old.mp4', now()->subDays(400));

        $this->artisan('qds:prune-story-media')->assertSuccessful();

        Storage::disk('media')->assertExists('stories/instagram/1/old.mp4');
        $this->assertNotNull($old->refresh()->media_url);
    }

    public function test_pruned_story_is_not_requeued_for_archival(): void
    {
        $account = PlatformAccount::factory()->create(['platform' => Platform::Instagram]);

        $story = Story::factory()->create([
            'platform_account_id' => $account->id,
            'platform' => Platform::Instagram,
            'external_id' => 'story-pruned-1',
            'media_url' => null,
            'media_pruned_at' => now()->subDay(),
            'captured_at' => now()->subDays(100),
        ]);

        $item = new StoryData(
            platform: Platform::Instagram,
            externalId: 'story-pruned-1',
            mediaSourceUrl: 'https://cdn.example/story-pruned-1.mp4',
            expiresAt: null,
            publicMetrics: $story->public_metrics ?? [],
            provenance: $story->provenance,
        );

        $outcome = app(StoryPersister::class)->persist($account, [$item]);

        $this->assertSame([], $outcome['toArchive']);
        $this->assertNull($story->refresh()->media_url);
    }
}
