<?php

namespace Tests\Feature\Enrichment;

use App\Platform\Enrichment\Media\StreamStatus;
use App\Platform\Enrichment\Recognition\MediaFetcher;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MediaStreamingTest extends TestCase
{
    private function sink(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'qds-test-sink-');
        $this->assertIsString($path);

        return $path;
    }

    public function test_ok_download_writes_the_sink_and_reports_content_type(): void
    {
        // Literal public IP: the SSRF guard passes without DNS resolution.
        Http::fake(['93.184.216.34/*' => Http::response('VIDEOBYTES', 200, ['Content-Type' => 'video/mp4'])]);
        $sink = $this->sink();

        $result = app(MediaFetcher::class)->streamToFile('https://93.184.216.34/clip.mp4', $sink, 1_000_000);

        $this->assertSame(StreamStatus::Ok, $result->status);
        $this->assertSame('video/mp4', $result->contentType);
        $this->assertSame('VIDEOBYTES', file_get_contents($sink));
        @unlink($sink);
    }

    public function test_gone_status_for_expired_source_urls(): void
    {
        Http::fake(['93.184.216.34/*' => Http::response('', 403)]);
        $sink = $this->sink();

        $this->assertSame(StreamStatus::Gone, app(MediaFetcher::class)->streamToFile('https://93.184.216.34/old.mp4', $sink, 1_000_000)->status);
        @unlink($sink);
    }

    public function test_content_length_over_cap_is_too_large(): void
    {
        Http::fake(['93.184.216.34/*' => Http::response('x', 200, ['Content-Type' => 'video/mp4', 'Content-Length' => '9999999'])]);
        $sink = $this->sink();

        $this->assertSame(StreamStatus::TooLarge, app(MediaFetcher::class)->streamToFile('https://93.184.216.34/big.mp4', $sink, 1_000)->status);
        @unlink($sink);
    }

    public function test_oversized_body_is_too_large_even_without_content_length(): void
    {
        Http::fake(['93.184.216.34/*' => Http::response(str_repeat('x', 2_000), 200, ['Content-Type' => 'video/mp4'])]);
        $sink = $this->sink();

        $this->assertSame(StreamStatus::TooLarge, app(MediaFetcher::class)->streamToFile('https://93.184.216.34/big.mp4', $sink, 1_000)->status);
        @unlink($sink);
    }

    public function test_html_watch_page_is_refused(): void
    {
        Http::fake(['93.184.216.34/*' => Http::response('<html></html>', 200, ['Content-Type' => 'text/html'])]);
        $sink = $this->sink();

        $this->assertSame(StreamStatus::Failed, app(MediaFetcher::class)->streamToFile('https://93.184.216.34/watch', $sink, 1_000_000)->status);
        @unlink($sink);
    }

    public function test_private_host_is_refused_by_the_ssrf_guard(): void
    {
        Http::fake();
        $sink = $this->sink();

        $this->assertSame(StreamStatus::Failed, app(MediaFetcher::class)->streamToFile('https://169.254.169.254/latest', $sink, 1_000)->status);
        Http::assertNothingSent();
        @unlink($sink);
    }
}
