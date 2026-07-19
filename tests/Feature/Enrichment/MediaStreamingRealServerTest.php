<?php

namespace Tests\Feature\Enrichment;

use App\Platform\Enrichment\Media\StreamStatus;
use App\Platform\Enrichment\Recognition\MediaFetcher;
use Illuminate\Process\InvokedProcess;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

class MediaStreamingRealServerTest extends TestCase
{
    private ?InvokedProcess $server = null;

    private string $docroot;

    private int $port;

    /** Loopback-permitting fetcher: ONLY ipIsPublic is overridden. */
    private function loopbackFetcher(): MediaFetcher
    {
        return new class extends MediaFetcher
        {
            protected function ipIsPublic(string $ip): bool
            {
                return $ip === '127.0.0.1' || parent::ipIsPublic($ip);
            }
        };
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->docroot = sys_get_temp_dir().'/qds-media-server-'.getmypid();
        @mkdir($this->docroot, 0777, true);
        file_put_contents($this->docroot.'/ok.mp4', str_repeat('A', 1024));
        file_put_contents($this->docroot.'/big.mp4', str_repeat('B', 5 * 1024 * 1024));
        file_put_contents($this->docroot.'/router.php', <<<'PHP'
<?php
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($uri === '/redirect') {
    header('Location: /ok.mp4', true, 302);
    exit;
}
$file = __DIR__.$uri;
if (is_file($file)) {
    header('Content-Type: video/mp4');
    header('Content-Length: '.filesize($file));
    readfile($file);
    exit;
}
http_response_code(404);
PHP);

        // Find a free loopback port, then boot php -S on it.
        $probe = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($probe === false) {
            $this->markTestSkipped('Cannot bind a loopback socket.');
        }
        $this->port = (int) explode(':', (string) stream_socket_get_name($probe, false))[1];
        fclose($probe);

        $this->server = Process::start([PHP_BINARY, '-S', "127.0.0.1:{$this->port}", '-t', $this->docroot, $this->docroot.'/router.php']);

        // Wait (bounded) until the server accepts connections.
        $deadline = microtime(true) + 5.0;
        while (microtime(true) < $deadline) {
            $conn = @fsockopen('127.0.0.1', $this->port, $errno, $errstr, 0.2);
            if (is_resource($conn)) {
                fclose($conn);

                return;
            }
            usleep(100_000);
        }

        $this->markTestSkipped('php -S did not come up on loopback.');
    }

    protected function tearDown(): void
    {
        $this->server?->stop();
        @unlink($this->docroot.'/ok.mp4');
        @unlink($this->docroot.'/big.mp4');
        @unlink($this->docroot.'/router.php');
        @rmdir($this->docroot);
        parent::tearDown();
    }

    private function sink(): string
    {
        return (string) tempnam(sys_get_temp_dir(), 'qds-real-sink-');
    }

    public function test_real_transfer_streams_exact_bytes_to_the_sink(): void
    {
        $sink = $this->sink();

        $result = $this->loopbackFetcher()->streamToFile("http://127.0.0.1:{$this->port}/ok.mp4", $sink, 1_000_000);

        $this->assertSame(StreamStatus::Ok, $result->status);
        $this->assertSame(str_repeat('A', 1024), file_get_contents($sink));
        @unlink($sink);
    }

    public function test_redirect_leaves_only_the_final_hops_bytes_in_the_sink(): void
    {
        $sink = $this->sink();

        $result = $this->loopbackFetcher()->streamToFile("http://127.0.0.1:{$this->port}/redirect", $sink, 1_000_000);

        $this->assertSame(StreamStatus::Ok, $result->status);
        $this->assertSame(str_repeat('A', 1024), file_get_contents($sink));
        @unlink($sink);
    }

    public function test_over_cap_transfer_reports_too_large(): void
    {
        $sink = $this->sink();

        $result = $this->loopbackFetcher()->streamToFile("http://127.0.0.1:{$this->port}/big.mp4", $sink, 100_000);

        $this->assertSame(StreamStatus::TooLarge, $result->status);
        @unlink($sink);
    }
}
