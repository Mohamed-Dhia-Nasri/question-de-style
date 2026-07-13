<?php

namespace App\Platform\Enrichment\Recognition;

use Illuminate\Support\Facades\Process;
use Throwable;

/**
 * Audio derivation for SPOKEN_BRAND: pulls a Speech-to-Text-ready audio
 * track out of downloaded video bytes with local ffmpeg — media never
 * leaves the platform for transcoding (DP-005); the derived audio goes to
 * SRC-google-speech-to-text inline, like every other recognition payload.
 *
 * The output contract is dictated by GoogleSpeechClient's frozen
 * speech:recognize call (the SYNC endpoint, sent without an explicit
 * encoding config):
 *  - FLAC, because the container must be self-describing for Google to
 *    read the format from the header;
 *  - mono 16 kHz, the recommended STT profile;
 *  - capped at qds.enrichment.audio.max_seconds (sync recognize accepts
 *    at most 60s) and MAX_AUDIO_BYTES (10 MB request limit after base64).
 *
 * Scraped video bytes are UNTRUSTED: ffmpeg runs with a fixed argument
 * vector (never a shell string), -nostdin, and a hard timeout. Any
 * failure — no audio track, undecodable media, oversized output — yields
 * null: SPOKEN_BRAND stays unavailable and the skip is reported upstream,
 * never fabricated.
 */
class AudioExtractor
{
    /** Sync speech:recognize caps requests at 10 MB; base64 inflates by 4/3. */
    private const MAX_AUDIO_BYTES = 7_000_000;

    private const FFMPEG_TIMEOUT_SECONDS = 60;

    /** speech:recognize (sync) rejects audio longer than one minute. */
    private const MAX_SECONDS_CEILING = 60;

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

    /** FLAC bytes of the first ≤60s of audio, or null when none can be derived. */
    public function extract(string $videoBytes): ?string
    {
        if ($videoBytes === '') {
            return null;
        }

        $in = tempnam(sys_get_temp_dir(), 'qds-audio-in-');
        $out = tempnam(sys_get_temp_dir(), 'qds-audio-out-');

        if ($in === false || $out === false) {
            // One tempnam may have created a real (0-byte) file while the
            // other failed — clean up the survivor so it does not leak, since
            // the try/finally below is never entered on this path.
            if (is_string($in)) {
                @unlink($in);
            }
            if (is_string($out)) {
                @unlink($out);
            }

            return null;
        }

        try {
            if (file_put_contents($in, $videoBytes) === false) {
                return null;
            }

            $result = Process::timeout(self::FFMPEG_TIMEOUT_SECONDS)->run([
                $this->ffmpegPath(),
                '-nostdin',
                '-v', 'error',
                '-i', $in,
                '-vn', // drop the video stream
                '-ac', '1', // mono
                '-ar', '16000', // 16 kHz
                '-t', (string) $this->maxSeconds(),
                '-f', 'flac',
                '-y', $out,
            ]);

            if (! $result->successful()) {
                // Includes the muted-video case: no audio stream → ffmpeg
                // refuses to write an empty FLAC and exits non-zero.
                return null;
            }

            $audio = file_get_contents($out);

            return is_string($audio) && $audio !== '' && strlen($audio) <= self::MAX_AUDIO_BYTES
                ? $audio
                : null;
        } catch (Throwable) {
            return null;
        } finally {
            @unlink($in);
            @unlink($out);
        }
    }

    private function ffmpegPath(): string
    {
        return (string) config('qds.enrichment.audio.ffmpeg_path', 'ffmpeg');
    }

    private function maxSeconds(): int
    {
        return min(self::MAX_SECONDS_CEILING, max(1, (int) config('qds.enrichment.audio.max_seconds')));
    }
}
