<?php

namespace Database\Factories;

use App\Modules\CRM\Models\Brand;
use App\Modules\CRM\Models\Campaign;
use App\Modules\Monitoring\Models\HashtagList;
use App\Platform\Enrichment\Hashtags\HashtagNormalizer;
use App\Platform\Enrichment\Support\HashtagScope;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Synthetic data only (DP-005).
 *
 * @extends Factory<HashtagList>
 */
class HashtagListFactory extends Factory
{
    protected $model = HashtagList::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $hashtag = '#'.fake()->unique()->lexify('brandtag????');

        return [
            'scope' => HashtagScope::Brand,
            'campaign_id' => null,
            'brand_id' => Brand::factory(),
            'product_label' => null,
            'hashtag' => $hashtag,
            'normalized' => HashtagNormalizer::normalize($hashtag),
            'active' => true,
            'created_by' => null,
        ];
    }

    public function forCampaign(): static
    {
        return $this->state(fn (array $attributes) => [
            'scope' => HashtagScope::Campaign,
            'campaign_id' => Campaign::factory(),
            'brand_id' => null,
        ]);
    }

    public function forProduct(string $productLabel = 'Glow Serum'): static
    {
        return $this->state(fn (array $attributes) => [
            'scope' => HashtagScope::Product,
            'campaign_id' => null,
            'brand_id' => Brand::factory(),
            'product_label' => $productLabel,
        ]);
    }

    public function agency(): static
    {
        return $this->state(fn (array $attributes) => [
            'scope' => HashtagScope::Agency,
            'campaign_id' => null,
            'brand_id' => null,
            'product_label' => null,
        ]);
    }

    public function hashtag(string $hashtag): static
    {
        return $this->state(fn (array $attributes) => [
            'hashtag' => $hashtag,
            'normalized' => HashtagNormalizer::normalize($hashtag),
        ]);
    }
}
