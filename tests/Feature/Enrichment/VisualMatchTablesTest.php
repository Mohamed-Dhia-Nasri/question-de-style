<?php

namespace Tests\Feature\Enrichment;

use App\Modules\CRM\Models\Product;
use App\Modules\Monitoring\Models\ContentItem;
use App\Modules\Monitoring\Models\Story;
use App\Modules\Monitoring\Models\VisualMatchCandidate;
use App\Modules\Monitoring\Models\VisualMatchRun;
use App\Platform\AiBudget\Priority;
use App\Shared\Enums\SectorLabel;
use App\Shared\Enums\VisualMatchBand;
use App\Shared\Enums\VisualMatchOutcome;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Sub-project C audit trail (spec §4.4/§4.5): visual_match_runs (one
 * append-only row per analysis run) + visual_match_candidates (ranked
 * shortlist with candidate-source and visibility evidence). Tenant-owned
 * with composite FKs; catalog edits must never rewrite the audit trail.
 */
class VisualMatchTablesTest extends TestCase
{
    use RefreshDatabase;

    public function test_run_and_candidate_factories_persist_and_round_trip_enums(): void
    {
        $run = VisualMatchRun::factory()->create([
            'outcome' => VisualMatchOutcome::Matched,
            'best_score' => 0.8123,
            'needs_verification' => true,
        ]);
        $candidate = VisualMatchCandidate::factory()->create([
            'visual_match_run_id' => $run->id,
            'band' => VisualMatchBand::Auto,
            'category' => SectorLabel::Tech,
            'first_support_ms' => 0,
            'last_support_ms' => 12000,
            'estimated_visible_ms' => 18000,
        ]);

        $run->refresh();
        $candidate->refresh();

        $this->assertSame(VisualMatchOutcome::Matched, $run->outcome);
        $this->assertSame(Priority::High, $run->priority);
        $this->assertTrue($run->needs_verification);
        $this->assertEqualsWithDelta(0.8123, $run->best_score, 0.00001);
        $this->assertNotNull($run->created_at);
        $this->assertIsArray($run->thresholds);
        $this->assertSame(VisualMatchBand::Auto, $candidate->band);
        $this->assertSame(SectorLabel::Tech, $candidate->category);
        $this->assertSame(18000, $candidate->estimated_visible_ms);
        $this->assertIsArray($candidate->supporting_frames);
        $this->assertSame($run->id, $candidate->run->id);
        $this->assertTrue($run->candidates()->whereKey($candidate->id)->exists());
    }

    public function test_a_run_with_both_targets_is_rejected(): void
    {
        $this->expectException(QueryException::class);

        // Factory default already sets content_item_id; adding story_id
        // violates the num_nonnulls(content_item_id, story_id) = 1 CHECK.
        VisualMatchRun::factory()->create([
            'story_id' => Story::factory()->create()->id,
        ]);
    }

    public function test_a_run_with_no_target_is_rejected(): void
    {
        $this->expectException(QueryException::class);

        VisualMatchRun::factory()->create(['content_item_id' => null, 'story_id' => null]);
    }

    public function test_in_story_state_builds_a_story_run(): void
    {
        $run = VisualMatchRun::factory()->inStory()->create();

        $this->assertNull($run->content_item_id);
        $this->assertNotNull($run->story_id);
        $this->assertSame($run->story_id, $run->story->id);
    }

    public function test_candidates_cascade_when_their_run_is_deleted(): void
    {
        $run = VisualMatchRun::factory()->create();
        VisualMatchCandidate::factory()->count(2)->sequence(['rank' => 1], ['rank' => 2])
            ->create(['visual_match_run_id' => $run->id]);

        DB::table('visual_match_runs')->where('id', $run->id)->delete();

        $this->assertSame(0, VisualMatchCandidate::query()->count());
    }

    public function test_product_delete_nulls_the_link_but_keeps_the_audit_label(): void
    {
        $product = Product::factory()->create();
        $candidate = VisualMatchCandidate::factory()->create([
            'product_id' => $product->id,
            'product_label' => 'Nexon Labs Headset',
        ]);

        DB::table('products')->where('id', $product->id)->delete();

        $candidate->refresh();
        // SET NULL is column-scoped (PG15+ column list): only product_id
        // clears — the denormalized label and tenant ownership survive.
        $this->assertNull($candidate->product_id);
        $this->assertSame('Nexon Labs Headset', $candidate->product_label);
        $this->assertNotNull($candidate->tenant_id);
    }

    public function test_runs_are_tenant_coherent_with_their_content_item(): void
    {
        $foreign = $this->makeTenant('Foreign Tenant');
        $foreignItem = $this->withTenant($foreign, fn (): ContentItem => ContentItem::factory()->create());

        $this->expectException(QueryException::class);

        // Composite (content_item_id, tenant_id) FK rejects the cross-tenant pair.
        VisualMatchRun::factory()->create(['content_item_id' => $foreignItem->id]);
    }
}
