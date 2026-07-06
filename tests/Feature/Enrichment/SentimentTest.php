<?php

namespace Tests\Feature\Enrichment;

use App\Modules\Monitoring\Models\Comment;
use App\Modules\Monitoring\Models\ContentItem;
use App\Modules\Monitoring\Models\SentimentAnalysis;
use App\Platform\Enrichment\Contracts\SentimentClassifier;
use App\Platform\Enrichment\EnrichmentPipeline;
use App\Platform\Enrichment\Sentiment\SentimentEnricher;
use App\Platform\Enrichment\Sentiment\SentimentPrediction;
use App\Platform\Enrichment\Sentiment\UnavailableSentimentClassifier;
use App\Shared\Enums\ConfidenceLevel;
use App\Shared\Enums\SentimentLabel;
use App\Shared\Enums\VerificationStatus;
use App\Shared\ValueObjects\ConfidenceAssessment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Sentiment stage (REQ-M1-009): caption/transcript-only analysis with the
 * canonical ENUM-SentimentLabel, mandatory ConfidenceAssessment (DP-003),
 * ambiguity routed to review (DP-004), human precedence, and the
 * "unavailable, never fabricated" rule while no sentiment model is decided.
 * Comments are NEVER analyzed (REQ-M1-010 deferred, DEF-005/ADR-0009).
 */
class SentimentTest extends TestCase
{
    use RefreshDatabase;

    private function contentItem(?string $caption = 'Der Look ist fantastisch, absolut empfehlenswert!'): ContentItem
    {
        // media_urls stays empty so no stage ever reaches for remote media.
        return ContentItem::factory()->create([
            'caption' => $caption,
            'media_urls' => [],
        ]);
    }

    private function bindClassifier(?SentimentPrediction $prediction): RecordingSentimentClassifier
    {
        $classifier = new RecordingSentimentClassifier($prediction);

        $this->app->instance(SentimentClassifier::class, $classifier);

        return $classifier;
    }

    public function test_default_binding_is_unavailable_and_never_fabricates_a_neutral(): void
    {
        // No sentiment model is canonically decided → default binding is the
        // unavailable classifier.
        $this->assertInstanceOf(UnavailableSentimentClassifier::class, app(SentimentClassifier::class));

        $content = $this->contentItem();

        $outcome = app(SentimentEnricher::class)->enrich($content);

        $this->assertSame('unavailable', $outcome);

        // Unavailable means NO row at all — missing values are absent,
        // never a fabricated NEUTRAL (or any other placeholder label).
        $this->assertSame(0, SentimentAnalysis::query()->count());
    }

    public function test_fake_classifier_persists_positive_row_with_full_ai_assessed_envelope(): void
    {
        $this->bindClassifier(new SentimentPrediction(SentimentLabel::Positive, 0.9, ['caption-tone']));

        $content = $this->contentItem();

        $outcome = app(SentimentEnricher::class)->enrich($content);

        $this->assertSame('completed', $outcome);

        $analysis = SentimentAnalysis::query()->sole()->fresh();

        $this->assertSame(SentimentLabel::Positive, $analysis->label);
        $this->assertSame($content->id, $analysis->content_item_id);
        $this->assertNull($analysis->comment_id);

        // DP-003: every AI value carries a full ConfidenceAssessment, and AI
        // writes start at AI_ASSESSED (never any human/confirmed status).
        $this->assertInstanceOf(ConfidenceAssessment::class, $analysis->assessment);
        $this->assertSame(SentimentLabel::Positive->value, $analysis->assessment->value);
        $this->assertSame(ConfidenceLevel::High, $analysis->assessment->confidenceLevel);
        $this->assertSame(['caption-tone'], $analysis->assessment->signals);
        $this->assertSame(VerificationStatus::AiAssessed, $analysis->assessment->verificationStatus);
    }

    public function test_every_canonical_label_persists_exactly(): void
    {
        foreach (SentimentLabel::cases() as $label) {
            $this->bindClassifier(new SentimentPrediction($label, 0.9, ['caption-tone']));

            $content = $this->contentItem();

            $this->assertSame('completed', app(SentimentEnricher::class)->enrich($content));

            $analysis = SentimentAnalysis::query()
                ->where('content_item_id', $content->id)
                ->sole()
                ->fresh();

            $this->assertSame($label, $analysis->label);

            // The raw column holds the canonical enum string, nothing mapped.
            $raw = DB::table('sentiment_analyses')->where('content_item_id', $content->id)->value('label');
            $this->assertSame($label->value, $raw);
        }
    }

