<?php

namespace Tests\Feature\Monitoring;

use App\Modules\Monitoring\Livewire\Operations\OperationsDashboard;
use App\Modules\Monitoring\Models\VisualMatchRun;
use App\Platform\AiBudget\Models\AiUsageCounter;
use App\Shared\Enums\RoleName;
use App\Shared\Enums\VisualMatchOutcome;
use App\Shared\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Spec §10 — the operations AI-spend panel. ADR-0019 posture (pinned by
 * CrossTenantAlertTest): this dashboard is viewed by TENANT staff, so
 * only the viewer's own usage is itemized; platform figures are
 * anonymous aggregate totals and no other tenant is ever named.
 */
class AiSpendPanelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
        $this->actingAs($this->makeUser(RoleName::Admin));
    }

    public function test_panel_itemizes_own_usage_and_anonymous_platform_totals(): void
    {
        $today = CarbonImmutable::now()->toDateString();

        AiUsageCounter::query()->create([
            'capability' => 'embedding',
            'tenant_id' => $this->defaultTenant->id,
            'usage_date' => $today,
            'units' => 1234,
            'estimated_cost_micro_usd' => 1234 * 120, // $0.14808
            'posts_processed' => 10,
            'posts_skipped_budget' => 7,
            'posts_skipped_no_candidates' => 5,
        ]);

        $foreign = $this->makeTenant('Tenant B');
        AiUsageCounter::query()->create([
            'capability' => 'embedding',
            'tenant_id' => $foreign->id,
            'usage_date' => $today,
            'units' => 999999,
            'estimated_cost_micro_usd' => 999999 * 120,
            'posts_processed' => 50,
        ]);

        Livewire::test(OperationsDashboard::class)
            ->assertSee('AI spend')
            ->assertSee('embedding')
            ->assertSee('1,234')          // own units today
            ->assertSee('$0.15')          // own month spend
            ->assertSee('$0.0148')        // avg cost per processed post
            ->assertSee('1,001,233')      // platform total INCLUDES the foreign tenant…
            ->assertDontSee('999,999')    // …but its individual figure never renders
            ->assertDontSee('Tenant B');  // and no tenant is ever named
    }

    public function test_new_d_capabilities_appear_as_panel_rows_automatically(): void
    {
        // The panel iterates config('qds.ai_budget.capabilities') — the
        // two sub-project D blocks must surface WITHOUT any dashboard
        // change, under the same own-vs-anonymous-platform posture that
        // the 'embedding' row is pinned to.
        $today = CarbonImmutable::now()->toDateString();

        AiUsageCounter::query()->create([
            'capability' => 'vlm_verification',
            'tenant_id' => $this->defaultTenant->id,
            'usage_date' => $today,
            'units' => 2345,
            'estimated_cost_micro_usd' => 2345 * 30000, // $70.35 at the §11 estimate
            'posts_processed' => 5,
        ]);

        $foreign = $this->makeTenant('Tenant B');
        AiUsageCounter::query()->create([
            'capability' => 'vlm_verification',
            'tenant_id' => $foreign->id,
            'usage_date' => $today,
            'units' => 111111,
            'estimated_cost_micro_usd' => 111111 * 30000,
        ]);

        Livewire::test(OperationsDashboard::class)
            ->assertSee('vlm_verification')
            ->assertSee('speech_transcription') // zero-usage row renders too — purely config-driven
            ->assertSee('2,345')                // own vlm units today
            ->assertSee('$70.35')               // own month spend at 30_000 micro-USD/unit
            ->assertSee('$14.0700')             // avg cost per processed post (70.35 / 5)
            ->assertSee('113,456')              // platform total INCLUDES the foreign tenant...
            ->assertDontSee('111,111')          // ...but its individual figure never renders
            ->assertDontSee('Tenant B');        // and no tenant is ever named
    }

    public function test_panel_aggregates_recent_visual_match_runs(): void
    {
        VisualMatchRun::factory()->create([
            'embedding_calls' => 9,
            'cache_hits' => 3,
            'frames_skipped_format' => 1,
            'frames_skipped_quality' => 2,
            'frames_deduped' => 3,
            'candidates_checked' => 4,
            'processing_ms' => 3000,
            'outcome' => VisualMatchOutcome::Matched,
        ]);
        VisualMatchRun::factory()->create([
            'embedding_calls' => 0,
            'cache_hits' => 0,
            'frames_skipped_format' => 0,
            'frames_skipped_quality' => 0,
            'frames_deduped' => 0,
            'candidates_checked' => 2,
            'processing_ms' => 1500,
            'outcome' => VisualMatchOutcome::SkippedBudget,
        ]);

        Livewire::test(OperationsDashboard::class)
            ->assertSee('Cache-hit rate')
            ->assertSee('25.0%')       // 3 cache hits of 12 embeddings needed
            ->assertSee('1 / 2 / 3')   // format / quality / dedup frame skips
            ->assertSee('Budget denials')
            ->assertSee('2,250 ms');   // average processing time
    }

    public function test_visual_match_aggregates_never_leak_when_no_tenant_context_is_active(): void
    {
        // A foreign tenant's runs exist and WOULD be summed by a bare
        // TenantScope no-op under a null context (platform/CLI rendering).
        // The panel must stay empty rather than silently aggregate them.
        $foreign = $this->makeTenant('Tenant B');

        $this->withTenant($foreign, fn () => VisualMatchRun::factory()->create([
            'embedding_calls' => 9,
            'cache_hits' => 3,
            'processing_ms' => 3000,
        ]));

        app(TenantContext::class)->runAs(null, function (): void {
            Livewire::test(OperationsDashboard::class)
                ->assertDontSee('Cache-hit rate')
                ->assertSee('No visual-match runs in the last 7 days.');
        });
    }

    public function test_provider_table_marks_gemini_embeddings_configured(): void
    {
        config([
            'services.apify.token' => null,
            'services.youtube.api_key' => null,
            'services.google_vision.api_key' => null,
            'services.google_speech.api_key' => null,
            'services.google_video_intelligence.api_key' => null,
            'services.google_embeddings.credentials_path' => null,
            'services.google_embeddings.project_id' => null,
        ]);

        Livewire::test(OperationsDashboard::class)
            ->assertSee('SRC-google-gemini-embeddings')
            ->assertDontSee('credentials set');

        config([
            'services.google_embeddings.credentials_path' => storage_path('test-sa.json'),
            'services.google_embeddings.project_id' => 'qds-embeddings-test',
        ]);

        Livewire::test(OperationsDashboard::class)->assertSee('credentials set');
    }
}
