<?php

namespace Database\Factories;

use App\Modules\Monitoring\Models\ContentHashtag;
use App\Modules\Monitoring\Models\ContentItem;
use App\Platform\Enrichment\Hashtags\HashtagNormalizer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Synthetic data only (DP-005).
 *
 * @extends Factory<ContentHashtag>
 */
class ContentHashtagFactory extends Factory
{
    protected $model = ContentHashtag::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $original = '#'.fake()->unique()->lexify('tag????');

        return [
            'content_item_id' => ContentItem::factory(),
            'original' => $original,
            'normalized' => HashtagNormalizer::normalize($original),
            'first_position' => fake()->numberBetween(0, 200),
            'occurrences' => 1,
            'matches' => [],
            'is_ambiguous' => false,
        ];
    }

    /** An unresolved ambiguous match — belongs in the review queue (DP-004). */
    public function ambiguous(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_ambiguous' => true,
            'resolved_at' => null,
        ]);
    }
}
