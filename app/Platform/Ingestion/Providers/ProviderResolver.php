<?php

namespace App\Platform\Ingestion\Providers;

use App\Platform\Ingestion\Contracts\ContentProvider;
use App\Platform\Ingestion\Contracts\ProfileProvider;
use App\Platform\Ingestion\Contracts\StoryProvider;
use App\Platform\Ingestion\Providers\Instagram\InstagramPostAdapter;
use App\Platform\Ingestion\Providers\Instagram\InstagramProfileAdapter;
use App\Platform\Ingestion\Providers\Instagram\InstagramReelAdapter;
use App\Platform\Ingestion\Providers\Instagram\InstagramStoryAdapter;
use App\Platform\Ingestion\Providers\TikTok\TikTokContentAdapter;
use App\Platform\Ingestion\Providers\TikTok\TikTokProfileAdapter;
use App\Platform\Ingestion\Providers\YouTube\YouTubeContentAdapter;
use App\Platform\Ingestion\Providers\YouTube\YouTubeProfileAdapter;
use App\Shared\Enums\Platform;
use Illuminate\Contracts\Container\Container;

/**
 * Maps each ENUM-Platform to its frozen SRC-* adapters, mirroring the
 * capability→source matrix (docs/40-integrations/00-data-source-matrix.md §2.1).
 * The map is CLOSED — extending it requires a superseding ADR (DP-006), so
 * it is code, not configuration.
 */
class ProviderResolver
{
    public function __construct(private readonly Container $container) {}

    public function profileProvider(Platform $platform): ProfileProvider
    {
        return match ($platform) {
            Platform::Instagram => $this->container->make(InstagramProfileAdapter::class),
            Platform::TikTok => $this->container->make(TikTokProfileAdapter::class),
            Platform::YouTube => $this->container->make(YouTubeProfileAdapter::class),
        };
    }

    /** @return list<ContentProvider> */
    public function contentProviders(Platform $platform): array
    {
        return match ($platform) {
            Platform::Instagram => [
                $this->container->make(InstagramPostAdapter::class),
                $this->container->make(InstagramReelAdapter::class),
            ],
            Platform::TikTok => [$this->container->make(TikTokContentAdapter::class)],
            Platform::YouTube => [$this->container->make(YouTubeContentAdapter::class)],
        };
    }

    /** @return list<StoryProvider> stories exist only on Instagram in v1 */
    public function storyProviders(Platform $platform): array
    {
        return match ($platform) {
            Platform::Instagram => [$this->container->make(InstagramStoryAdapter::class)],
            Platform::TikTok, Platform::YouTube => [],
        };
    }
}
