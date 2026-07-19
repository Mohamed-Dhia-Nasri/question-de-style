<?php

namespace Tests\Feature\Enrichment;

use App\Platform\Enrichment\Keyframes\KeyframeSampler;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

class KeyframeSamplerTest extends TestCase
{
    private KeyframeSampler $sampler;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sampler = app(KeyframeSampler::class);

        if (! $this->sampler->isAvailable()) {
            $this->markTestSkipped('ffmpeg/ffprobe not installed.');
        }
    }

    /** Render a silent synthetic clip of $seconds and return its path. */
    private function makeVideo(int $seconds): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'qds-test-video-');
        Process::timeout(60)->run([
            (string) config('qds.enrichment.keyframes.ffmpeg_path'),
            '-nostdin', '-v', 'error',
            '-f', 'lavfi', '-i', "testsrc=duration={$seconds}:size=320x240:rate=10",
            '-pix_fmt', 'yuv420p', '-f', 'mp4', '-y', $path,
        ])->throw();

        return $path;
    }

    private function discard(?array $frames, string ...$videos): void
    {
        foreach ($frames ?? [] as $frame) {
            @unlink($frame->tempPath);
        }
        foreach ($videos as $video) {
            @unlink($video);
        }
    }

    public function test_frame_count_is_clamped_and_timestamps_ascend(): void
    {
        config(['qds.enrichment.keyframes.interval_seconds' => 2, 'qds.enrichment.keyframes.min_frames' => 2, 'qds.enrichment.keyframes.max_frames' => 4]);
        $video = $this->makeVideo(20); // ceil(20/2)=10 → clamped to 4

        $frames = $this->sampler->sample($video);

        $this->assertNotNull($frames);
        $this->assertCount(4, $frames);
        $this->assertSame([0, 1, 2, 3], array_map(fn ($f) => $f->ordinal, $frames));
        $timestamps = array_map(fn ($f) => $f->timestampMs, $frames);
        $this->assertSame($timestamps, array_values(array_unique($timestamps)));
        $sorted = $timestamps;
        sort($sorted);
        $this->assertSame($sorted, $timestamps);
        foreach ($frames as $frame) {
            $this->assertFileExists($frame->tempPath);
            $this->assertGreaterThan(0, (int) filesize($frame->tempPath));
        }
        $this->discard($frames, $video);
    }

    public function test_short_video_gets_the_minimum_and_sampling_is_deterministic(): void
    {
        config(['qds.enrichment.keyframes.interval_seconds' => 6, 'qds.enrichment.keyframes.min_frames' => 3, 'qds.enrichment.keyframes.max_frames' => 12]);
        $video = $this->makeVideo(4); // ceil(4/6)=1 → clamped up to 3

        $first = $this->sampler->sample($video);
        $second = $this->sampler->sample($video);

        $this->assertNotNull($first);
        $this->assertCount(3, $first);
        $this->assertSame(
            array_map(fn ($f) => $f->timestampMs, $first),
            array_map(fn ($f) => $f->timestampMs, $second ?? []),
        );
        $this->assertSame(
            array_map(fn ($f) => hash_file('sha256', $f->tempPath), $first),
            array_map(fn ($f) => hash_file('sha256', $f->tempPath), $second ?? []),
        );
        $this->discard($first, $video);
        $this->discard($second);
    }

    public function test_frames_are_downscaled_to_max_width(): void
    {
        config(['qds.enrichment.keyframes.max_width' => 160, 'qds.enrichment.keyframes.min_frames' => 1, 'qds.enrichment.keyframes.max_frames' => 1]);
        $video = $this->makeVideo(3); // source is 320px wide

        $frames = $this->sampler->sample($video);

        $this->assertNotNull($frames);
        [$width] = (array) getimagesize($frames[0]->tempPath);
        $this->assertSame(160, $width);
        $this->discard($frames, $video);
    }

    public function test_undecodable_input_yields_null(): void
    {
        $garbage = (string) tempnam(sys_get_temp_dir(), 'qds-test-garbage-');
        file_put_contents($garbage, 'not a video at all');

        $this->assertNull($this->sampler->sample($garbage));
        @unlink($garbage);
    }
}
