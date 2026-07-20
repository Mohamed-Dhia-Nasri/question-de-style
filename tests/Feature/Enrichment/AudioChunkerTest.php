<?php

namespace Tests\Feature\Enrichment;

use App\Platform\Enrichment\Recognition\AudioChunker;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

/**
 * Real-ffmpeg integration for the sub-project D chunked-audio derivation:
 * synthetic multi-second fixtures are rendered by the same ffmpeg binary
 * the chunker uses, so nothing external is downloaded (AudioExtractorTest
 * pattern). Skipped entirely on hosts without ffmpeg.
 */
class AudioChunkerTest extends TestCase
{
    private AudioChunker $chunker;

    /** @var list<string> */
    private array $cleanupPaths = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->chunker = new AudioChunker;

        if (! $this->chunker->isAvailable()) {
            $this->markTestSkipped('ffmpeg is not installed on this host.');
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->cleanupPaths as $path) {
            @unlink($path);
        }

        parent::tearDown();
    }

    public function test_chunk_count_covers_the_capped_duration(): void
    {
        config([
            'qds.enrichment.speech.chunk_seconds' => 55,
            'qds.enrichment.speech.max_minutes' => 10,
        ]);

        $this->assertSame(0, $this->chunker->chunkCount(0.0));
        $this->assertSame(1, $this->chunker->chunkCount(30.0));
        $this->assertSame(1, $this->chunker->chunkCount(55.0));
        $this->assertSame(2, $this->chunker->chunkCount(56.0));
        $this->assertSame(11, $this->chunker->chunkCount(600.0));
        // Duration beyond the minutes budget is capped, never chunked further.
        $this->assertSame(11, $this->chunker->chunkCount(3_600.0));
    }

    public function test_extracts_sequential_flac_chunks_and_null_beyond_the_end(): void
    {
        config([
            'qds.enrichment.speech.chunk_seconds' => 1,
            'qds.enrichment.speech.max_minutes' => 10,
        ]);
        $videoPath = $this->makeVideo(withAudio: true, seconds: 3);

        foreach ([0, 1, 2] as $index) {
            $chunk = $this->chunker->extractChunk($videoPath, $index);

            $this->assertNotNull($chunk, "chunk {$index} should extract");
            // Self-describing container — GoogleSpeechV2Client sends
            // autoDecodingConfig, so Google reads the format from this header.
            $this->assertStringStartsWith('fLaC', $chunk);
        }

        // Seek past EOF: ffmpeg encodes nothing and exits non-zero → null,
        // never a fabricated chunk.
        $this->assertNull($this->chunker->extractChunk($videoPath, 30));
    }

    public function test_the_offset_cap_never_extracts_beyond_the_minutes_budget(): void
    {
        config([
            'qds.enrichment.speech.chunk_seconds' => 55,
            'qds.enrichment.speech.max_minutes' => 1,
        ]);
        $videoPath = $this->makeVideo(withAudio: true, seconds: 3);

        // Offset 110 s >= the 60 s budget — refused before ffmpeg even runs.
        $this->assertNull($this->chunker->extractChunk($videoPath, 2));
        // Negative indexes are refused outright.
        $this->assertNull($this->chunker->extractChunk($videoPath, -1));
    }

    public function test_chunk_extraction_is_deterministic(): void
    {
        config([
            'qds.enrichment.speech.chunk_seconds' => 1,
            'qds.enrichment.speech.max_minutes' => 10,
        ]);
        $videoPath = $this->makeVideo(withAudio: true, seconds: 3);

        $first = $this->chunker->extractChunk($videoPath, 1);
        $second = $this->chunker->extractChunk($videoPath, 1);

        $this->assertNotNull($first);
        $this->assertSame($first, $second);
    }

    public function test_a_muted_video_yields_null_never_a_fabricated_track(): void
    {
        config([
            'qds.enrichment.speech.chunk_seconds' => 1,
            'qds.enrichment.speech.max_minutes' => 10,
        ]);

        $this->assertNull($this->chunker->extractChunk($this->makeVideo(withAudio: false, seconds: 3), 0));
    }

    public function test_undecodable_or_missing_input_yields_null(): void
    {
        config([
            'qds.enrichment.speech.chunk_seconds' => 1,
            'qds.enrichment.speech.max_minutes' => 10,
        ]);

        $garbage = (string) tempnam(sys_get_temp_dir(), 'qds-not-a-video-');
        $this->cleanupPaths[] = $garbage;
        file_put_contents($garbage, 'not-a-video');

        $this->assertNull($this->chunker->extractChunk($garbage, 0));
        $this->assertNull($this->chunker->extractChunk('/nonexistent/video.mp4', 0));
    }

    /** Synthetic MP4 (test pattern ± sine tone), rendered locally; returns its PATH. */
    private function makeVideo(bool $withAudio, int $seconds): string
    {
        $out = tempnam(sys_get_temp_dir(), 'qds-video-fixture-');
        $this->assertNotFalse($out);
        $this->cleanupPaths[] = $out;

        $args = [
            (string) config('qds.enrichment.audio.ffmpeg_path', 'ffmpeg'),
            '-nostdin', '-v', 'error',
            '-f', 'lavfi', '-i', "testsrc=duration={$seconds}:size=64x64:rate=10",
        ];

        if ($withAudio) {
            array_push($args, '-f', 'lavfi', '-i', "sine=frequency=440:duration={$seconds}");
        }

        array_push($args, '-pix_fmt', 'yuv420p', '-shortest', '-f', 'mp4', '-y', $out);

        Process::timeout(30)->run($args)->throw();

        return $out;
    }
}
