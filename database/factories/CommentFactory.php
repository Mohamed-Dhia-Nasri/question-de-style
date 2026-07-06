<?php

namespace Database\Factories;

use App\Modules\Monitoring\Models\Comment;
use App\Modules\Monitoring\Models\ContentItem;
use App\Platform\Ingestion\SourceRegistry;
use App\Shared\Enums\MetricTier;
use App\Shared\ValueObjects\MetricValue;
use App\Shared\ValueObjects\Provenance;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Synthetic data only (DP-005). TEST-ONLY fixture: comment collection is
 * DEFERRED in v1 (DEF-005 / ADR-0009) — no production code path writes
 * comments. This factory exists solely to exercise the schema, encryption
 * casts, and relationship contracts.
 *
 * @extends Factory<Comment>
 */
class CommentFactory extends Factory
{
    protected $model = Comment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'content_item_id' => ContentItem::factory(),
            'parent_comment_id' => null,
            'author_handle' => fake()->userName(),
            'text' => fake()->sentence(),
            'like_count' => new MetricValue(fake()->numberBetween(0, 500), MetricTier::Public),
            'posted_at' => now()->subMinutes(fake()->numberBetween(5, 600)),
            'provenance' => new Provenance(
                SourceRegistry::APIFY_INSTAGRAM_COMMENT_SCRAPER,
                CarbonImmutable::now(),
                'test-fixture-v1',
            ),
        ];
    }
}
