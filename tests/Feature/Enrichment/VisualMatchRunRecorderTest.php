<?php

namespace Tests\Feature\Enrichment;

use App\Modules\CRM\Models\Brand;
use App\Modules\CRM\Models\Campaign;
use App\Modules\CRM\Models\Product;
use App\Modules\CRM\Models\SeedingCampaign;
use App\Modules\Monitoring\Models\ContentItem;
use App\Modules\Monitoring\Models\VisualMatchCandidate;
use App\Platform\AiBudget\Priority;
use App\Platform\Enrichment\VisualMatch\Candidates\Candidate;
use App\Platform\Enrichment\VisualMatch\Candidates\CandidateSet;
use App\Platform\Enrichment\VisualMatch\Frames\FramePreparationResult;
use App\Platform\Enrichment\VisualMatch\Matching\BandResult;
use App\Platform\Enrichment\VisualMatch\Matching\FrameScore;
use App\Platform\Enrichment\VisualMatch\VisualMatchRunRecorder;
use App\Shared\Enums\SectorLabel;
use App\Shared\Enums\VisualMatchBand;
use App\Shared\Enums\VisualMatchOutcome;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VisualMatchRunRecorderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'qds.enrichment.visual_match.thresholds' => [
                'default' => ['auto' => 0.65, 'review' => 0.55, 'margin' => 0.05],
                'BEAUTY' => ['auto' => 0.70],
            ],
            'qds.ai_budget.capabilities.embedding.price_micro_usd_per_unit' => 120,
        ]);
    }

    public function test_records_the_run_and_ranked_candidate_rows(): void
    {
        $content = ContentItem::factory()->create();
        $brand = Brand::factory()->create(['name' => 'Glossier']);
        $campaign = Campaign::factory()->create(['brand_id' => $brand->id]);
        $seeding = SeedingCampaign::factory()->create(['brand_id' => $brand->id, 'campaign_id' => $campaign->id]);
        $matched = Product::factory()->create(['brand_id' => $brand->id, 'name' => 'You Perfume', 'category' => SectorLabel::Beauty]);
        $photoless = Product::factory()->create(['brand_id' => $brand->id, 'name' => 'Cloud Blush']);

        $matchedCandidate = new Candidate($matched->id, 'You Perfume', 'Glossier', SectorLabel::Beauty, 'shipment', true, null, CarbonImmutable::parse('2026-07-10 10:00:00'), 6, true);
        $photolessCandidate = new Candidate($photoless->id, 'Cloud Blush', 'Glossier', null, 'roster', false, $seeding->id, null, null, false);
        $candidates = new CandidateSet([$matchedCandidate, $photolessCandidate], Priority::High);

        $prep = new FramePreparationResult(frames: [], framesAvailable: 4, skippedFormat: 1, skippedQuality: 0, deduped: 1);

        $result = new BandResult(
            candidate: $matchedCandidate, band: VisualMatchBand::Auto,
            supportingFrames: [new FrameScore(9, 0, 1500, 0.7123, 21, 3)],
            supportCount: 3, marginToRunnerUp: 0.12, rejectionReason: null,
            firstSupportMs: 1500, lastSupportMs: 1500, estimatedVisibleMs: 9000,
            bestSimilarity: 0.7123,
        );

        $run = app(VisualMatchRunRecorder::class)->record(
            $content, 'corr-rec-1', $candidates, $prep, [$result],
            VisualMatchOutcome::Matched, 'gemini-embedding-2', 2, 1, 345, false,
        );

        $this->assertDatabaseHas('visual_match_runs', [
            'id' => $run->id,
            'content_item_id' => $content->id,
            'correlation_id' => 'corr-rec-1',
            'model_version' => 'gemini-embedding-2',
            'priority' => 'high',
            'frames_available' => 4,
            'frames_processed' => 3, // billed 2 + cached 1
            'frames_skipped_format' => 1,
            'frames_skipped_quality' => 0,
            'frames_deduped' => 1,
            'cache_hits' => 1,
            'processing_ms' => 345,
            'candidates_checked' => 2,
            'outcome' => 'matched',
            'embedding_calls' => 2,
            'estimated_cost_micro_usd' => 240,
            'needs_verification' => false,
        ]);
        $this->assertSame(0.7123, (float) $run->best_score);
        $this->assertNull($run->rejection_reason);

        $thresholds = $run->thresholds;
        $this->assertEqualsWithDelta(0.70, $thresholds['BEAUTY']['auto'], 0.0001);
        $this->assertEqualsWithDelta(0.55, $thresholds['BEAUTY']['review'], 0.0001);
        $this->assertEqualsWithDelta(0.65, $thresholds['default']['auto'], 0.0001);

        $this->assertDatabaseHas('visual_match_candidates', [
            'visual_match_run_id' => $run->id, 'rank' => 1,
            'product_id' => $matched->id, 'product_label' => 'You Perfume',
            'category' => 'BEAUTY', 'band' => 'auto',
            'source' => 'shipment', 'shipment_in_window' => true, 'shipment_age_days' => 6,
            'first_support_ms' => 1500, 'last_support_ms' => 1500, 'estimated_visible_ms' => 9000,
        ]);
        // Unmatchable candidate: coverage accounting for D and reviewers.
        $this->assertDatabaseHas('visual_match_candidates', [
            'visual_match_run_id' => $run->id, 'rank' => 2,
            'product_id' => $photoless->id, 'band' => 'reject',
            'rejection_reason' => 'no-embedded-photos',
            'source' => 'roster', 'seeding_campaign_id' => $seeding->id,
        ]);

        $rows = VisualMatchCandidate::query()->where('visual_match_run_id', $run->id)->orderBy('rank')->get();
        // assertEquals, not assertSame: PostgreSQL's jsonb column type does
        // not preserve object-key insertion order (it canonicalizes to a
        // length-then-lexicographic internal order) — the key/value pairs
        // still must match exactly, just not their order.
        $this->assertEquals([
            ['ordinal' => 0, 'timestamp_ms' => 1500, 'similarity' => 0.7123, 'photo_id' => 21, 'represented_frames' => 3],
        ], $rows[0]->supporting_frames);
        $this->assertSame([], $rows[1]->supporting_frames);
    }

    public function test_skipped_runs_record_coverage_but_no_candidate_verdicts(): void
    {
        $content = ContentItem::factory()->create();
        $brand = Brand::factory()->create(['name' => 'Glossier']);
        $product = Product::factory()->create(['brand_id' => $brand->id, 'name' => 'You Perfume']);
        $candidates = new CandidateSet([
            new Candidate($product->id, 'You Perfume', 'Glossier', null, 'shipment', true, null, null, 2, true),
        ], Priority::Medium);
        $prep = new FramePreparationResult([], 3, 0, 0, 0);

        $run = app(VisualMatchRunRecorder::class)->record(
            $content, 'corr-rec-2', $candidates, $prep, [],
            VisualMatchOutcome::SkippedBudget, 'gemini-embedding-2', 0, 0, 12, false,
        );

        $this->assertDatabaseHas('visual_match_runs', [
            'id' => $run->id, 'outcome' => 'skipped_budget', 'priority' => 'medium',
            'embedding_calls' => 0, 'estimated_cost_micro_usd' => 0, 'candidates_checked' => 1,
        ]);
        $this->assertNull($run->best_score);
        // A skipped run assessed nothing — fabricating per-candidate
        // verdicts would be dishonest.
        $this->assertSame(0, VisualMatchCandidate::query()->count());
    }
}
