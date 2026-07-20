<?php

namespace Tests\Feature\Enrichment;

use App\Modules\CRM\Models\Product;
use App\Modules\Monitoring\Models\ContentItem;
use App\Modules\Monitoring\Models\Story;
use App\Modules\Monitoring\Models\VisualMatchRun;
use App\Modules\Monitoring\Models\VlmCandidateVerdict;
use App\Modules\Monitoring\Models\VlmVerificationRun;
use App\Platform\AiBudget\Priority;
use App\Shared\Enums\VlmBand;
use App\Shared\Enums\VlmRunOutcome;
use App\Shared\Enums\VlmTriggerReason;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Sub-project D audit trail (spec §8.1/§8.2): vlm_verification_runs (one
 * append-only row per verification attempt-set, with DB-level consumption
 * bookkeeping) + vlm_candidate_verdicts (per-candidate verdicts —
 * sub-project E's "Gemini agreement" input). Tenant-owned with composite
 * FKs; catalog edits must never rewrite the audit trail.
 */
class VlmVerificationTablesTest extends TestCase
{
    use RefreshDatabase;

    public function test_run_and_verdict_factories_persist_and_round_trip_enums(): void
    {
        $run = VlmVerificationRun::factory()->create();
        $verdict = VlmCandidateVerdict::factory()->create([
            'vlm_verification_run_id' => $run->id,
        ]);

        $run->refresh();
        $verdict->refresh();

        $this->assertSame(VlmRunOutcome::Confirmed, $run->outcome);
        $this->assertSame(VlmTriggerReason::ReviewBand, $run->trigger_reason);
        $this->assertSame(Priority::High, $run->priority);
        $this->assertSame('gemini-3.5-flash', $run->model_version);
        $this->assertSame(1, $run->attempts);
        $this->assertIsArray($run->thresholds);
        $this->assertEqualsWithDelta(0.85, $run->thresholds['auto'], 0.00001);
        $this->assertNotNull($run->created_at);
        $this->assertNotNull($run->updated_at);
        $this->assertNotNull($run->visual_match_run_id);
        // The default anchor covers the SAME content item as the run.
        $this->assertSame($run->content_item_id, $run->visualMatchRun->content_item_id);
        $this->assertTrue($run->visualMatchRun->needs_verification);

        $this->assertSame(VlmBand::Auto, $verdict->band);
        $this->assertTrue($verdict->visible);
        $this->assertFalse($verdict->spoken);
        $this->assertFalse($verdict->gifting_cue);
        $this->assertEqualsWithDelta(0.9100, $verdict->confidence, 0.00001);
        $this->assertSame([1500, 4000], $verdict->frame_timestamps);
        $this->assertSame($run->id, $verdict->run->id);
        $this->assertTrue($run->verdicts()->whereKey($verdict->id)->exists());
    }

    public function test_a_run_with_both_targets_is_rejected(): void
    {
        $this->expectException(QueryException::class);

        // Factory default already sets content_item_id; adding story_id
        // violates the num_nonnulls(content_item_id, story_id) = 1 CHECK.
        VlmVerificationRun::factory()->create([
            'story_id' => Story::factory()->create()->id,
        ]);
    }

    public function test_a_run_with_no_target_is_rejected(): void
    {
        $this->expectException(QueryException::class);

        VlmVerificationRun::factory()->discovery()->create([
            'content_item_id' => null,
            'story_id' => null,
        ]);
    }

    public function test_in_story_state_builds_a_story_run(): void
    {
        $run = VlmVerificationRun::factory()->inStory()->create();

        $this->assertNull($run->content_item_id);
        $this->assertNotNull($run->story_id);
        $this->assertSame($run->story_id, $run->story->id);
        $this->assertSame($run->story_id, $run->visualMatchRun->story_id);
    }

    public function test_an_anchor_is_consumed_once_per_model_version(): void
    {
        $anchor = VisualMatchRun::factory()->create(['needs_verification' => true]);
        VlmVerificationRun::factory()->forAnchor($anchor)->create();

        try {
            // Savepoint-wrapped: the violation aborts only this SAVEPOINT so
            // the outer RefreshDatabase transaction stays usable for the
            // model-upgrade insert below (PostgreSQL 25P02 otherwise).
            DB::transaction(fn () => VlmVerificationRun::factory()->forAnchor($anchor)->create());
            $this->fail('A second verification for the same anchor at the same model_version must violate vlm_runs_anchor_model_unique.');
        } catch (UniqueConstraintViolationException $e) {
            $this->assertStringContainsString('vlm_runs_anchor_model_unique', $e->getMessage());
        }

        // A model upgrade re-opens the anchor (append-only re-verification).
        $reopened = VlmVerificationRun::factory()->forAnchor($anchor)->create([
            'model_version' => 'gemini-4-flash',
        ]);

        $this->assertSame($anchor->id, $reopened->visual_match_run_id);
    }

    public function test_discovery_rows_dedupe_per_owner_and_reason(): void
    {
        $item = ContentItem::factory()->create();
        VlmVerificationRun::factory()->discovery()->create(['content_item_id' => $item->id]);

        try {
            // Savepoint-wrapped: the violation aborts only this SAVEPOINT so
            // the outer RefreshDatabase transaction stays usable for the
            // different-reason insert below (PostgreSQL 25P02 otherwise).
            DB::transaction(fn () => VlmVerificationRun::factory()->discovery()->create(['content_item_id' => $item->id]));
            $this->fail('The daily sweep must never duplicate a discovery row for the same owner and reason.');
        } catch (UniqueConstraintViolationException $e) {
            $this->assertStringContainsString('vlm_runs_discovery_content_unique', $e->getMessage());
        }

        // A different reason is a different recorded fact — allowed.
        VlmVerificationRun::factory()->discovery()->create([
            'content_item_id' => $item->id,
            'trigger_reason' => VlmTriggerReason::UnverifiableSkippedRun,
        ]);

        $this->assertSame(2, VlmVerificationRun::query()->count());
    }

    public function test_set_null_orphaned_anchored_rows_never_collide_in_discovery_dedup(): void
    {
        // Two verifications of the SAME content item at the SAME
        // non-unverifiable trigger_reason ('review-band'), each anchored to a
        // DIFFERENT C run. Deleting both anchors SET-NULLs visual_match_run_id
        // on both audit rows (column-scoped ON DELETE SET NULL). The discovery
        // dedup index must NOT then treat these orphans as duplicate discovery
        // rows: its predicate is scoped to the two 'unverifiable:*' reasons,
        // which anchored rows never carry. With the old
        // `WHERE visual_match_run_id IS NULL` predicate the second anchor
        // delete threw vlm_runs_discovery_content_unique, blocking retention
        // prunes / GDPR erase of visual_match_runs.
        $item = ContentItem::factory()->create();
        $anchorA = VisualMatchRun::factory()->create(['content_item_id' => $item->id, 'needs_verification' => true]);
        $anchorB = VisualMatchRun::factory()->create(['content_item_id' => $item->id, 'needs_verification' => true]);

        $runA = VlmVerificationRun::factory()->forAnchor($anchorA)->create([
            'trigger_reason' => VlmTriggerReason::ReviewBand,
        ]);
        $runB = VlmVerificationRun::factory()->forAnchor($anchorB)->create([
            'trigger_reason' => VlmTriggerReason::ReviewBand,
        ]);

        // Both anchor deletes must succeed. The second delete's SET NULL used
        // to collide with the first orphan in the discovery unique index.
        DB::table('visual_match_runs')->where('id', $anchorA->id)->delete();
        DB::table('visual_match_runs')->where('id', $anchorB->id)->delete();

        $runA->refresh();
        $runB->refresh();

        // Both orphaned audit rows survive with a null anchor and their
        // original non-unverifiable trigger_reason intact.
        $this->assertNull($runA->visual_match_run_id);
        $this->assertNull($runB->visual_match_run_id);
        $this->assertSame(VlmTriggerReason::ReviewBand, $runA->trigger_reason);
        $this->assertSame(VlmTriggerReason::ReviewBand, $runB->trigger_reason);
        $this->assertSame(2, VlmVerificationRun::query()->count());
    }

    public function test_anchor_delete_nulls_the_link_but_keeps_the_audit_row(): void
    {
        $run = VlmVerificationRun::factory()->create();
        $anchorId = $run->visual_match_run_id;

        DB::table('visual_match_runs')->where('id', $anchorId)->delete();

        $run->refresh();
        // SET NULL is column-scoped (PG15+ column list): only
        // visual_match_run_id clears — outcome, tenant and target survive.
        $this->assertNull($run->visual_match_run_id);
        $this->assertSame(VlmRunOutcome::Confirmed, $run->outcome);
        $this->assertNotNull($run->tenant_id);
        $this->assertNotNull($run->content_item_id);
    }

    public function test_verdicts_cascade_when_their_run_is_deleted(): void
    {
        $run = VlmVerificationRun::factory()->create();
        VlmCandidateVerdict::factory()->count(2)->sequence(['rank' => 1], ['rank' => 2])
            ->create(['vlm_verification_run_id' => $run->id]);

        DB::table('vlm_verification_runs')->where('id', $run->id)->delete();

        $this->assertSame(0, VlmCandidateVerdict::query()->count());
    }

    public function test_product_delete_nulls_the_link_but_keeps_the_audit_labels(): void
    {
        $product = Product::factory()->create();
        $verdict = VlmCandidateVerdict::factory()->create([
            'product_id' => $product->id,
            'product_label' => 'Nexon Labs Headset',
            'brand_label' => 'Nexon Labs',
        ]);

        DB::table('products')->where('id', $product->id)->delete();

        $verdict->refresh();
        // SET NULL is column-scoped (PG15+ column list): only product_id
        // clears — the denormalized labels and tenant ownership survive.
        $this->assertNull($verdict->product_id);
        $this->assertSame('Nexon Labs Headset', $verdict->product_label);
        $this->assertSame('Nexon Labs', $verdict->brand_label);
        $this->assertNotNull($verdict->tenant_id);
    }

    public function test_runs_are_tenant_coherent_with_their_content_item(): void
    {
        $foreign = $this->makeTenant('Foreign Tenant');
        $foreignItem = $this->withTenant($foreign, fn (): ContentItem => ContentItem::factory()->create());

        $this->expectException(QueryException::class);

        // Composite (content_item_id, tenant_id) FK rejects the pair; the
        // discovery state keeps the anchor out of the way.
        VlmVerificationRun::factory()->discovery()->create(['content_item_id' => $foreignItem->id]);
    }

    public function test_a_run_pointing_at_another_tenants_anchor_violates_the_composite_fk(): void
    {
        $other = $this->makeTenant('Other Workspace');
        $foreignAnchor = $this->withTenant($other, fn (): VisualMatchRun => VisualMatchRun::factory()->create());

        try {
            VlmVerificationRun::factory()->create([
                'content_item_id' => ContentItem::factory()->create()->id,
                'visual_match_run_id' => $foreignAnchor->id,
            ]);
            $this->fail("A run pointing at another tenant's anchor must violate the composite FK.");
        } catch (QueryException $e) {
            $this->assertStringContainsString('vlm_verification_runs_visual_match_run_tenant_fk', $e->getMessage());
        }
    }

    public function test_the_outcome_check_rejects_unknown_values(): void
    {
        $run = VlmVerificationRun::factory()->create();

        $this->expectException(QueryException::class);
        DB::statement(
            'UPDATE vlm_verification_runs SET outcome = ? WHERE id = ?',
            ['exploded', $run->id]
        );
    }
}
