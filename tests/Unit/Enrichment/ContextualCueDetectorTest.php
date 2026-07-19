<?php

namespace Tests\Unit\Enrichment;

use App\Platform\Enrichment\TextSignals\ContextualCueDetector;
use Tests\TestCase;

class ContextualCueDetectorTest extends TestCase
{
    public function test_detects_multilingual_cues(): void
    {
        $d = new ContextualCueDetector;

        $this->assertContains('gifting-cue:gifted', $d->detect('this was gifted by the brand'));
        $this->assertContains('gifting-cue:pr-paket', $d->detect('Danke für das PR-Paket'));
        $this->assertContains('gifting-cue:offert', $d->detect('produit offert, merci'));
    }

    public function test_no_cue_yields_empty(): void
    {
        $this->assertSame([], (new ContextualCueDetector)->detect('just a normal caption'));
    }
}
