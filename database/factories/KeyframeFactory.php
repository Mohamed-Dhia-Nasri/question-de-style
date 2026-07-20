<?php

namespace Database\Factories;

use App\Modules\Monitoring\Models\ContentItem;
use App\Modules\Monitoring\Models\Keyframe;
use App\Platform\Ingestion\SourceRegistry;
use App\Shared\Enums\KeyframeKind;
use App\Shared\ValueObjects\Provenance;
use Carbon\CarbonImmutable;
use Database\Factories\Concerns\ResolvesTenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;

/**
 * Synthetic keyframe rows (sub-project B's artifact; DP-005). Default owner
 * is a fresh ContentItem; no morph map exists, so owner_type stores the
 * FQCN. Ordinals are faker-unique so multiple frames for ONE owner never
 * collide on the (owner_type, owner_id, ordinal) unique; pass an explicit
 * ordinal (and timestamp_ms) when a test needs deterministic positions.
 *
 * @extends Factory<Keyframe>
 */
class KeyframeFactory extends Factory
{
    use ResolvesTenant;

    protected $model = Keyframe::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $ordinal = fake()->unique()->numberBetween(0, 9_999);

        return [
            'tenant_id' => fn () => $this->defaultTenantId(),
            'owner_type' => ContentItem::class,
            'owner_id' => ContentItem::factory(),
            'ordinal' => $ordinal,
            'timestamp_ms' => $ordinal * 3_000,
            'storage_disk' => 'media',
            'storage_path' => 'tenants/test/keyframes/'.fake()->uuid().'.jpg',
            'width' => 1280,
            'height' => 720,
            'kind' => KeyframeKind::VideoSample,
            'checksum' => hash('sha256', fake()->uuid()),
            'source_checksum' => hash('sha256', fake()->uuid()),
            'provenance' => new Provenance(
                SourceRegistry::APIFY_INSTAGRAM_REEL_SCRAPER,
                CarbonImmutable::now(),
                'keyframes-v1',
            ),
        ];
    }

    /** Attach the frame to an existing owner (ContentItem or Story). */
    public function forOwner(Model $owner): static
    {
        return $this->state(fn (array $attributes) => [
            'owner_type' => $owner->getMorphClass(),
            'owner_id' => $owner->getKey(),
        ]);
    }

    /** A platform poster image — no position in a video timeline. */
    public function thumbnail(): static
    {
        return $this->state(fn (array $attributes) => [
            'kind' => KeyframeKind::Thumbnail,
            'timestamp_ms' => null,
        ]);
    }

    /** A post/carousel/story image — the image IS the frame. */
    public function sourceImage(): static
    {
        return $this->state(fn (array $attributes) => [
            'kind' => KeyframeKind::SourceImage,
            'timestamp_ms' => null,
        ]);
    }
}
