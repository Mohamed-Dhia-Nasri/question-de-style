<?php

namespace Tests\Feature\Enrichment\VlmVerification;

use App\Models\Tenant;
use App\Modules\CRM\Models\Creator;
use App\Modules\CRM\Models\PlatformAccount;
use App\Modules\CRM\Models\Product;
use App\Modules\CRM\Models\SeedingCampaign;
use App\Modules\CRM\Models\Shipment;
use App\Modules\Monitoring\Models\ContentItem;
use App\Modules\Monitoring\Models\VisualMatchRun;
use App\Modules\Monitoring\Models\VlmVerificationRun;
use App\Platform\AiBudget\Priority;
use App\Platform\Enrichment\VlmVerification\Jobs\VlmVerificationJob;
use App\Shared\Enums\SeedingCampaignStatus;
use App\Shared\Enums\VisualMatchOutcome;
use App\Shared\Enums\VlmRunOutcome;
use App\Shared\Enums\VlmTriggerReason;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * qds:vlm-verify (sub-project D, spec §4/§10/§14): catch-up dispatch for
 * flagged-but-unconsumed visual runs, DEF-021 'unverifiable' discovery
 * (never a Gemini call), and the stale-pending crash backstop. Self-gated
 * on BOTH the vlm and visual_match switches. Queue::fake + Http::fake —
 * the sweep itself must never touch a provider.
 */
class VlmVerifySweepCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Http::fake();
        config([
            'qds.enrichment.vlm.enabled' => true,
            'qds.enrichment.visual_match.enabled' => true,
            'qds.enrichment.vlm.model_version' => 'gemini-3.5-flash',
            'qds.enrichment.vlm.pending_stale_hours' => 6,
        ]);
    }

    /** @return array{0: ContentItem, 1: VisualMatchRun} flagged latest run, in-window by default */
    private function flaggedPost(?CarbonImmutable $runCreatedAt = null): array
    {
        $account = PlatformAccount::factory()->for(Creator::factory())->create();
        $item = ContentItem::factory()->for($account, 'platformAccount')->create([
            'published_at' => CarbonImmutable::now()->subDays(2),
        ]);

        $run = VisualMatchRun::factory()->create([
            'content_item_id' => $item->id,
            'outcome' => VisualMatchOutcome::Review,
            'needs_verification' => true,
        ]);

        if ($runCreatedAt !== null) {
            // created_at is not fillable — direct write, like the model would age.
            DB::table('visual_match_runs')->where('id', $run->id)->update(['created_at' => $runCreatedAt]);
        }

        return [$item, $run];
    }

    /** In-window shipped post for DEF-021 discovery (no keyframes — discovery never looks at frames). */
    private function shippedPost(): ContentItem
    {
        $creator = Creator::factory()->create();
        $account = PlatformAccount::factory()->for($creator)->create();
        $item = ContentItem::factory()->for($account, 'platformAccount')->create([
            'published_at' => CarbonImmutable::now()->subDays(2),
        ]);

        $product = Product::factory()->create(['category' => null]);
        $campaign = SeedingCampaign::factory()->create([
            'status' => SeedingCampaignStatus::Active,
            'product_id' => $product->id,
        ]);
        Shipment::factory()->create([
            'seeding_campaign_id' => $campaign->id,
            'creator_id' => $creator->id,
            'product_id' => $product->id,
            'shipped_at' => CarbonImmutable::now()->subDays(5), // in the 60-day default window
        ]);

        return $item;
    }

    private function terminalRow(ContentItem $item, VisualMatchRun $anchor, string $modelVersion = 'gemini-3.5-flash'): VlmVerificationRun
    {
        return VlmVerificationRun::query()->create([
            'content_item_id' => $item->id,
            'visual_match_run_id' => $anchor->id,
            'correlation_id' => 'sweep-seed',
            'model_version' => $modelVersion,
            'trigger_reason' => VlmTriggerReason::ReviewBand,
            'priority' => Priority::Medium,
            'frames_sent' => 2,
            'attempts' => 1,
            'outcome' => VlmRunOutcome::Confirmed,
            'thresholds' => ['auto' => 0.85, 'review' => 0.60, 'margin' => 0.10],
            'latency_ms' => 900,
            'estimated_cost_micro_usd' => 30000,
        ]);
    }

    private function pendingRun(ContentItem $item, VisualMatchRun $anchor, int $attempts, int $ageHours): VlmVerificationRun
    {
        $run = VlmVerificationRun::query()->create([
            'content_item_id' => $item->id,
            'visual_match_run_id' => $anchor->id,
            'correlation_id' => 'sweep-pending-seed',
            'model_version' => 'gemini-3.5-flash',
            'trigger_reason' => VlmTriggerReason::ReviewBand,
            'priority' => Priority::Medium,
            'frames_sent' => 2,
            'attempts' => $attempts,
            'outcome' => VlmRunOutcome::Pending,
            'thresholds' => ['auto' => 0.85, 'review' => 0.60, 'margin' => 0.10],
            'latency_ms' => 0,
            'estimated_cost_micro_usd' => 30000 * $attempts,
        ]);

        DB::table('vlm_verification_runs')->where('id', $run->id)->update([
            'created_at' => CarbonImmutable::now()->subHours($ageHours),
            'updated_at' => CarbonImmutable::now()->subHours($ageHours),
        ]);

        return $run;
    }

    public function test_vlm_switch_off_exits_quietly(): void
    {
        config(['qds.enrichment.vlm.enabled' => false]);
        $this->flaggedPost();

        $this->artisan('qds:vlm-verify')
            ->expectsOutputToContain('VLM verification is disabled')
            ->assertSuccessful();

        Queue::assertNothingPushed();
        $this->assertDatabaseCount('vlm_verification_runs', 0);
    }

    public function test_visual_match_switch_off_exits_quietly(): void
    {
        config(['qds.enrichment.visual_match.enabled' => false]);
        $this->flaggedPost();

        $this->artisan('qds:vlm-verify')
            ->expectsOutputToContain('requires visual matching')
            ->assertSuccessful();

        Queue::assertNothingPushed();
        $this->assertDatabaseCount('vlm_verification_runs', 0);
    }

    public function test_dispatches_latest_flagged_unconsumed_runs_only(): void
    {
        [$eligible] = $this->flaggedPost();

        // Consumed: a terminal row at the current model version blocks.
        [$consumedItem, $consumedRun] = $this->flaggedPost();
        $this->terminalRow($consumedItem, $consumedRun);

        // Superseded: a NEWER unflagged run on the same post outranks the flag.
        [$supersededItem] = $this->flaggedPost();
        VisualMatchRun::factory()->create([
            'content_item_id' => $supersededItem->id,
            'outcome' => VisualMatchOutcome::NoMatch,
            'needs_verification' => false,
        ]);

        $this->artisan('qds:vlm-verify')->assertSuccessful();

        Queue::assertPushed(VlmVerificationJob::class, 1);
        Queue::assertPushed(VlmVerificationJob::class, function (VlmVerificationJob $job) use ($eligible): bool {
            // correlationId null = the frozen sweep-catchup convention.
            return $job->targetType === 'content'
                && $job->targetId === $eligible->id
                && $job->correlationId === null;
        });
    }

    public function test_model_version_bump_reopens_consumed_anchors(): void
    {
        [$item, $run] = $this->flaggedPost();
        $this->terminalRow($item, $run, 'gemini-3.5-flash');
        config(['qds.enrichment.vlm.model_version' => 'gemini-4-flash']);

        $this->artisan('qds:vlm-verify')->assertSuccessful();

        Queue::assertPushed(VlmVerificationJob::class, fn (VlmVerificationJob $job): bool => $job->targetId === $item->id);
    }

    public function test_a_fresh_pending_row_does_not_block_redispatch_and_is_not_finalized(): void
    {
        [$item, $run] = $this->flaggedPost();
        $this->pendingRun($item, $run, attempts: 1, ageHours: 1); // younger than pending_stale_hours

        $this->artisan('qds:vlm-verify')->assertSuccessful();

        Queue::assertPushed(VlmVerificationJob::class, fn (VlmVerificationJob $job): bool => $job->targetId === $item->id);
        $this->assertDatabaseHas('vlm_verification_runs', ['content_item_id' => $item->id, 'outcome' => 'pending']);
    }

    public function test_days_window_bounds_the_catchup(): void
    {
        $this->flaggedPost(CarbonImmutable::now()->subDays(40));

        $this->artisan('qds:vlm-verify')->assertSuccessful();
        Queue::assertNothingPushed();

        $this->artisan('qds:vlm-verify', ['--days' => 60])->assertSuccessful();
        Queue::assertPushed(VlmVerificationJob::class, 1);
    }

    public function test_discovery_records_unverifiable_rows_and_never_calls_gemini(): void
    {
        // (a) shipped, in-window, NO visual run at all → unverifiable:no-run.
        $noRun = $this->shippedPost();

        // (b) shipped, latest run skipped (budget) → unverifiable:skipped-run.
        $skipped = $this->shippedPost();
        VisualMatchRun::factory()->create([
            'content_item_id' => $skipped->id,
            'outcome' => VisualMatchOutcome::SkippedBudget,
            'needs_verification' => false, // C's recorder guard guarantees this
        ]);

        // (c) shipped but a REAL attempt exists (no_match) — we looked; not discovery.
        $looked = $this->shippedPost();
        VisualMatchRun::factory()->create([
            'content_item_id' => $looked->id,
            'outcome' => VisualMatchOutcome::NoMatch,
            'needs_verification' => false,
        ]);

        // (d) no shipment — never discovery.
        $account = PlatformAccount::factory()->for(Creator::factory())->create();
        $unshipped = ContentItem::factory()->for($account, 'platformAccount')->create([
            'published_at' => CarbonImmutable::now()->subDays(2),
        ]);

        $this->artisan('qds:vlm-verify')->assertSuccessful();

        $this->assertDatabaseHas('vlm_verification_runs', [
            'content_item_id' => $noRun->id,
            'visual_match_run_id' => null,
            'outcome' => 'unverifiable',
            'trigger_reason' => 'unverifiable:no-run',
            'attempts' => 0,
        ]);
        $this->assertDatabaseHas('vlm_verification_runs', [
            'content_item_id' => $skipped->id,
            'visual_match_run_id' => null,
            'outcome' => 'unverifiable',
            'trigger_reason' => 'unverifiable:skipped-run',
        ]);
        $this->assertDatabaseMissing('vlm_verification_runs', ['content_item_id' => $looked->id]);
        $this->assertDatabaseMissing('vlm_verification_runs', ['content_item_id' => $unshipped->id]);

        Queue::assertNothingPushed(); // discovery never dispatches
        Http::assertNothingSent();    // and never talks to Gemini
    }

    public function test_discovery_is_deduplicated_across_sweeps(): void
    {
        $this->shippedPost();

        $this->artisan('qds:vlm-verify')->assertSuccessful();
        $this->artisan('qds:vlm-verify')->assertSuccessful();

        $this->assertDatabaseCount('vlm_verification_runs', 1);
    }

    public function test_stale_pending_rows_are_finalized_or_deleted_before_catchup(): void
    {
        [$billedItem, $billedRun] = $this->flaggedPost();
        $this->pendingRun($billedItem, $billedRun, attempts: 2, ageHours: 7);

        [$unbilledItem, $unbilledRun] = $this->flaggedPost();
        $this->pendingRun($unbilledItem, $unbilledRun, attempts: 0, ageHours: 7);

        $this->artisan('qds:vlm-verify')->assertSuccessful();

        // Billed → consumed as skipped_provider; catch-up must NOT re-dispatch it.
        $this->assertDatabaseHas('vlm_verification_runs', [
            'content_item_id' => $billedItem->id,
            'outcome' => 'skipped_provider',
            'attempts' => 2,
            'rejection_reason' => 'stale-pending',
        ]);
        Queue::assertNotPushed(VlmVerificationJob::class, fn (VlmVerificationJob $job): bool => $job->targetId === $billedItem->id);

        // Unbilled → deleted (unconsumed); catch-up re-dispatches it.
        $this->assertDatabaseMissing('vlm_verification_runs', ['content_item_id' => $unbilledItem->id]);
        Queue::assertPushed(VlmVerificationJob::class, fn (VlmVerificationJob $job): bool => $job->targetId === $unbilledItem->id);
    }

    public function test_dry_run_reports_without_writing_or_dispatching(): void
    {
        $this->flaggedPost();
        $this->shippedPost();
        [$staleItem, $staleRun] = $this->flaggedPost();
        $this->pendingRun($staleItem, $staleRun, attempts: 1, ageHours: 7);

        $this->artisan('qds:vlm-verify', ['--dry-run' => true])
            // Stale row NOT finalized in dry-run, so BOTH flagged posts still count as dispatchable.
            ->expectsOutputToContain('would finalize 1')
            ->expectsOutputToContain('dispatch 2 job(s)')
            ->expectsOutputToContain('record 1 unverifiable post(s)')
            ->expectsOutputToContain('Dry run — nothing executed.')
            ->assertSuccessful();

        Queue::assertNothingPushed();
        $this->assertDatabaseMissing('vlm_verification_runs', ['outcome' => 'unverifiable']);
        $this->assertDatabaseHas('vlm_verification_runs', ['content_item_id' => $staleItem->id, 'outcome' => 'pending']);
    }

    public function test_tenant_option_scopes_the_sweep(): void
    {
        $this->flaggedPost();

        $other = Tenant::factory()->create(['name' => 'Other Tenant']);
        /** @var array{0: ContentItem, 1: VisualMatchRun} $made */
        $made = $this->withTenant($other, fn (): array => $this->flaggedPost());
        $otherItem = $made[0];

        $this->artisan('qds:vlm-verify', ['--tenant' => $other->id])->assertSuccessful();

        Queue::assertPushed(VlmVerificationJob::class, 1);
        Queue::assertPushed(VlmVerificationJob::class, fn (VlmVerificationJob $job): bool => $job->targetId === $otherItem->id);
    }

    public function test_discovery_rows_are_stamped_with_the_owning_tenant(): void
    {
        $other = Tenant::factory()->create(['name' => 'Other Tenant']);
        $otherItem = $this->withTenant($other, fn (): ContentItem => $this->shippedPost());

        $this->artisan('qds:vlm-verify')->assertSuccessful();

        $this->assertDatabaseHas('vlm_verification_runs', [
            'content_item_id' => $otherItem->id,
            'tenant_id' => $other->id,
            'outcome' => 'unverifiable',
        ]);
        $this->assertDatabaseMissing('vlm_verification_runs', ['tenant_id' => $this->defaultTenant->id]);
    }
}
