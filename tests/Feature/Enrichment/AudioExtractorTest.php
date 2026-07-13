<?php

namespace Tests\Feature\Enrichment;

use App\Platform\Enrichment\Recognition\AudioExtractor;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

/**
 * Real-ffmpeg integration for the SPOKEN_BRAND audio derivation stage:
 * synthetic one-second fixtures are rendered by the same ffmpeg binary the
 * extractor uses, so nothing external is downloaded. Skipped entirely on
 * hosts without ffmpeg — RecognitionPipelineTest covers the
 * 'speech:ffmpeg-unavailable' path that applies there.
 */
class AudioExtractorTest extends TestCase
{
    private AudioExtractor $extractor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->extractor = new AudioExtractor;

        if (! $this->extractor->isAvailable()) {
            $this->markTestSkipped('ffmpeg is not installed on this host.');
        }
    }

    public function test_extracts_a_flac_track_from_a_video_with_audio(): void
    {
        $audio = $this->extractor->extract($this->makeVideo(withAudio: true));

        $this->assertNotNull($audio);
        // Self-describing container — GoogleSpeechClient sends no encoding
        // config, so Google must read the format from this header.
        $this->assertStringStartsWith('fLaC', $audio);
    }

    public function test_a_muted_video_yields_null_never_a_fabricated_track(): void
    {
        $this->assertNull($this->extractor->extract($this->makeVideo(withAudio: false)));
    }

    public function test_undecodable_bytes_yield_null(): void
    {
        $this->assertNull($this->extractor->extract('not-a-video'));
        $this->assertNull($this->extractor->extract(''));
    }

    /** One-second synthetic MP4 (test pattern ± sine tone), rendered locally. */
    private function makeVideo(bool $withAudio): string
    {
        $out = tempnam(sys_get_temp_dir(), 'qds-video-fixture-');
        $this->assertNotFalse($out);

        $args = [
            (string) config('qds.enrichment.audio.ffmpeg_path', 'ffmpeg'),
            '-nostdin', '-v', 'error',
            '-f', 'lavfi', '-i', 'testsrc=duration=1:size=64x64:rate=10',
        ];

        if ($withAudio) {
            array_push($args, '-f', 'lavfi', '-i', 'sine=frequency=440:duration=1');
        }

        array_push($args, '-pix_fmt', 'yuv420p', '-shortest', '-f', 'mp4', '-y', $out);

        Process::timeout(30)->run($args)->throw();

        $bytes = file_get_contents($out);
        @unlink($out);

        $this->assertIsString($bytes);
        $this->assertNotSame('', $bytes);

        return $bytes;
    }
}
