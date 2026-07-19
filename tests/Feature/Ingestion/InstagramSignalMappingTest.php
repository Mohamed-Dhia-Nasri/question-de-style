<?php

namespace Tests\Feature\Ingestion;

use App\Platform\Ingestion\Http\ApifyClient;
use App\Platform\Ingestion\Providers\Instagram\InstagramPostAdapter;
use App\Platform\Ingestion\DTO\ContentData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InstagramSignalMappingTest extends TestCase
{
    use RefreshDatabase;

    public function test_post_adapter_maps_mentions_when_present(): void
    {
        $item = [
            'id' => 'abc', 'type' => 'Image', 'caption' => 'love it',
            'displayUrl' => 'https://cdn/x.jpg', 'url' => 'https://ig/p/abc',
            'timestamp' => '2026-07-01T00:00:00Z',
            'mentions' => ['glossier', 'sephora'],
        ];

        $data = \App\Platform\Ingestion\Normalization\SignalExtract::mentions($item);

        $this->assertSame(['glossier', 'sephora'], $data);
    }

    public function test_signal_extract_is_fail_closed_on_missing_keys(): void
    {
        $this->assertSame([], \App\Platform\Ingestion\Normalization\SignalExtract::mentions([]));
        $this->assertNull(\App\Platform\Ingestion\Normalization\SignalExtract::brandedContentLabel([]));
    }
}