    public function test_ambiguous_labels_are_forced_to_low_confidence_and_route_to_review(): void
    {
        foreach ([SentimentLabel::Mixed, SentimentLabel::Unknown] as $label) {
            // 0.95 would bucket as HIGH — ambiguity must override that.
            $this->bindClassifier(new SentimentPrediction($label, 0.95, ['caption-tone']));

            $content = $this->contentItem();

            $this->assertSame('completed', app(SentimentEnricher::class)->enrich($content));

            $analysis = SentimentAnalysis::query()
                ->where('content_item_id', $content->id)
                ->sole()
                ->fresh();

            $this->assertSame(ConfidenceLevel::Low, $analysis->assessment->confidenceLevel);
            $this->assertNotSame(ConfidenceLevel::High, $analysis->assessment->confidenceLevel);
            $this->assertTrue($analysis->assessment->needsHumanReview());

            // The DP-004 review-queue predicate matches the stored envelope.
            $queue = SentimentAnalysis::query()
                ->where(DB::raw("assessment->>'verificationStatus'"), VerificationStatus::AiAssessed->value)
                ->whereIn(DB::raw("assessment->>'confidenceLevel'"), [ConfidenceLevel::Low->value, ConfidenceLevel::Unknown->value])
                ->pluck('id');

            $this->assertContains($analysis->id, $queue);
        }
    }

    public function test_comments_are_never_analyzed_by_enricher_or_pipeline(): void
    {
        Http::fake(); // safety net — no provider may ever be called (DP-005)

        $this->bindClassifier(new SentimentPrediction(SentimentLabel::Positive, 0.9, ['caption-tone']));

        $content = $this->contentItem();
        Comment::factory()->create([
            'content_item_id' => $content->id,
            'text' => 'This comment text must never reach the classifier.',
        ]);

        $this->assertSame('completed', app(SentimentEnricher::class)->enrich($content));

        $run = app(EnrichmentPipeline::class)->run($content, 'corr-sentiment-comments');

        $this->assertSame('completed', $run->stages['sentiment']);

        // REQ-M1-010 is deferred: sentiment rows only ever link to content;
        // the comment_id column stays unused.
        $this->assertSame(0, SentimentAnalysis::query()->whereNotNull('comment_id')->count());
        $this->assertSame(
            SentimentAnalysis::query()->count(),
            SentimentAnalysis::query()->where('content_item_id', $content->id)->whereNull('comment_id')->count(),
        );

        Http::assertNothingSent();
    }

    public function test_empty_caption_yields_no_input_and_no_row(): void
    {
        $classifier = $this->bindClassifier(new SentimentPrediction(SentimentLabel::Positive, 0.9, ['caption-tone']));

        foreach ([null, '', "  \n  "] as $caption) {
            $outcome = app(SentimentEnricher::class)->enrich($this->contentItem($caption));

            $this->assertSame('no-input', $outcome);
        }

        // Nothing to analyze → the classifier is never even consulted and
        // no row exists (absent, never a zero/NEUTRAL placeholder).
        $this->assertNull($classifier->lastInput);
        $this->assertSame(0, SentimentAnalysis::query()->count());
    }

    public function test_human_corrected_analysis_takes_precedence_over_ai_reruns(): void
    {
        $content = $this->contentItem();

        $existing = SentimentAnalysis::factory()->create([
            'content_item_id' => $content->id,
            'label' => SentimentLabel::Negative,
            'assessment' => new ConfidenceAssessment(
                SentimentLabel::Negative->value,
                ConfidenceLevel::High,
                ['human-review'],
                VerificationStatus::HumanCorrected,
            ),
        ]);

        $classifier = $this->bindClassifier(new SentimentPrediction(SentimentLabel::Positive, 0.9, ['caption-tone']));

        $outcome = app(SentimentEnricher::class)->enrich($content);

        $this->assertSame('human-precedence', $outcome);

        // DP-004: the human decision is untouched — same single row, same
        // label, same verification status; the classifier never even ran.
        $this->assertSame(1, SentimentAnalysis::query()->count());

        $fresh = $existing->fresh();
        $this->assertSame(SentimentLabel::Negative, $fresh->label);
        $this->assertSame(VerificationStatus::HumanCorrected, $fresh->assessment->verificationStatus);
        $this->assertNull($classifier->lastInput);
    }

    public function test_transcript_is_combined_with_caption_as_classifier_input(): void
    {
        $classifier = $this->bindClassifier(new SentimentPrediction(SentimentLabel::Positive, 0.9, ['caption-tone', 'transcript-tone']));

        $content = $this->contentItem('Caption with a warm tone');

        $outcome = app(SentimentEnricher::class)->enrich($content, 'transcript text with tone');

        $this->assertSame('completed', $outcome);
        $this->assertSame("Caption with a warm tone\n\ntranscript text with tone", $classifier->lastInput);

        // A transcript alone (no caption) is also a valid input.
        $classifier = $this->bindClassifier(new SentimentPrediction(SentimentLabel::Neutral, 0.7, ['transcript-tone']));

        $outcome = app(SentimentEnricher::class)->enrich($this->contentItem(null), 'transcript only');

        $this->assertSame('completed', $outcome);
        $this->assertSame('transcript only', $classifier->lastInput);
    }
}

/**
 * Test-local fake (DP-005: synthetic only, no live provider): returns a
 * canned prediction and records the exact text it was asked to classify.
 */
class RecordingSentimentClassifier implements SentimentClassifier
{
    public ?string $lastInput = null;

    public function __construct(private readonly ?SentimentPrediction $prediction) {}

    public function classify(string $text): ?SentimentPrediction
    {
        $this->lastInput = $text;

        return $this->prediction;
    }
}
