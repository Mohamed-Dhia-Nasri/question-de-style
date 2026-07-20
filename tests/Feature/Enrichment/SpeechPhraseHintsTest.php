<?php

namespace Tests\Feature\Enrichment;

use App\Modules\CRM\Models\Brand;
use App\Platform\AiBudget\Priority;
use App\Platform\Enrichment\Speech\SpeechPhraseHints;
use App\Platform\Enrichment\VisualMatch\Candidates\Candidate;
use App\Platform\Enrichment\VisualMatch\Candidates\CandidateSet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Sub-project D (spec §9): adaptation phrase hints for Speech-to-Text v2
 * — the tenant's brand names + aliases plus the post's candidate
 * product/brand names, deduplicated, deterministically ordered, capped at
 * qds.enrichment.speech.phrase_cap.
 */
class SpeechPhraseHintsTest extends TestCase
{
    use RefreshDatabase;

    private function candidate(int $productId, string $label, string $brand): Candidate
    {
        return new Candidate(
            productId: $productId,
            productLabel: $label,
            brandName: $brand,
            category: null,
            source: 'shipment',
            shipmentInWindow: true,
            seedingCampaignId: null,
            shipmentAnchorAt: null,
            shipmentAgeDays: null,
            hasEmbeddedPhotos: false,
        );
    }

    public function test_phrases_are_brands_aliases_then_candidate_names_deduped_in_stable_order(): void
    {
        Brand::factory()->create(['name' => 'Maison Lumière', 'aliases' => ['lumiere', '@maisonlumiere']]);
        Brand::factory()->create(['name' => 'Nexon Labs', 'aliases' => []]);

        $set = new CandidateSet([
            $this->candidate(1, 'Nexon Headset', 'Nexon Labs'), // brand dupe collapses
            $this->candidate(2, 'Lumière Palette', 'Maison Lumière'),
        ], Priority::Medium);

        $phrases = app(SpeechPhraseHints::class)->build($set);

        $this->assertSame([
            'Maison Lumière',
            'lumiere',
            '@maisonlumiere',
            'Nexon Labs',
            'Nexon Headset',
            'Lumière Palette',
        ], $phrases);
    }

    public function test_the_phrase_cap_bounds_the_list(): void
    {
        config(['qds.enrichment.speech.phrase_cap' => 2]);
        Brand::factory()->create(['name' => 'Maison Lumière', 'aliases' => ['lumiere', '@maisonlumiere']]);

        $phrases = app(SpeechPhraseHints::class)->build(new CandidateSet([], Priority::Medium));

        $this->assertSame(['Maison Lumière', 'lumiere'], $phrases);
    }

    public function test_blank_entries_are_dropped(): void
    {
        Brand::factory()->create(['name' => 'Maison Lumière', 'aliases' => ['  ', 'lumiere']]);

        $phrases = app(SpeechPhraseHints::class)->build(new CandidateSet([], Priority::Medium));

        $this->assertSame(['Maison Lumière', 'lumiere'], $phrases);
    }
}
