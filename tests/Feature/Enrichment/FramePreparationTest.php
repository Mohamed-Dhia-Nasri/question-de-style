<?php

namespace Tests\Feature\Enrichment;

use App\Modules\CRM\Models\Creator;
use App\Modules\CRM\Models\PlatformAccount;
use App\Modules\Monitoring\Models\ContentItem;
use App\Modules\Monitoring\Models\Keyframe;
use App\Platform\Enrichment\Keyframes\KeyframeSet;
use App\Platform\Enrichment\VisualMatch\Frames\FramePreparation;
use App\Platform\Enrichment\VisualMatch\Frames\FramePreparationResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Spec §8 steps 1–3 over stored keyframes: format check → quality filter
 * → dedup → budget. Skips are coverage LOSS ("unavailable ≠ false"),
 * counted per kind; the budget cap is a cost guard, not coverage loss.
 */
class FramePreparationTest extends TestCase
{
    use RefreshDatabase;

    private ContentItem $item;

    protected function setUp(): void
    {
        parent::setUp();
        config(['qds.ingestion.media_disk' => 'media']);
        Storage::fake('media');

        $account = PlatformAccount::factory()->for(Creator::factory())->create();
        $this->item = ContentItem::factory()->for($account, 'platformAccount')->create();
    }

    /** Left-to-right grayscale ramp; +$shift monotonic → identical dHash. */
    private function rampJpeg(int $shift = 0, bool $reversed = false): string
    {
        $image = imagecreatetruecolor(64, 64);

        for ($x = 0; $x < 64; $x++) {
            $level = min(255, ($reversed ? 63 - $x : $x) * 4 + $shift);
            imagefilledrectangle($image, $x, 0, $x, 63, (int) imagecolorallocate($image, $level, $level, $level));
        }

        return $this->jpegBytes($image);
    }

    /** Half-dark/half-bright: distinct dHash from both ramps (≈8 vs 0/64 rising bits). */
    private function halfJpeg(): string
    {
        $image = imagecreatetruecolor(64, 64);
        imagefilledrectangle($image, 0, 0, 31, 63, (int) imagecolorallocate($image, 20, 20, 20));
        imagefilledrectangle($image, 32, 0, 63, 63, (int) imagecolorallocate($image, 235, 235, 235));

        return $this->jpegBytes($image);
    }

    private function solidJpeg(int $level): string
    {
        $image = imagecreatetruecolor(64, 64);
        imagefilledrectangle($image, 0, 0, 63, 63, (int) imagecolorallocate($image, $level, $level, $level));

        return $this->jpegBytes($image);
    }

    private function jpegBytes(\GdImage $image): string
    {
        ob_start();
        imagejpeg($image, null, 90);
        imagedestroy($image);

        return (string) ob_get_clean();
    }

    /** $bytes null = row exists but the blob is missing from the disk. */
    private function makeKeyframe(int $ordinal, ?int $timestampMs, string $extension, ?string $bytes): Keyframe
    {
        $path = "tenants/{$this->defaultTenant->id}/keyframes/instagram/{$this->item->platform_account_id}/content-x/{$ordinal}.{$extension}";

        if ($bytes !== null) {
            Storage::disk('media')->put($path, $bytes);
        }

        return Keyframe::factory()->create([
            'owner_type' => $this->item->getMorphClass(),
            'owner_id' => $this->item->id,
            'ordinal' => $ordinal,
            'timestamp_ms' => $timestampMs,
            'storage_disk' => 'media',
            'storage_path' => $path,
        ]);
    }

    /** @param list<Keyframe> $frames */
    private function prepare(array $frames, int $budget = 12): FramePreparationResult
    {
        return app(FramePreparation::class)->prepare(
            new KeyframeSet($frames, $frames === [] ? 'empty' : 'extracted'),
            $budget,
        );
    }

    public function test_supported_distinct_frames_survive_in_ordinal_order(): void
    {
        $frames = [
            $this->makeKeyframe(0, 0, 'jpg', $this->rampJpeg()),
            $this->makeKeyframe(1, 6000, 'jpg', $this->halfJpeg()),
            $this->makeKeyframe(2, 12000, 'jpg', $this->rampJpeg(reversed: true)),
        ];

        $result = $this->prepare($frames);

        $this->assertSame([0, 1, 2], array_map(fn ($f): int => $f->keyframe->ordinal, $result->frames));
        $this->assertSame('image/jpeg', $result->frames[0]->mimeType);
        $this->assertSame(3, $result->framesAvailable);
        $this->assertSame(0, $result->skippedFormat);
        $this->assertSame(0, $result->skippedQuality);
        $this->assertSame(0, $result->deduped);
        $this->assertFalse($result->coverageDegraded());
    }

