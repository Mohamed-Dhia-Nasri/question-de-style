<?php

namespace Tests\Unit\Enrichment;

use Tests\TestCase;

class TextSignalsConfigTest extends TestCase
{
    public function test_text_signals_config_present(): void
    {
        $this->assertIsBool(config('qds.enrichment.text_signals.enabled'));
        $this->assertContains('dm', config('qds.enrichment.text_signals.short_brand_allowlist'));
        $this->assertContains('gifted', config('qds.enrichment.text_signals.gifting_cues.en'));
        $this->assertContains('pr-paket', config('qds.enrichment.text_signals.gifting_cues.de'));
    }
}
