<?php

namespace Tests\Unit\Enrichment;

use App\Platform\Enrichment\Hashtags\ExtractedHashtag;
use App\Platform\Enrichment\Hashtags\HashtagExtractor;
use App\Platform\Enrichment\Hashtags\HashtagNormalizer;
use PHPUnit\Framework\TestCase;

/**
 * Pure extraction/normalization guarantees: Unicode-aware tag recognition,
 * NFKC + case-fold normalization for matching, verbatim preservation of the
 * ORIGINAL form (the normalized form exists only for matching), and
 * duplicate collapsing with occurrence counts. Synthetic data only (DP-005).
 */
class HashtagExtractorTest extends TestCase
{
    private HashtagExtractor $extractor;

    protected function setUp(): void
    {
        $this->extractor = new HashtagExtractor;
    }

    /** @return array<string, ExtractedHashtag> keyed by normalized form */
    private function byNormalized(?string $caption, array $metadataTags = []): array
    {
        $result = [];

        foreach ($this->extractor->extract($caption, $metadataTags) as $hashtag) {
            $result[$hashtag->normalized] = $hashtag;
        }

        return $result;
    }

    public function test_unicode_hashtags_in_any_script_are_extracted(): void
    {
        $extracted = $this->byNormalized('Un #café à la #мода avec du #スキンケア');

        $this->assertCount(3, $extracted);
        $this->assertArrayHasKey('café', $extracted);
        $this->assertArrayHasKey('мода', $extracted);
        $this->assertArrayHasKey('スキンケア', $extracted);

        // Originals are preserved verbatim, including the '#'.
        $this->assertSame('#café', $extracted['café']->original);
        $this->assertSame('#мода', $extracted['мода']->original);
        $this->assertSame('#スキンケア', $extracted['スキンケア']->original);
    }

    public function test_case_variants_collapse_into_one_normalized_entry(): void
    {
        $extracted = $this->extractor->extract('Try #GlowSerum today, #glowserum forever, #GLOWSERUM!');

        $this->assertCount(1, $extracted);
        $this->assertSame('glowserum', $extracted[0]->normalized);
        $this->assertSame(3, $extracted[0]->occurrences);

        // The FIRST verbatim original wins, '#' included.
        $this->assertSame('#GlowSerum', $extracted[0]->original);
    }

    public function test_full_width_forms_fold_to_the_same_normalized_entry(): void
    {
        // NFKC folds full-width compatibility forms before the case fold.
        $this->assertSame('glowserum', HashtagNormalizer::normalize('#ＧｌｏｗＳｅｒｕｍ'));

        $extracted = $this->extractor->extract('#ＧｌｏｗＳｅｒｕｍ meets #glowserum');

        $this->assertCount(1, $extracted);
        $this->assertSame('glowserum', $extracted[0]->normalized);
        $this->assertSame(2, $extracted[0]->occurrences);
        $this->assertSame('#ＧｌｏｗＳｅｒｕｍ', $extracted[0]->original);
    }

    public function test_combining_marks_compose_under_nfkc(): void
    {
        // Precomposed U+00E9 vs 'e' + combining acute U+0301: one entry.
        $extracted = $this->extractor->extract("#café et #cafe\u{0301}");

        $this->assertCount(1, $extracted);
        $this->assertSame('café', $extracted[0]->normalized);
        $this->assertSame(2, $extracted[0]->occurrences);
    }

    public function test_normalizer_strips_hash_and_case_folds(): void
    {
        $this->assertSame('glowserum', HashtagNormalizer::normalize('#GlowSerum'));
        $this->assertSame('glowserum', HashtagNormalizer::normalize('GlowSerum'));
        $this->assertSame('café', HashtagNormalizer::normalize('#CAFÉ'));
    }

    public function test_duplicates_keep_first_position_and_count_occurrences(): void
    {
        $extracted = $this->extractor->extract('Try #GlowSerum now — later #glowserum again');

        $this->assertCount(1, $extracted);
        $this->assertSame(4, $extracted[0]->firstPosition);
        $this->assertSame(2, $extracted[0]->occurrences);
    }

    public function test_metadata_tags_without_hash_are_accepted(): void
    {
        $extracted = $this->byNormalized(null, ['glowserum', '#Sponsored']);

        $this->assertCount(2, $extracted);
        $this->assertSame('#glowserum', $extracted['glowserum']->original);
        $this->assertSame('#Sponsored', $extracted['sponsored']->original);
    }

    public function test_metadata_and_caption_forms_deduplicate(): void
    {
        $extracted = $this->extractor->extract('Loving #GlowSerum', ['glowserum']);

        $this->assertCount(1, $extracted);
        $this->assertSame(2, $extracted[0]->occurrences);

        // Caption came first, so its verbatim original is kept.
        $this->assertSame('#GlowSerum', $extracted[0]->original);
        $this->assertSame(7, $extracted[0]->firstPosition);
    }

    public function test_non_string_metadata_is_skipped(): void
    {
        $extracted = $this->extractor->extract(null, [42, 3.14, null, true, ['nested'], '#valid']);

        $this->assertCount(1, $extracted);
        $this->assertSame('valid', $extracted[0]->normalized);
    }

    public function test_blank_or_hash_only_metadata_is_skipped(): void
    {
        $this->assertSame([], $this->extractor->extract(null, ['#', '   ', "#\t ", '']));
    }

    public function test_empty_or_missing_caption_yields_no_hashtags(): void
    {
        // Absent input yields an empty result — never a fabricated value.
        $this->assertSame([], $this->extractor->extract(''));
        $this->assertSame([], $this->extractor->extract(null));
        $this->assertSame([], $this->extractor->extract('no tags in this caption at all'));
    }
}
