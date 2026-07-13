<?php

namespace Database\Factories;

use App\Modules\CRM\Models\Creator;
use App\Modules\CRM\Models\PlatformAccount;
use App\Platform\Ingestion\SourceRegistry;
use App\Shared\Enums\MetricTier;
use App\Shared\Enums\Platform;
use App\Shared\ValueObjects\MetricValue;
use App\Shared\ValueObjects\Provenance;
use Carbon\CarbonImmutable;
use Database\Factories\Concerns\ResolvesTenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Synthetic data only (DP-005). Provenance sources come from the closed
 * SRC-* registry — the Provenance constructor rejects anything else (DP-006).
 *
 * @extends Factory<PlatformAccount>
 */
class PlatformAccountFactory extends Factory
{
    use ResolvesTenant;

    protected $model = PlatformAccount::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => fn () => $this->defaultTenantId(),
            'creator_id' => null,
            'platform' => Platform::Instagram,
            'handle' => fake()->unique()->userName(),
            'bio' => fake()->sentence(),
            'external_links' => [fake()->url()],
            'follower_count' => new MetricValue(fake()->numberBetween(1_000, 500_000), MetricTier::Public),
            'provenance' => new Provenance(
                SourceRegistry::APIFY_INSTAGRAM_PROFILE_SCRAPER,
                CarbonImmutable::now(),
                'test-fixture-v1',
            ),
        ];
    }

    public function forCreator(?Creator $creator = null): static
    {
        return $this->state(fn (array $attributes) => [
            'creator_id' => $creator !== null ? $creator->id : Creator::factory(),
        ]);
    }

    public function onPlatform(Platform $platform): static
    {
        return $this->state(fn (array $attributes) => ['platform' => $platform]);
    }
}
