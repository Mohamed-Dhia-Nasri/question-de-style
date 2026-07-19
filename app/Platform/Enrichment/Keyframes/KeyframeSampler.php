<?php

namespace App\Platform\Enrichment\Keyframes;

use Illuminate\Support\Facades\Process;
use Throwable;

/**
 * Deterministic even-interval keyframe extraction (sub-project B): given a
 * downloaded video file, derive N = clamp(ceil(duration/interval), min, max)
 * JPEG frames at the midpoint of each of N equal spans — identical input
 * bytes + config always yield identical frames (repo determinism doctrine;
 * scene-change selection is a documented future mode, ADR-0028).
 *
 * Mirrors AudioExtractor's untrusted-input hardening: fixed argument
 * vector, -nostdin, hard timeouts; any failure yields null (fail-closed,
 * never fabricated), and partial output is discarded.
 */
class KeyframeSampler
{
    private const FFMPEG_TIMEOUT_SECONDS = 120;

    private const FFPROBE_TIMEOUT_SECONDS = 30;

    private ?bool $available = null;

    /** True when both configured binaries answer -version. */
    public function isAvailable(): bool
    {
        if ($this->available !== null) {
            return $this->available;
        }

        try {
            return $this->available = Process::timeout(10)->run([$this->ffmpegPath(), '-version'])->successful()
                && Process::timeout(10)->run([$this->ffprobePath(), '-version'])->successful();
        } catch (Throwable) {
            return $this->available = false;
        }
    }

    /** @return list<SampledFrame>|null null when nothing can be derived */
    public function sample(string $videoPath): ?array
    {
        $duration = $this->probeDurationSeconds($videoPath);

        if ($duration === null || $duration <= 0.0) {
            return null;
        }

        $interval = max(1, (int) config('qds.enrichment.keyframes.interval_seconds'));
        $min = max(1, (int) config('qds.enrichment.keyframes.min_frames'));
        $max = max($min, (int) config('qds.enrichment.keyframes.max_frames'));
        $count = (int) min($max, max($min, (int) ceil($duration / $interval)));

        $maxWidth = max(16, (int) config('qds.enrichment.keyframes.max_width'));
        $quality = min(31, max(2, (int) config('qds.enrichment.keyframes.jpeg_quality')));

        $frames = [];

        try {
            for ($i = 0; $i < $count; $i++) {
                // Midpoint of span i — deterministic, never the (often black)
                // first/last frame.
                $timestamp = $duration * ($i + 0.5) / $count;
                $out = tempnam(sys_get_temp_dir(), 'qds-frame-');

                if ($out === false) {
                    $this->discard($frames);

                    return null;
                }

                $result = Process::timeout(self::FFMPEG_TIMEOUT_SECONDS)->run([
                    $this->ffmpegPath(),
                    '-nostdin',
                    '-v', 'error',
                    '-ss', sprintf('%.3F', $timestamp),
                    '-i', $videoPath,
                    '-frames:v', '1',
                    '-vf', sprintf('scale=min(%d\,iw):-2', $maxWidth),
                    '-q:v', (string) $quality,
                    '-f', 'image2',
                    '-y', $out,
                ]);

                clearstatcache(true, $out);

                if (! $result->successful() || (int) @filesize($out) === 0) {
                    @unlink($out);
                    $this->discard($frames);

                    return null;
                }

                $frames[] = new SampledFrame($out, (int) round($timestamp * 1000), $i);
            }
        } catch (Throwable) {
            $this->discard($frames);

            return null;
        }

        return $frames;
    }

    private function probeDurationSeconds(string $videoPath): ?float
    {
        try {
            $result = Process::timeout(self::FFPROBE_TIMEOUT_SECONDS)->run([
                $this->ffprobePath(),
                '-v', 'error',
                '-show_entries', 'format=duration',
                '-of', 'default=noprint_wrappers=1:nokey=1',
                $videoPath,
            ]);
        } catch (Throwable) {
            return null;
        }

        if (! $result->successful()) {
            return null;
        }

        $output = trim($result->output());

        return is_numeric($output) ? (float) $output : null;
    }

    /** @param list<SampledFrame> $frames */
    private function discard(array $frames): void
    {
        foreach ($frames as $frame) {
            @unlink($frame->tempPath);
        }
    }

    private function ffmpegPath(): string
    {
        return (string) config('qds.enrichment.keyframes.ffmpeg_path', 'ffmpeg');
    }

    private function ffprobePath(): string
    {
        return (string) config('qds.enrichment.keyframes.ffprobe_path', 'ffprobe');
    }
}