    public function test_the_frame_budget_caps_prepared_frames(): void
    {
        $frames = [
            $this->makeKeyframe(0, 0, 'jpg', $this->rampJpeg()),
            $this->makeKeyframe(1, 6000, 'jpg', $this->halfJpeg()),
            $this->makeKeyframe(2, 12000, 'jpg', $this->rampJpeg(reversed: true)),
        ];

        $result = $this->prepare($frames, budget: 2);

        $this->assertSame([0, 1], array_map(fn ($f): int => $f->keyframe->ordinal, $result->frames));
        // Truncation is a cost guard, never coverage loss.
        $this->assertSame(3, $result->framesAvailable);
        $this->assertFalse($result->coverageDegraded());
    }

    public function test_unknown_format_and_missing_blob_are_format_loss(): void
    {
        $frames = [
            $this->makeKeyframe(0, 0, 'bin', 'whatever'),
            $this->makeKeyframe(1, 6000, 'jpg', null),
        ];

        $result = $this->prepare($frames);

        $this->assertSame([], $result->frames);
        $this->assertSame(2, $result->skippedFormat);
        $this->assertTrue($result->coverageDegraded());
    }

    public function test_garbage_and_dark_frames_are_skipped_by_kind(): void
    {
        $frames = [
            $this->makeKeyframe(0, 0, 'jpg', 'GARBAGE-NOT-JPEG'),
            $this->makeKeyframe(1, 6000, 'jpg', $this->solidJpeg(3)),
            $this->makeKeyframe(2, 12000, 'jpg', $this->rampJpeg()),
        ];

        $result = $this->prepare($frames);

        $this->assertCount(1, $result->frames);
        $this->assertSame(1, $result->skippedFormat);   // undecodable = format (§5)
        $this->assertSame(1, $result->skippedQuality);  // too-dark = quality
        $this->assertTrue($result->coverageDegraded());
    }

    public function test_near_duplicates_collapse_into_a_represented_span(): void
    {
        $frames = [
            $this->makeKeyframe(0, 0, 'jpg', $this->rampJpeg()),
            $this->makeKeyframe(1, 6000, 'jpg', $this->rampJpeg(2)),
            $this->makeKeyframe(2, 12000, 'jpg', $this->rampJpeg(reversed: true)),
        ];

        $result = $this->prepare($frames);

        $this->assertCount(2, $result->frames);
        $this->assertSame(2, $result->frames[0]->representedFrames);
        $this->assertSame(0, $result->frames[0]->spanStartMs);
        $this->assertSame(6000, $result->frames[0]->spanEndMs);
        $this->assertSame(1, $result->frames[1]->representedFrames);
        $this->assertSame(12000, $result->frames[1]->spanStartMs);
        $this->assertSame(1, $result->deduped);
        $this->assertFalse($result->coverageDegraded());
    }

    public function test_heic_frames_bypass_local_analysis_and_proceed(): void
    {
        // GD cannot decode HEIC, but the MODEL accepts it (spec §5) — the
        // frame must reach embedding unfiltered, not die as "undecodable".
        $result = $this->prepare([$this->makeKeyframe(0, null, 'heic', 'HEIC-BYTES')]);

        $this->assertCount(1, $result->frames);
        $this->assertSame('image/heic', $result->frames[0]->mimeType);
        $this->assertSame('HEIC-BYTES', $result->frames[0]->bytes);
        $this->assertSame(1, $result->frames[0]->representedFrames);
        $this->assertSame(0, $result->skippedFormat);
        $this->assertSame(0, $result->skippedQuality);
        $this->assertFalse($result->coverageDegraded());
    }

    public function test_an_empty_keyframe_set_is_degraded_coverage(): void
    {
        $result = $this->prepare([]);

        $this->assertSame([], $result->frames);
        $this->assertSame(0, $result->framesAvailable);
        $this->assertTrue($result->coverageDegraded());
    }
}
