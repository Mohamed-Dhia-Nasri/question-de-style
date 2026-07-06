<?php

namespace Tests\Feature\Ingestion;

use App\Modules\CRM\Models\PlatformAccount;
use App\Modules\Monitoring\Models\ContentItem;
use App\Modules\Monitoring\Models\Story;
use App\Platform\Ingestion\Jobs\IngestStoriesJob;
use App\Shared\Enums\Platform;
use App\Shared\Enums\RoleName;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\Support\FakesProviderResponses;
use Tests\TestCase;

/**
 * Story monitoring & archival before expiry (REQ-M1-004, AC-M1-005):
 * stories become ENT-Story rows (never ContentItems — rule F8), media is
 * downloaded into PRIVATE object storage, archival is idempotent, and
 * access happens only through short-lived signed URLs issued to
 * authorized users.
 */
class StoryArchivalTest extends TestCase
{
    use FakesProviderResponses;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeProviderCredentials();
        Storage::fake('media');
    }

    private function fakeStoryActorAndCdn(): void
    {
        Http::fake([
            'api.apify.com/v2/acts/'.config('services.apify.actors.instagram_story').'/*' => Http::response($this->fixture('instagram-stories')),
            'cdn.example/story1.mp4' => Http::response('FAKE-VIDEO-BYTES', 200, ['Content-Type' => 'video/mp4']),
            'cdn.example/story2.jpg' => Http::response('FAKE-IMAGE-BYTES', 200, ['Content-Type' => 'image/jpeg']),
        ]);
    }

    public function test_stories_are_archived_privately_with_provenance_before_expiry(): void
    {
        $account = PlatformAccount::factory()->create([
            'platform' => Platform::Instagram,
            'handle' => 'styleicon.de',
        ]);

        $this->fakeStoryActorAndCdn();

        // QUEUE_CONNECTION=sync → the archival jobs run inline.
        IngestStoriesJob::dispatchSync($account->id, null, 'corr-story');

        $this->assertSame(2, Story::query()->count());
        $this->assertSame(0, ContentItem::query()->count()); // never a ContentItem (rule F8)

        $story = Story::query()->where('external_id', 'story-77001')->firstOrFail();

        // Media landed in PRIVATE storage; media_url is the storage path,
        // not the provider CDN URL.
        $this->assertNotNull($story->media_url);
        $this->assertStringNotContainsString('cdn.example', $story->media_url);
        Storage::disk('media')->assertExists($story->media_url);
        $this->assertSame('FAKE-VIDEO-BYTES', Storage::disk('media')->get($story->media_url));

        // Provenance + capture timestamp (AC-M1-005).
        $this->assertSame('SRC-apify-instagram-story-details', $story->provenance->source);
        $this->assertNotNull($story->captured_at);
        $this->assertNotNull($story->expires_at);
    }

    public function test_story_reingestion_is_idempotent_and_does_not_rearchive(): void
    {
        $account = PlatformAccount::factory()->create([
            'platform' => Platform::Instagram,
            'handle' => 'styleicon.de',
        ]);

        $this->fakeStoryActorAndCdn();
        IngestStoriesJob::dispatchSync($account->id, null, 'corr-1');

        $firstPath = Story::query()->where('external_id', 'story-77001')->firstOrFail()->media_url;

        Http::clearResolvedInstances();
        $this->fakeStoryActorAndCdn();
        IngestStoriesJob::dispatchSync($account->id, null, 'corr-2');

        $this->assertSame(2, Story::query()->count()); // no duplicates
        $this->assertSame($firstPath, Story::query()->where('external_id', 'story-77001')->firstOrFail()->media_url);

        // Second run must not re-download archived media.
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'cdn.example/story1.mp4'));
    }

    public function test_media_access_requires_authorization_and_a_valid_signature(): void
    {
        $this->seedRoles();

        $story = Story::factory()->create(['media_url' => 'stories/instagram/1/story-77001.mp4']);
        Storage::disk('media')->put($story->media_url, 'FAKE-VIDEO-BYTES');

        // Unauthenticated → redirected to login.
        $this->getJson(route('monitoring.stories.media-url', $story))->assertUnauthorized();

        // CLIENT_VIEWER has no monitoring.view → forbidden.
        $this->actingAs($this->makeUser(RoleName::ClientViewer))
            ->get(route('monitoring.stories.media-url', $story))
            ->assertForbidden();

        // Analyst gets a short-lived signed URL...
        $response = $this->actingAs($this->makeUser(RoleName::Analyst))
            ->get(route('monitoring.stories.media-url', $story))
            ->assertOk()
            ->json();

        $this->assertArrayHasKey('url', $response);
        $this->assertArrayHasKey('expires_at', $response);

        // ...which serves the private bytes...
        $this->get($response['url'])->assertOk();

        // ...while an unsigned URL is rejected.
        $this->get(URL::route('monitoring.stories.media', $story))->assertForbidden();
    }

    public function test_expired_media_at_the_platform_is_not_retried_forever(): void
    {
        $account = PlatformAccount::factory()->create([
            'platform' => Platform::Instagram,
            'handle' => 'styleicon.de',
        ]);

        Http::fake([
            'api.apify.com/v2/acts/'.config('services.apify.actors.instagram_story').'/*' => Http::response($this->fixture('instagram-stories')),
            'cdn.example/*' => Http::response('gone', 404),
        ]);

        IngestStoriesJob::dispatchSync($account->id, null, 'corr-expired');

        $story = Story::query()->where('external_id', 'story-77001')->firstOrFail();
        $this->assertNull($story->media_url); // metadata kept, media unavailable
    }
}
