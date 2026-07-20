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

    public function test_gemini_embeddings_source_is_registered(): void
    {
        $this->assertSame('SRC-google-gemini-embeddings', SourceRegistry::GOOGLE_GEMINI_EMBEDDINGS);
        $this->assertTrue(SourceRegistry::isRegistered(SourceRegistry::GOOGLE_GEMINI_EMBEDDINGS));
    }

    public function test_gemini_vlm_source_is_registered(): void
    {
        $this->assertSame('SRC-google-gemini-vlm', SourceRegistry::GOOGLE_GEMINI_VLM);
        $this->assertTrue(SourceRegistry::isRegistered(SourceRegistry::GOOGLE_GEMINI_VLM));
    }
}
