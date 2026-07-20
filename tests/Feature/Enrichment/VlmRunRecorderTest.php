<?php

namespace Tests\Feature\Enrichment;

use App\Modules\CRM\Models\Brand;
use App\Modules\CRM\Models\Product;
use App\Modules\Monitoring\Models\ContentItem;
use App\Modules\Monitoring\Models\Story;
use App\Modules\Monitoring\Models\VisualMatchRun;
use App\Modules\Monitoring\Models\VlmCandidateVerdict;
use App\Modules\Monitoring\Models\VlmVerificationRun;
use App\Platform\AiBudget\Priority;
use App\Platform\Enrichment\VlmVerification\Banding\VlmBandResult;
use App\Platform\Enrichment\VlmVerification\Verdicts\CandidateVerdict;
use App\Platform\Enrichment\VlmVerification\Verdicts\VerdictSet;
use App\Platform\Enrichment\VlmVerification\VlmRunRecorder;
use App\Shared\Enums\VlmBand;
use App\Shared\Enums\VlmRunOutcome;
use App\Shared\Enums\VlmTriggerReason;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class VlmRunRecorderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'qds.enrichment.vlm.thresholds' => ['auto' => 0.85, 'review' => 0.60, 'margin' => 0.10],
            'qds.ai_budget.capabilities.vlm_verification.price_micro_usd_per_unit' => 30000,
        ]);
    }

    private function recorder(): VlmRunRecorder
    {
        return app(VlmRunRecorder::class);
    }

    /** @return array{0: ContentItem, 1: VisualMatchRun} */
    private function anchored(): array
    {
        $content = ContentItem::factory()->create();
        $anchor = VisualMatchRun::factory()->create([
            'content_item_id' => $content->id,
            'needs_verification' => true,
        ]);

        return [$content, $anchor];
    }

    private function open(ContentItem $content, VisualMatchRun $anchor, string $correlationId = 'corr-vlm-1'): VlmVerificationRun
    {
        return $this->recorder()->open(
            $content, $anchor, VlmTriggerReason::ReviewBand, Priority::High,
            'gemini-3.5-flash', $correlationId, 9,
        );
    }

    /** @param list<int> $frames */
    private function verdict(int $productId, float $confidence, array $frames = [1000, 5000]): CandidateVerdict
    {
        return new CandidateVerdict(
            productKey: 'P'.$productId,
            productId: $productId,
            visible: true,
            spoken: false,
            giftingCue: true,
            confidence: $confidence,
            frameTimestampsMs: $frames,
            rationale: 'Bottle visible on the vanity.',
        );
    }

    public function test_open_creates_a_pending_ledger_row(): void
    {
        [$content, $anchor] = $this->anchored();

        $run = $this->open($content, $anchor);

        $this->assertDatabaseHas('vlm_verification_runs', [
            'id' => $run->id,
            'content_item_id' => $content->id,
            'story_id' => null,
            'visual_match_run_id' => $anchor->id,
            'correlation_id' => 'corr-vlm-1',
            'model_version' => 'gemini-3.5-flash',
            'trigger_reason' => 'review-band',
            'priority' => 'high',
            'frames_sent' => 9,
            'attempts' => 0,
            'outcome' => 'pending',
            'latency_ms' => 0,
            'estimated_cost_micro_usd' => 0,
        ]);
        // Threshold snapshot: the placeholder values in force at open time.
        $this->assertEqualsWithDelta(0.85, $run->thresholds['auto'], 0.0001);
        $this->assertEqualsWithDelta(0.60, $run->thresholds['review'], 0.0001);
        $this->assertEqualsWithDelta(0.10, $run->thresholds['margin'], 0.0001);
    }

    public function test_open_resumes_the_existing_pending_row_attempts_intact(): void
    {
        [$content, $anchor] = $this->anchored();
        $recorder = $this->recorder();

        $first = $this->open($content, $anchor);
        $recorder->incrementAttempts($first);
        $recorder->incrementAttempts($first);

        // A crashed execution's retry re-opens: SAME row, billing count
        // intact — never a fresh ledger (spec §10 crash-safe ledger).
        $resumed = $this->open($content, $anchor, correlationId: 'corr-vlm-2');

        $this->assertSame($first->id, $resumed->id);
        $this->assertSame(2, $resumed->attempts);
        // The original correlation id is preserved (append-only audit).
        $this->assertSame('corr-vlm-1', $resumed->correlation_id);
        $this->assertSame(1, VlmVerificationRun::query()->count());
    }

    public function test_increment_attempts_commits_the_cost_ledger(): void
    {
        [$content, $anchor] = $this->anchored();
        $run = $this->open($content, $anchor);

        $this->recorder()->incrementAttempts($run);
        $this->assertDatabaseHas('vlm_verification_runs', [
            'id' => $run->id, 'attempts' => 1, 'estimated_cost_micro_usd' => 30000,
        ]);

        $this->recorder()->incrementAttempts($run);
        $this->assertDatabaseHas('vlm_verification_runs', [
            'id' => $run->id, 'attempts' => 2, 'estimated_cost_micro_usd' => 60000,
        ]);
    }

    public function test_increment_attempts_refuses_a_terminal_run(): void
    {
        [$content, $anchor] = $this->anchored();
        $run = $this->open($content, $anchor);
        $this->recorder()->finalize($run, VlmRunOutcome::SkippedProvider, null, [], null, null, null, 40);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Attempts are billed against a pending run only.');

        $this->recorder()->incrementAttempts($run);
    }

    public function test_finalize_writes_outcome_tokens_and_ranked_banded_verdicts(): void
    {
        [$content, $anchor] = $this->anchored();
        $brand = Brand::factory()->create(['name' => 'Glossier']);
        $perfume = Product::factory()->create(['brand_id' => $brand->id, 'name' => 'You Perfume']);
        $blush = Product::factory()->create(['brand_id' => $brand->id, 'name' => 'Cloud Blush']);

        $run = $this->open($content, $anchor);
        $this->recorder()->incrementAttempts($run);

        $winner = $this->verdict($perfume->id, 0.91);
        $runnerUp = $this->verdict($blush->id, 0.83, frames: [7000]);

        $this->recorder()->finalize(
            $run,
            VlmRunOutcome::Confirmed,
            new VerdictSet(outcome: 'PRODUCT_CONFIRMED', verdicts: [$winner, $runnerUp]),
            [
                new VlmBandResult(verdict: $winner, band: VlmBand::Auto, rejectionReason: null, captionEcho: false),
                new VlmBandResult(verdict: $runnerUp, band: VlmBand::Review, rejectionReason: 'margin-ambiguous', captionEcho: false),
            ],
            9800, 450, 120, 2100,
        );

        $this->assertDatabaseHas('vlm_verification_runs', [
            'id' => $run->id,
            'outcome' => 'confirmed',
            'prompt_tokens' => 9800,
            'output_tokens' => 450,
            'thinking_tokens' => 120,
            'latency_ms' => 2100,
            'rejection_reason' => null,
            'attempts' => 1,
            'estimated_cost_micro_usd' => 30000,
        ]);

        $rows = VlmCandidateVerdict::query()->where('vlm_verification_run_id', $run->id)->orderBy('rank')->get();
        $this->assertCount(2, $rows);

        $this->assertSame(1, $rows[0]->rank);
        $this->assertSame($perfume->id, $rows[0]->product_id);
        $this->assertSame('You Perfume', $rows[0]->product_label);
        $this->assertSame('Glossier', $rows[0]->brand_label);
        $this->assertTrue($rows[0]->visible);
        $this->assertFalse($rows[0]->spoken);
        $this->assertTrue($rows[0]->gifting_cue);
        $this->assertEqualsWithDelta(0.91, (float) $rows[0]->confidence, 0.0001);
        $this->assertEquals([1000, 5000], $rows[0]->frame_timestamps);
        $this->assertSame('Bottle visible on the vanity.', $rows[0]->rationale);
        $this->assertSame(VlmBand::Auto, $rows[0]->band);
        $this->assertNull($rows[0]->rejection_reason);

        $this->assertSame(2, $rows[1]->rank);
        $this->assertSame('Cloud Blush', $rows[1]->product_label);
        $this->assertSame(VlmBand::Review, $rows[1]->band);
        $this->assertSame('margin-ambiguous', $rows[1]->rejection_reason);
    }

    public function test_finalize_is_a_single_pending_to_terminal_transition(): void
    {
        [$content, $anchor] = $this->anchored();
        $run = $this->open($content, $anchor);
        $this->recorder()->finalize($run, VlmRunOutcome::Inconclusive, null, [], null, null, null, 900);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('A VLM run is finalized exactly once: pending -> terminal.');

        $this->recorder()->finalize($run, VlmRunOutcome::Confirmed, null, [], null, null, null, 900);
    }

    public function test_finalize_refuses_pending_as_a_terminal_outcome(): void
    {
        [$content, $anchor] = $this->anchored();
        $run = $this->open($content, $anchor);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Pending is not a terminal outcome.');

        $this->recorder()->finalize($run, VlmRunOutcome::Pending, null, [], null, null, null, 0);
    }

    public function test_skipped_outcomes_must_not_carry_verdicts(): void
    {
        [$content, $anchor] = $this->anchored();
        $run = $this->open($content, $anchor);
        $set = new VerdictSet(outcome: 'PRODUCT_CONFIRMED', verdicts: [$this->verdict(1, 0.9)]);

        try {
            $this->recorder()->finalize($run, VlmRunOutcome::SkippedNoFrames, $set, [], null, null, null, 10);
            $this->fail('Expected the boundary guard to throw.');
        } catch (InvalidArgumentException $e) {
            $this->assertSame('A skipped or failed VLM run records no candidate verdicts.', $e->getMessage());
        }

        // The guard precedes every write: the row is still pending and
        // carries no fabricated verdicts.
        $this->assertDatabaseHas('vlm_verification_runs', ['id' => $run->id, 'outcome' => 'pending']);
        $this->assertSame(0, VlmCandidateVerdict::query()->count());

        // A verdict-less skip finalizes fine, rejection reason recorded.
        $this->recorder()->finalize($run, VlmRunOutcome::SkippedPayloadGuard, null, [], null, null, null, 15, 'payload-guard');
        $this->assertDatabaseHas('vlm_verification_runs', [
            'id' => $run->id, 'outcome' => 'skipped_payload_guard', 'rejection_reason' => 'payload-guard',
        ]);
    }

    public function test_inconclusive_with_a_set_but_no_bands_persists_null_band_verdicts(): void
    {
        [$content, $anchor] = $this->anchored();
        $brand = Brand::factory()->create(['name' => 'Glossier']);
        $perfume = Product::factory()->create(['brand_id' => $brand->id, 'name' => 'You Perfume']);
        $blush = Product::factory()->create(['brand_id' => $brand->id, 'name' => 'Cloud Blush']);

        $run = $this->open($content, $anchor);
        $this->recorder()->incrementAttempts($run);

        // The §6 normalization case: outcome forced to INCONCLUSIVE, no
        // bands computed — verdicts still persist for sub-project E, band
        // null, ranked by confidence desc; the normalization marker lands
        // on the RUN row (rejection_reason), never on a detection.
        $this->recorder()->finalize(
            $run,
            VlmRunOutcome::Inconclusive,
            new VerdictSet(outcome: 'INCONCLUSIVE', verdicts: [
                $this->verdict($blush->id, 0.41, frames: []),
                $this->verdict($perfume->id, 0.55, frames: []),
            ]),
            [],
            8700, 300, 90, 1400,
            'vlm-outcome-normalized',
        );

        $this->assertDatabaseHas('vlm_verification_runs', [
            'id' => $run->id, 'outcome' => 'inconclusive', 'rejection_reason' => 'vlm-outcome-normalized',
        ]);

        $rows = VlmCandidateVerdict::query()->where('vlm_verification_run_id', $run->id)->orderBy('rank')->get();
        $this->assertCount(2, $rows);
        $this->assertSame('You Perfume', $rows[0]->product_label); // 0.55 ranks first
        $this->assertNull($rows[0]->band);
        $this->assertNull($rows[1]->band);
        $this->assertEquals([], $rows[0]->frame_timestamps);
    }

    public function test_rejection_reasons_are_truncated_to_the_column_width(): void
    {
        [$content, $anchor] = $this->anchored();
        $brand = Brand::factory()->create(['name' => 'Glossier']);
        $product = Product::factory()->create(['brand_id' => $brand->id, 'name' => 'You Perfume']);

        $run = $this->open($content, $anchor);
        $this->recorder()->incrementAttempts($run);
        $verdict = $this->verdict($product->id, 0.62);

        // Model-supplied reason strings can exceed the varchar(100)
        // columns — both write sites truncate BEFORE persisting, so an
        // oversized reason can never abort a finalize (Task 9 review
        // hardening). Multibyte text truncates on characters, not bytes.
        $longReason = str_repeat('é', 150);

        $this->recorder()->finalize(
            $run, VlmRunOutcome::Inconclusive,
            new VerdictSet(outcome: 'INCONCLUSIVE', verdicts: [$verdict]),
            [new VlmBandResult(verdict: $verdict, band: VlmBand::Review, rejectionReason: $longReason, captionEcho: false)],
            null, null, null, 700,
            $longReason,
        );

        $this->assertSame(str_repeat('é', 100), $run->fresh()->rejection_reason);
        $this->assertSame(str_repeat('é', 100), VlmCandidateVerdict::query()->firstOrFail()->rejection_reason);
    }

    public function test_a_vanished_product_keeps_its_verdict_under_a_fallback_label(): void
    {
        [$content, $anchor] = $this->anchored();
        $brand = Brand::factory()->create(['name' => 'Glossier']);
        $product = Product::factory()->create(['brand_id' => $brand->id, 'name' => 'You Perfume']);

        $run = $this->open($content, $anchor);
        $this->recorder()->incrementAttempts($run);
        $verdict = $this->verdict($product->id, 0.88);
        $product->delete();

        $this->recorder()->finalize(
            $run, VlmRunOutcome::Confirmed,
            new VerdictSet(outcome: 'PRODUCT_CONFIRMED', verdicts: [$verdict]),
            [new VlmBandResult(verdict: $verdict, band: VlmBand::Auto, rejectionReason: null, captionEcho: false)],
            null, null, null, 800,
        );

        $row = VlmCandidateVerdict::query()->firstOrFail();
        $this->assertNull($row->product_id);
        $this->assertSame('P'.$verdict->productId, $row->product_label);
        $this->assertSame('unknown', $row->brand_label);
    }

    public function test_delete_unbilled_unconsumes_only_a_pending_zero_attempt_row(): void
    {
        [$content, $anchor] = $this->anchored();
        $run = $this->open($content, $anchor);

        $this->recorder()->deleteUnbilled($run);

        // Nothing billed → nothing consumed: the anchor is sweep-eligible again.
        $this->assertSame(0, VlmVerificationRun::query()->count());
        $this->assertFalse($this->recorder()->terminalRunExists($anchor, 'gemini-3.5-flash'));

        $billed = $this->open($content, $anchor);
        $this->recorder()->incrementAttempts($billed);

        try {
            $this->recorder()->deleteUnbilled($billed);
            $this->fail('Expected the ledger guard to throw.');
        } catch (InvalidArgumentException $e) {
            $this->assertSame('Only a pending run with zero billed attempts may be deleted.', $e->getMessage());
        }

        // The billed ledger row survives — money spent is never forgotten.
        $this->assertDatabaseHas('vlm_verification_runs', ['id' => $billed->id, 'attempts' => 1]);
    }

    public function test_terminal_run_exists_is_the_consumption_check(): void
    {
        [$content, $anchor] = $this->anchored();
        $recorder = $this->recorder();

        $this->assertFalse($recorder->terminalRunExists(null, 'gemini-3.5-flash'));
        $this->assertFalse($recorder->terminalRunExists($anchor, 'gemini-3.5-flash'));

        $run = $this->open($content, $anchor);
        // Pending rows do NOT consume — they are resumable ledgers.
        $this->assertFalse($recorder->terminalRunExists($anchor, 'gemini-3.5-flash'));

        $recorder->finalize($run, VlmRunOutcome::SkippedProvider, null, [], null, null, null, 60);
        $this->assertTrue($recorder->terminalRunExists($anchor, 'gemini-3.5-flash'));
        // A model_version bump re-opens old anchors (append-only re-verification).
        $this->assertFalse($recorder->terminalRunExists($anchor, 'gemini-4-flash'));
    }

    public function test_record_unverifiable_writes_once_per_owner_and_reason(): void
    {
        $content = ContentItem::factory()->create();
        $recorder = $this->recorder();

        $run = $recorder->recordUnverifiable($content, VlmTriggerReason::UnverifiableNoRun, 'gemini-3.5-flash', 'corr-sweep-1');

        $this->assertNotNull($run);
        $this->assertDatabaseHas('vlm_verification_runs', [
            'id' => $run->id,
            'content_item_id' => $content->id,
            'visual_match_run_id' => null,
            'trigger_reason' => 'unverifiable:no-run',
            'outcome' => 'unverifiable',
            'priority' => 'medium',
            'frames_sent' => 0,
            'attempts' => 0,
            'estimated_cost_micro_usd' => 0,
        ]);

        // The daily sweep can never duplicate discovery rows.
        $this->assertNull($recorder->recordUnverifiable($content, VlmTriggerReason::UnverifiableNoRun, 'gemini-3.5-flash', 'corr-sweep-2'));
        $this->assertSame(1, VlmVerificationRun::query()->count());

        // A different discovery reason is its own fact.
        $this->assertNotNull($recorder->recordUnverifiable($content, VlmTriggerReason::UnverifiableSkippedRun, 'gemini-3.5-flash', 'corr-sweep-3'));
        $this->assertSame(2, VlmVerificationRun::query()->count());
    }

    public function test_record_unverifiable_refuses_non_discovery_reasons(): void
    {
        $content = ContentItem::factory()->create();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unverifiable rows carry an unverifiable:* trigger reason.');

        $this->recorder()->recordUnverifiable($content, VlmTriggerReason::ReviewBand, 'gemini-3.5-flash', 'corr-sweep-4');
    }

    public function test_unverifiable_rows_for_stories_key_on_story_id(): void
    {
        $story = Story::factory()->create();

        $run = $this->recorder()->recordUnverifiable($story, VlmTriggerReason::UnverifiableNoRun, 'gemini-3.5-flash', 'corr-sweep-5');

        $this->assertNotNull($run);
        $this->assertDatabaseHas('vlm_verification_runs', [
            'id' => $run->id, 'story_id' => $story->id, 'content_item_id' => null,
        ]);
    }

    public function test_runs_are_tenant_scoped(): void
    {
        [$tenantA, $tenantB] = $this->makeTenantPair();

        $runId = $this->withTenant($tenantA, function (): int {
            $content = ContentItem::factory()->create();
            $anchor = VisualMatchRun::factory()->create([
                'content_item_id' => $content->id,
                'needs_verification' => true,
            ]);

            return $this->open($content, $anchor)->id;
        });

        $this->withTenant($tenantB, function () use ($runId): void {
            $this->assertNull(VlmVerificationRun::query()->find($runId));
        });

        $this->withTenant($tenantA, function () use ($runId): void {
            $this->assertNotNull(VlmVerificationRun::query()->find($runId));
        });
    }
}
