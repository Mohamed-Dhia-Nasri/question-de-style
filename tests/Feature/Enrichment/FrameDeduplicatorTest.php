<?php

namespace Tests\Feature\Enrichment;

use App\Modules\Monitoring\Models\Keyframe;
use App\Platform\Enrichment\VisualMatch\Frames\FrameDeduplicator;
use Tests\TestCase;

/**
 * 64-bit difference-hash near-duplicate grouping (spec §8 step 3): only
 * the earliest representative of a group is embedded; dedup reduces cost,
 * never evidence (represented_frames + span carry the group forward).
 */
class FrameDeduplicatorTest extends TestCase
{
    /** Left-to-right grayscale ramp; +$shift is monotonic → identical dHash. */
    private function rampJpeg(int $shift = 0, bool $reversed = false): string
    {
        $image = imagecreatetruecolor(64, 64);

        for ($x = 0; $x < 64; $x++) {
            $level = min(255, ($reversed ? 63 - $x : $x) * 4 + $shift);
            imagefilledrectangle($image, $x, 0, $x, 63, (int) imagecolorallocate($image, $level, $level, $level));
        }

        ob_start();
        imagejpeg($image, null, 90);
        imagedestroy($image);

        return (string) ob_get_clean();
    }

    /** @return array{keyframe: Keyframe, bytes: string, mimeType: string} */
    private function frame(string $bytes, int $ordinal, ?int $timestampMs): array
    {
        return [
            'keyframe' => new Keyframe(['ordinal' => $ordinal, 'timestamp_ms' => $timestampMs]),
            'bytes' => $bytes,
            'mimeType' => 'image/jpeg',
        ];
    }

    private function deduplicator(): FrameDeduplicator
    {
        return new FrameDeduplicator;
    }

    public function test_identical_frames_group_under_the_earliest_representative(): void
    {
        $bytes = $this->rampJpeg();
        $prepared = $this->deduplicator()->deduplicate([
            $this->frame($bytes, 0, 0),
            $this->frame($bytes, 1, 6000),
            $this->frame($bytes, 2, 12000),
        ]);

        $this->assertCount(1, $prepared);
        $this->assertSame(0, $prepared[0]->keyframe->ordinal);
        $this->assertSame(3, $prepared[0]->representedFrames);
        $this->assertSame(0, $prepared[0]->spanStartMs);
        $this->assertSame(12000, $prepared[0]->spanEndMs);
        $this->assertSame($bytes, $prepared[0]->bytes);
    }

    public function test_a_brightness_shift_stays_within_the_hamming_threshold(): void
    {
        $prepared = $this->deduplicator()->deduplicate([
            $this->frame($this->rampJpeg(), 0, 0),
            $this->frame($this->rampJpeg(2), 1, 6000),
        ]);

        $this->assertCount(1, $prepared);
        $this->assertSame(2, $prepared[0]->representedFrames);
    }

    public function test_distinct_frames_survive_as_their_own_groups(): void
    {
        $prepared = $this->deduplicator()->deduplicate([
            $this->frame($this->rampJpeg(), 0, 0),
            $this->frame($this->rampJpeg(reversed: true), 1, 6000),
        ]);

        $this->assertCount(2, $prepared);
        $this->assertSame([1, 1], [$prepared[0]->representedFrames, $prepared[1]->representedFrames]);
        $this->assertSame(0, $prepared[0]->spanStartMs);
        $this->assertSame(0, $prepared[0]->spanEndMs);
        $this->assertSame(6000, $prepared[1]->spanStartMs);
    }

    public function test_null_timestamp_groups_carry_a_null_span(): void
    {
        $bytes = $this->rampJpeg();
        $prepared = $this->deduplicator()->deduplicate([
            $this->frame($bytes, 0, null),
            $this->frame($bytes, 1, null),
        ]);

        $this->assertCount(1, $prepared);
        $this->assertSame(2, $prepared[0]->representedFrames);
        $this->assertNull($prepared[0]->spanStartMs);
        $this->assertNull($prepared[0]->spanEndMs);
    }

    public function test_disabled_dedup_keeps_every_frame(): void
    {
        config(['qds.enrichment.visual_match.dedup.enabled' => false]);

        $bytes = $this->rampJpeg();
        $prepared = $this->deduplicator()->deduplicate([
            $this->frame($bytes, 0, 0),
            $this->frame($bytes, 1, 6000),
        ]);

        $this->assertCount(2, $prepared);
        $this->assertSame(1, $prepared[0]->representedFrames);
        $this->assertSame(6000, $prepared[1]->spanStartMs);
        $this->assertSame(6000, $prepared[1]->spanEndMs);
    }

    public function test_undecodable_bytes_never_group(): void
    {
        $prepared = $this->deduplicator()->deduplicate([
            $this->frame('GARBAGE-BYTES', 0, 0),
            $this->frame('GARBAGE-BYTES', 1, 6000),
        ]);

        $this->assertCount(2, $prepared);
    }
}
