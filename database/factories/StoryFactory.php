<?php

namespace Database\Factories;

use App\Modules\CRM\Models\PlatformAccount;
use App\Modules\Monitoring\Models\Story;
use App\Platform\Ingestion\SourceRegistry;
use App\Shared\Enums\MetricTier;
use App\Shared\Enums\Platform;
use App\Shared\ValueObjects\MetricValue;
use App\Shared\ValueObjects\Provenance;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Synthetic data only (DP-005). Stories are archived before platform expiry
 * (REQ-M1-004) from SRC-apify-instagram-story-details.
 *
 * @extends Factory<Story>
 */
class StoryFactory extends Factory
{
    protected $model = Story::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'platform_account_id' => PlatformAccount::factory(),
            'platform' => Platform::Instagram,
            'external_id' => fake()->unique()->bothify('story-########'),
            'media_url' => fake()->url(),
            'captured_at' => now()->subHours(2),
            'expires_at' => now()->addHours(22),
            'public_metrics' => [
                new MetricValue(fake()->numberBetween(50, 20_000), MetricTier::Public),
            ],
            'provenance' => new Provenance(
                SourceRegistry::APIFY_INSTAGRAM_STORY_DETAILS,
                CarbonImmutable::now(),
                'test-fixture-v1',
            ),
        ];
    }
}
