<?php

namespace Tests\Unit\Enrichment;

use App\Platform\Enrichment\TextSignals\MentionExtractor;
use Tests\TestCase;

class MentionExtractorTest extends TestCase
{
    public function test_extracts_distinct_handles(): void
    {
        $out = (new MentionExtractor)->extract('thanks @glossier and @Sephora.official — @glossier again');

        $this->assertSame(['glossier', 'sephora.official'], $out);
    }

    public function test_null_caption_yields_empty(): void
    {
        $this->assertSame([], (new MentionExtractor)->extract(null));
    }
}
