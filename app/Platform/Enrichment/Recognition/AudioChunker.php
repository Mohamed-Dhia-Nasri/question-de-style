<?php

namespace App\Platform\Enrichment\Recognition;

use Illuminate\Support\Facades\Process;
use Throwable;

/**
 * Segmented audio derivation for the multilingual speech upgrade
 * (sub-project D, ADR-0030): chunk i of a video is the mono 16 kHz FLAC
 * of [i·chunk_seconds, (i+1)·chunk_seconds). Chunk 0 IS today's
 * first-window pass (stays synchronous in-pipeline); chunks 1..N are the
 * persisted extension TranscribeExtendedAudioJob works through.
 * chunk_seconds defaults to 55 — a deliberate safety margin under the
 * sync recognize limits (60 s / 10 MB); the doctrine-compliant long-audio
 * path is chunked ≤60 s sync recognize (BatchRecognize is gs://-only).
 *
 * Same posture as AudioExtractor (scraped video bytes are UNTRUSTED):
 * fixed argument vector — never a shell string — plus -nostdin, a hard
 * timeout, and a ≤7 MB output guard. Any failure — no audio track, seek
 * past EOF, undecodable media, oversized output — yields null: the chunk
 * is skipped and reported upstream, never fabricated. A belt-and-braces
 * offset cap refuses any chunk starting beyond the max_minutes budget,
 * whatever the caller asks (budget doctrine fail-safe).
 */
class AudioChunker
{
    /** Sync recognize caps requests at 10 MB; base64 inflates by 4/3. */
    private const MAX_AUDIO_BYTES = 7_000_000;

    private const FFMPEG_TIMEOUT_SECONDS = 60;

    /** The sync recognize duration ceiling — chunk_seconds is clamped to it. */
    private const CHUNK_SECONDS_CEILING = 60;

    private ?bool $available = null;

    /** True when the configured ffmpeg binary answers -version. */
    public function isAvailable(): bool
    {
        if ($this->available !== null) {
            return $this->available;
        }

        try {
            return $this->available = Process::timeout(10)
                ->run([$this->ffmpegPath(), '-version'])
                ->successful();
        } catch (Throwable) {
            return $this->available = false;
        }
    }

    /**
     * Total chunks (INCLUDING chunk 0) covering the first
     * min(duration, max_minutes·60) seconds of the media.
     */
    public function chunkCount(float $durationSeconds): int
    {
        $capped = min(max(0.0, $durationSeconds), $this->maxMinutes() * 60.0);

        return (int) ceil($capped / $this->chunkSeconds());
    }

    /**
     * FLAC bytes of chunk $chunkIndex (0-based), or null when the index is
     * negative, the offset falls outside the minutes budget, ffmpeg fails,
     * the output is empty, or it exceeds the inline-payload guard.
     */
    public function extractChunk(string $videoPath, int $chunkIndex): ?string
    {
        if ($chunkIndex < 0 || ! is_file($videoPath) || (int) @filesize($videoPath) === 0) {
            return null;
        }

        $chunkSeconds = $this->chunkSeconds();
        $offsetSeconds = $chunkIndex * $chunkSeconds;

        // Budget-doctrine fail-safe: never read past max_minutes.
        if ($offsetSeconds >= $this->maxMinutes() * 60) {
            return null;
        }

        $out = tempnam(sys_get_temp_dir(), 'qds-audio-chunk-');

        if ($out === false) {
            return null;
        }

        try {
            // -ss BEFORE -i: input-side seek — fast on long media and
            // sample-accurate for audio decode on modern ffmpeg.
            $result = Process::timeout(self::FFMPEG_TIMEOUT_SECONDS)->run([
                $this->ffmpegPath(),
                '-nostdin',
                '-v', 'error',
                '-ss', (string) $offsetSeconds,
                '-i', $videoPath,
                '-vn', // drop the video stream
                '-ac', '1', // mono — Speech v2 bills per channel
                '-ar', '16000', // 16 kHz
                '-t', (string) $chunkSeconds,
                '-f', 'flac',
                '-y', $out,
            ]);

            if (! $result->successful()) {
                // Includes the muted-video case (no audio stream to map) and,
                // on older ffmpeg, a seek past EOF: it encodes nothing and
                // exits non-zero. Modern ffmpeg exits zero there — see the
                // sample-count guard below.
                return null;
            }

            $audio = file_get_contents($out);

            if (! is_string($audio) || $audio === '' || strlen($audio) > self::MAX_AUDIO_BYTES) {
                return null;
            }

            // A seek past EOF leaves modern ffmpeg (≥8.0) writing a valid but
            // sample-less FLAC and exiting zero, rather than failing — that is
            // a fabricated chunk. Reject any output with no audio frames.
            return $this->flacHasAudioSamples($audio) ? $audio : null;
        } catch (Throwable) {
            return null;
        } finally {
            @unlink($out);
        }
    }

    /**
     * True when the FLAC STREAMINFO reports a non-zero total sample count.
     * A seek past the end of the media (or any decode that produced no
     * frames) leaves a header-only container whose STREAMINFO total-samples
     * field is 0; that is never a real chunk. The count is the low 36 bits
     * of the big-endian 8 bytes at STREAMINFO body offset 10 — i.e. file
     * offset 18, past the 4-byte "fLaC" magic and the 4-byte metadata-block
     * header.
     */
    private function flacHasAudioSamples(string $flac): bool
    {
        if (strlen($flac) < 26 || substr($flac, 0, 4) !== 'fLaC') {
            return false;
        }

        $streamInfo = unpack('J', substr($flac, 18, 8));

        if ($streamInfo === false) {
            return false;
        }

        return ($streamInfo[1] & 0xFFFFFFFFF) !== 0;
    }

    private function ffmpegPath(): string
    {
        return (string) config('qds.enrichment.audio.ffmpeg_path', 'ffmpeg');
    }

    private function chunkSeconds(): int
    {
        return min(self::CHUNK_SECONDS_CEILING, max(1, (int) config('qds.enrichment.speech.chunk_seconds')));
    }

    private function maxMinutes(): int
    {
        return max(1, (int) config('qds.enrichment.speech.max_minutes'));
    }
}
