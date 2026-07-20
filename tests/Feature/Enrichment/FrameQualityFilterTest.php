<?php

namespace Tests\Feature\Enrichment;

use App\Platform\Enrichment\VisualMatch\Frames\FrameQualityFilter;
use Tests\TestCase;

/**
 * Local, free garbage detection before any embedding spend (spec §8 step 2).
 * Deliberately loose defaults: this filter skips garbage, it does not
 * judge photography.
 */
class FrameQualityFilterTest extends TestCase
{
    private function filter(): FrameQualityFilter
    {
        return new FrameQualityFilter;
    }

    /** Solid-color grayscale JPEG (mean ≈ the level, stddev ≈ 0). */
    private function solidJpeg(int $level): string
    {
        $image = imagecreatetruecolor(64, 64);
        imagefilledrectangle($image, 0, 0, 63, 63, (int) imagecolorallocate($image, $level, $level, $level));

        return $this->jpegBytes($image);
    }

    /** Left-to-right grayscale ramp: mid mean, high stddev — a "normal" frame. */
    private function rampJpeg(): string
    {
        $image = imagecreatetruecolor(64, 64);

        for ($x = 0; $x < 64; $x++) {
            $level = min(255, $x * 4);
            imagefilledrectangle($image, $x, 0, $x, 63, (int) imagecolorallocate($image, $level, $level, $level));
        }

        return $this->jpegBytes($image);
    }

    private function jpegBytes(\GdImage $image): string
    {
        ob_start();
        imagejpeg($image, null, 90);
        imagedestroy($image);

        return (string) ob_get_clean();
    }

    public function test_quality_filter_and_dedup_config_defaults_are_registered(): void
    {
        $this->assertTrue((bool) config('qds.enrichment.visual_match.quality_filter.enabled'));
        $this->assertSame(10, config('qds.enrichment.visual_match.quality_filter.min_mean_luminance'));
        $this->assertSame(245, config('qds.enrichment.visual_match.quality_filter.max_mean_luminance'));
        $this->assertSame(4.0, config('qds.enrichment.visual_match.quality_filter.min_luminance_stddev'));
        $this->assertTrue((bool) config('qds.enrichment.visual_match.dedup.enabled'));
        $this->assertSame(6, config('qds.enrichment.visual_match.dedup.hamming_threshold'));
    }

    public function test_a_normal_frame_passes(): void
    {
        $this->assertNull($this->filter()->rejectionReason($this->rampJpeg()));
    }

    public function test_undecodable_bytes_are_rejected(): void
    {
        $this->assertSame('undecodable', $this->filter()->rejectionReason('definitely-not-an-image'));
    }

    public function test_extreme_darkness_is_rejected(): void
    {
        $this->assertSame('too-dark', $this->filter()->rejectionReason($this->solidJpeg(3)));
    }

    public function test_overexposure_is_rejected(): void
    {
        $this->assertSame('too-bright', $this->filter()->rejectionReason($this->solidJpeg(252)));
    }

    public function test_flat_frames_are_rejected(): void
    {
        $this->assertSame('flat', $this->filter()->rejectionReason($this->solidJpeg(128)));
    }

    public function test_disabling_the_filter_keeps_only_garbage_detection(): void
    {
        config(['qds.enrichment.visual_match.quality_filter.enabled' => false]);

        // Photographic judgments are off …
        $this->assertNull($this->filter()->rejectionReason($this->solidJpeg(3)));
        // … but undecodable garbage is a FORMAT concern (§5) and still reported.
        $this->assertSame('undecodable', $this->filter()->rejectionReason('definitely-not-an-image'));
    }
}
