<?php

namespace Tests\Unit\Ingestion;

use App\Platform\Ingestion\SourceRegistry;
use PHPUnit\Framework\TestCase;

class SourceRegistryTest extends TestCase
{
    public function test_youtube_transcript_source_is_registered(): void
    {
        $this->assertSame('SRC-apify-youtube-transcript', SourceRegistry::APIFY_YOUTUBE_TRANSCRIPT);
        $this->assertTrue(SourceRegistry::isRegistered(SourceRegistry::APIFY_YOUTUBE_TRANSCRIPT));
    }
}
