<?php

namespace Tests\Unit\Enrichment;

use App\Platform\Enrichment\VisualMatch\Matching\ThresholdResolver;
use App\Shared\Enums\SectorLabel;
use Tests\TestCase;

/**
 * Per-category band thresholds (spec §8/§12). Values are deliberate
 * PLACEHOLDERS — calibration is sub-project E's mandate.
 */
class ThresholdResolverTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('qds.enrichment.visual_match.thresholds', [
            'default' => ['auto' => 0.65, 'review' => 0.55, 'margin' => 0.05],
            'BEAUTY' => ['auto' => 0.70],
            'FOOD_BEVERAGE' => ['auto' => 0.70],
        ]);
    }

    public function test_null_category_resolves_the_default_band(): void
    {
        $thresholds = (new ThresholdResolver)->for(null);

        $this->assertSame(0.65, $thresholds->auto);
        $this->assertSame(0.55, $thresholds->review);
        $this->assertSame(0.05, $thresholds->margin);
    }

    public function test_category_override_merges_over_the_default(): void
    {
        $thresholds = (new ThresholdResolver)->for(SectorLabel::Beauty);

        // Only auto is overridden; review/margin inherit from default.
        $this->assertSame(0.70, $thresholds->auto);
        $this->assertSame(0.55, $thresholds->review);
        $this->assertSame(0.05, $thresholds->margin);
    }

    public function test_category_without_override_falls_back_to_default(): void
    {
        $thresholds = (new ThresholdResolver)->for(SectorLabel::Tech);

        $this->assertSame(0.65, $thresholds->auto);
        $this->assertSame(0.55, $thresholds->review);
        $this->assertSame(0.05, $thresholds->margin);
    }

    public function test_missing_config_resolves_the_spec_placeholders(): void
    {
        config()->set('qds.enrichment.visual_match.thresholds', null);

        $thresholds = (new ThresholdResolver)->for(SectorLabel::Beauty);

        $this->assertSame(0.65, $thresholds->auto);
        $this->assertSame(0.55, $thresholds->review);
        $this->assertSame(0.05, $thresholds->margin);
    }
}
