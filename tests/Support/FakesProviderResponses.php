<?php

namespace Tests\Support;

use Illuminate\Support\Facades\Http;

/**
 * Provider fakes and fixtures for ingestion tests. NO live provider is
 * ever called from automated tests, and no production data is used —
 * fixtures are synthetic (DP-005).
 */
trait FakesProviderResponses
{
    /** @return array<array-key, mixed> decoded fixture */
    protected function fixture(string $name): array
    {
        $path = base_path("tests/Fixtures/providers/{$name}.json");

        return json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    }

    /** Configure fake provider credentials so clients pass the config gate. */
    protected function fakeProviderCredentials(): void
    {
        config([
            'services.apify.token' => 'test-apify-token',
            'services.youtube.api_key' => 'test-youtube-key',
        ]);
    }

    /** Fake one Apify actor with a fixture (or explicit items). */
    protected function fakeApifyActor(string $actorId, array $items, int $status = 200): void
    {
        Http::fake([
            "api.apify.com/v2/acts/{$actorId}/run-sync-get-dataset-items*" => Http::response($items, $status),
        ]);
    }

    /** Fake the three YouTube Data API endpoints from fixtures. */
    protected function fakeYouTubeApi(): void
    {
        Http::fake([
            'www.googleapis.com/youtube/v3/channels*' => Http::response($this->fixture('youtube-channel')),
            'www.googleapis.com/youtube/v3/playlistItems*' => Http::response($this->fixture('youtube-playlist')),
            'www.googleapis.com/youtube/v3/videos*' => Http::response($this->fixture('youtube-videos')),
        ]);
    }
}
