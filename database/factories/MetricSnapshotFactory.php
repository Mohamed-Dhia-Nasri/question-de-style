<?php

namespace Database\Factories;

use App\Modules\CRM\Models\PlatformAccount;
use App\Modules\Monitoring\Models\ContentItem;
use App\Modules\Monitoring\Models\MetricSnapshot;
use App\Platform\Ingestion\SourceRegistry;
use App\Shared\Enums\MetricTier;
use App\Shared\ValueObjects\MetricValue;
use App\Shared\ValueObjects\Provenance;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Synthetic data only (DP-005). Default is an account-level snapshot
 * (platform_account_id set); use contentLevel() for a per-content snapshot.
 * Exactly one target is set — the schema enforces it (ADR-0003 series).
 *
 * @extends Factory<MetricSnapshot>
 */
class MetricSnapshotFactory extends Factory
{
    protected $model = MetricSnapshot::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'platform_account_id' => PlatformAccount::factory(),
            'content_item_id' => null,
            'captured_at' => now(),
            'metrics' => [
                new MetricValue(fake()->numberBetween(1_000, 500_000), MetricTier::Public),
                new MetricValue(fake()->numberBetween(100, 20_000), MetricTier::Public),
            ],
            'provenance' => new Provenance(
                SourceRegistry::APIFY_INSTAGRAM_PROFILE_SCRAPER,
                CarbonImmutable::now(),
                'test-fixture-v1',
            ),
        ];
    }

    /** Content-level snapshot: content_item_id set, platform_account_id null. */
    public function contentLevel(): static
    {
        return $this->state(fn (array $attributes) => [
            'platform_account_id' => null,
            'content_item_id' => ContentItem::factory(),
        ]);
    }
}
