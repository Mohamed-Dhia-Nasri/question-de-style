<?php

namespace App\Platform\Enrichment\VisualMatch\Frames;

/**
 * Local, free frame triage before any embedding spend (spec §8 step 2):
 * drops frames that cannot produce a reliable match — undecodable bytes,
 * extreme darkness/overexposure (mean luminance on a downsampled grayscale
 * copy) or near-zero luminance variance (flat/blank/heavily-blurred proxy).
 * Deliberately loose defaults: this filter skips garbage, it does not
 * judge photography.
 *
 * Gating: the quality_filter.enabled switch turns off only the
 * PHOTOGRAPHIC judgments. Undecodable content is a FORMAT concern
 * (spec §5 — frames_skipped_format covers "unknown or undecodable") and
 * is always reported; FramePreparation counts it accordingly.
 */
final class FrameQualityFilter
{
    public const REASON_UNDECODABLE = 'undecodable';

    public const REASON_TOO_DARK = 'too-dark';

    public const REASON_TOO_BRIGHT = 'too-bright';

    public const REASON_FLAT = 'flat';

    private const SAMPLE_SIZE = 32;

    /** Null when the frame passes; otherwise the rejection reason. */
    public function rejectionReason(string $bytes): ?string
    {
        $image = @imagecreatefromstring($bytes);

        if ($image === false) {
            return self::REASON_UNDECODABLE;
        }

        if (! (bool) config('qds.enrichment.visual_match.quality_filter.enabled')) {
            imagedestroy($image);

            return null;
        }

        [$mean, $stddev] = $this->luminanceStats($image);
        imagedestroy($image);

        return match (true) {
            $mean < (int) config('qds.enrichment.visual_match.quality_filter.min_mean_luminance') => self::REASON_TOO_DARK,
            $mean > (int) config('qds.enrichment.visual_match.quality_filter.max_mean_luminance') => self::REASON_TOO_BRIGHT,
            $stddev < (float) config('qds.enrichment.visual_match.quality_filter.min_luminance_stddev') => self::REASON_FLAT,
            default => null,
        };
    }

    /** @return array{0: float, 1: float} mean + stddev of downsampled grayscale luminance (0–255) */
    private function luminanceStats(\GdImage $image): array
    {
        $sample = imagecreatetruecolor(self::SAMPLE_SIZE, self::SAMPLE_SIZE);
        imagecopyresampled($sample, $image, 0, 0, 0, 0, self::SAMPLE_SIZE, self::SAMPLE_SIZE, imagesx($image), imagesy($image));

        $values = [];

        for ($y = 0; $y < self::SAMPLE_SIZE; $y++) {
            for ($x = 0; $x < self::SAMPLE_SIZE; $x++) {
                $rgb = imagecolorat($sample, $x, $y);
                $values[] = 0.299 * (($rgb >> 16) & 0xFF) + 0.587 * (($rgb >> 8) & 0xFF) + 0.114 * ($rgb & 0xFF);
            }
        }

        imagedestroy($sample);

        $mean = array_sum($values) / count($values);
        $variance = 0.0;

        foreach ($values as $value) {
            $variance += ($value - $mean) ** 2;
        }

        return [$mean, sqrt($variance / count($values))];
    }
}
