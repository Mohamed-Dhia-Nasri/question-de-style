<?php

namespace Tests\Feature\Enrichment;

use App\Modules\CRM\Models\Brand;
use App\Platform\Enrichment\Recognition\RecognitionNormalizer;
use App\Shared\Enums\RecognitionType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Sub-project D (spec §9): per-chunk SPOKEN_BRAND mining over the v2
 * speech path. Same lexicon gate as speechBatch — free text with no known
 * brand is not a recognition hit — but with a DETERMINISTIC provider
 * label (ordinal + slugged brand): stable across re-transcription, never
 * colliding at 255 chars, never spoken personal content in a
 * review-UI-visible identity field.
 */
class TranscriptChunkNormalizerTest extends TestCase
{
    use RefreshDatabase;

    private function normalizer(): RecognitionNormalizer
    {
        return app(RecognitionNormalizer::class);
    }

    private function brand(): Brand
    {
        return Brand::factory()->create([
            'name' => 'Maison Lumière',
            'aliases' => ['lumiere', '@maisonlumiere'],
        ]);
    }

    public function test_a_chunk_transcript_with_a_known_brand_yields_a_deterministic_chunk_labeled_candidate(): void
    {
        $this->brand();

        $batch = $this->normalizer()->transcriptChunkBatch('and now the new lumiere palette in english', 3, 0.88);

        $this->assertCount(1, $batch->items);
        $candidate = $batch->items[0];
        $this->assertSame(RecognitionType::SpokenBrand, $candidate->type);
        $this->assertSame('Maison Lumière', $candidate->detectedBrand);
        // Deterministic identity (spec §9): ordinal + slugged brand — never
        // the truncated transcript the v1 path uses.
        $this->assertSame('speech-chunk:3:maison-lumiere', $candidate->providerLabel);
        $this->assertSame('and now the new lumiere palette in english', $candidate->detectedText);
        $this->assertSame(0.88, $candidate->score);
        $this->assertContains('spoken-brand-transcript-match:Maison Lumière', $candidate->signals);
        $this->assertContains('provider-confidence:0.88', $candidate->signals);
        $this->assertSame('google-speech-to-text-v2', $batch->response->sourceVersion);
    }

    public function test_the_label_is_stable_across_re_transcription_wording_changes(): void
    {
        $this->brand();

        $first = $this->normalizer()->transcriptChunkBatch('heute die lumiere palette', 2, 0.9);
        $second = $this->normalizer()->transcriptChunkBatch('heute DIE Lumiere Palette!!', 2, 0.4);

        $this->assertSame($first->items[0]->providerLabel, $second->items[0]->providerLabel);
        $this->assertSame('speech-chunk:2:maison-lumiere', $second->items[0]->providerLabel);
    }

    public function test_free_text_without_a_known_brand_is_not_a_candidate(): void
    {
        $this->brand();

        $batch = $this->normalizer()->transcriptChunkBatch('danke fürs zuschauen bis morgen', 1, 0.95);

        $this->assertSame([], $batch->items);
        $this->assertSame([], $batch->rejected);
    }

    public function test_null_score_reports_unavailable_confidence(): void
    {
        $this->brand();

        $batch = $this->normalizer()->transcriptChunkBatch('unboxing the lumiere set', 0, null);

        $this->assertContains('provider-confidence:unavailable', $batch->items[0]->signals);
    }

    public function test_detected_text_is_truncated_but_the_label_is_not_derived_from_it(): void
    {
        $this->brand();
        $text = 'lumiere '.str_repeat('a', 3000);

        $batch = $this->normalizer()->transcriptChunkBatch($text, 7, null);

        $this->assertSame(2000, mb_strlen((string) $batch->items[0]->detectedText));
        $this->assertSame('speech-chunk:7:maison-lumiere', $batch->items[0]->providerLabel);
    }
}
