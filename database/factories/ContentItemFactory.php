<?php

namespace Database\Factories;

use App\Modules\CRM\Models\PlatformAccount;
use App\Modules\Monitoring\Models\ContentItem;
use App\Platform\Ingestion\SourceRegistry;
use App\Shared\Enums\ContentType;
use App\Shared\Enums\MetricTier;
use App\Shared\Enums\Platform;
use App\Shared\ValueObjects\MetricValue;
use App\Shared\ValueObjects\Provenance;
use Carbon\CarbonImmutable;
use Database\Factories\Concerns\ResolvesTenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Synthetic data only (DP-005). Content is externally sourced, so every
 * fixture carries a Provenance envelope naming a registered SRC-* id
 * (DP-002 / DP-006). content_type never takes a story value — stories are
 * ENT-Story (rule F8).
 *
 * @extends Factory<ContentItem>
 */
class ContentItemFactory extends Factory
{
    use ResolvesTenant;

    protected $model = ContentItem::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => fn () => $this->defaultTenantId(),
            'platform_account_id' => PlatformAccount::factory(),
            'platform' => Platform::Instagram,
            'content_type' => fake()->randomElement([ContentType::ImagePost, ContentType::Carousel, ContentType::Reel]),
            'external_id' => fake()->unique()->bothify('post-########'),
            'caption' => fake()->sentence(),
            'media_urls' => [fake()->url()],
            'published_at' => now()->subHours(fake()->numberBetween(1, 72)),
            'public_metrics' => [
                new MetricValue(fake()->numberBetween(100, 100_000), MetricTier::Public),
                new MetricValue(fake()->numberBetween(10, 5_000), MetricTier::Public),
            ],
            'provenance' => new Provenance(
                SourceRegistry::APIFY_INSTAGRAM_SCRAPER,
                CarbonImmutable::now(),
                'test-fixture-v1',
            ),
        ];
    }
}
