<?php

namespace Tests\Feature\Monitoring;

use App\Models\User;
use App\Modules\CRM\Models\Creator;
use App\Modules\CRM\Models\PlatformAccount;
use App\Modules\Monitoring\Livewire\Dashboard\ContentDetail;
use App\Modules\Monitoring\Livewire\Dashboard\CreatorsIndex;
use App\Modules\Monitoring\Models\ContentItem;
use App\Modules\Monitoring\Models\MonitoredSubject;
use App\Modules\Monitoring\Models\SentimentAnalysis;
use App\Shared\Enums\ConfidenceLevel;
use App\Shared\Enums\MonitoredSubjectType;
use App\Shared\Enums\Platform;
use App\Shared\Enums\RoleName;
use App\Shared\Enums\SentimentLabel;
use App\Shared\Enums\VerificationStatus;
use App\Shared\ValueObjects\ConfidenceAssessment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Module 1 dashboards (REQ-M1-012): server-side rendering, filters,
 * sorting whitelists, pagination, query-string state, unavailable states,
 * metric tiers, the review-correction loop (DP-004), and strict
 * CLIENT_VIEWER isolation from every internal surface (REQ-M3-012 rule).
 */
class DashboardScreensTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
    }

    private function analyst(): User
    {
        return $this->makeUser(RoleName::Analyst);
    }

    public function test_overview_renders_kpis_deferred_states_and_review_counts(): void
    {
        $creator = Creator::factory()->create();
        MonitoredSubject::factory()->create([
            'subject_type' => MonitoredSubjectType::Creator->value,
            'creator_id' => $creator->id,
            'active' => true,
        ]);

        $this->actingAs($this->analyst());

        $this->get('/monitoring')
            ->assertOk()
            ->assertSee('Monitored creators (roster)')
            ->assertSee('Pending reviews')
            ->assertSee('unavailable')          // deferred/unmeasured values
            ->assertSee('DEF-003', false)       // confirmed-reach deferral cited
            ->assertSee('DEF-005', false)       // comment analysis deferred
            ->assertSee('DEF-006', false);      // open-web listening deferred
    }

    public function test_creators_index_searches_sorts_and_rejects_unknown_sort_columns(): void
    {
        $subjectFor = function (Creator $creator): void {
            MonitoredSubject::factory()->create([
                'subject_type' => MonitoredSubjectType::Creator->value,
                'creator_id' => $creator->id,
                'active' => true,
            ]);
        };

        $anna = Creator::factory()->create(['display_name' => 'Anna Weber']);
        $zoe = Creator::factory()->create(['display_name' => 'Zoe Martin']);
        $subjectFor($anna);
        $subjectFor($zoe);

        // Not on the roster → never listed.
        Creator::factory()->create(['display_name' => 'Unmonitored Person']);

        $this->actingAs($this->analyst());

        Livewire::test(CreatorsIndex::class)
            ->assertSee('Anna Weber')
            ->assertSee('Zoe Martin')
            ->assertDontSee('Unmonitored Person')
            ->set('search', 'Anna')
            ->assertSee('Anna Weber')
            ->assertDontSee('Zoe Martin')
            ->set('search', '')
            ->call('sortBy', 'display_name')
            ->assertSet('sortField', 'display_name')
            // Unknown columns never reach ORDER BY (validated whitelist).
            ->call('sortBy', 'password')
            ->assertSet('sortField', 'display_name')
            ->set('sortField', 'users.email') // tampered query string
            ->assertOk();
    }

    public function test_creators_index_keeps_filter_state_in_the_query_string(): void
    {
        $this->actingAs($this->analyst());

        Livewire::withQueryParams(['q' => 'anna', 'platform' => 'INSTAGRAM'])
            ->test(CreatorsIndex::class)
            ->assertSet('search', 'anna')
            ->assertSet('platform', 'INSTAGRAM');
    }

    public function test_creator_detail_shows_unavailable_posting_frequency_and_demographics(): void
    {
        $creator = Creator::factory()->create();
        PlatformAccount::factory()->create([
            'creator_id' => $creator->id,
            'platform' => Platform::Instagram,
        ]);

        $this->actingAs($this->analyst());

        $this->get("/monitoring/creators/{$creator->id}")
            ->assertOk()
            ->assertSee($creator->display_name)
            ->assertSee('DEF-001', false)  // audience demographics deferred
            ->assertSee('DEF-002', false)  // contact auto-extraction deferred
            ->assertSee('ADR-0003', false); // history only from own-DB snapshots
    }

    public function test_content_detail_shows_tiered_metrics_and_correction_moves_to_human_corrected(): void
    {
        $account = PlatformAccount::factory()->create(['creator_id' => Creator::factory()->create()->id]);
        $content = ContentItem::factory()->create(['platform_account_id' => $account->id]);

        $sentiment = SentimentAnalysis::factory()->create([
            'content_item_id' => $content->id,
            'label' => SentimentLabel::Neutral,
            'assessment' => new ConfidenceAssessment(
                value: 'NEUTRAL',
                confidenceLevel: ConfidenceLevel::Low,
                signals: ['caption-only'],
                verificationStatus: VerificationStatus::AiAssessed,
            ),
        ]);

        $this->actingAs($this->analyst());

        $this->get("/monitoring/content/{$content->id}")
            ->assertOk()
            ->assertSee('Derived rates')
            ->assertSee('DEF-003', false)
            ->assertSee('unavailable');

        Livewire::test(ContentDetail::class, ['contentItem' => $content])
            ->set('correctionSentiment', SentimentLabel::Positive->value)
            ->set('reason', 'Clearly positive wording')
            ->call('correct', 'sentiment', $sentiment->id)
            ->assertHasNoErrors();

        $fresh = $sentiment->fresh();
        $this->assertSame(SentimentLabel::Positive, $fresh->label);
        $this->assertSame(VerificationStatus::HumanCorrected, $fresh->assessment->verificationStatus);
    }

    public function test_content_detail_rejects_assessments_of_other_content(): void
    {
        $account = PlatformAccount::factory()->create(['creator_id' => Creator::factory()->create()->id]);
        $content = ContentItem::factory()->create(['platform_account_id' => $account->id]);
        $otherContent = ContentItem::factory()->create(['platform_account_id' => $account->id]);

        $foreign = SentimentAnalysis::factory()->create(['content_item_id' => $otherContent->id]);

        $this->actingAs($this->analyst());

        Livewire::test(ContentDetail::class, ['contentItem' => $content])
            ->set('correctionSentiment', SentimentLabel::Positive->value)
            ->call('correct', 'sentiment', $foreign->id)
            ->assertStatus(404);
    }

    public function test_operations_screen_is_staff_only_and_renders_health_panels(): void
    {
        $this->actingAs($this->analyst());

        $this->get('/monitoring/operations')
            ->assertOk()
            ->assertSee('Provider health')
            ->assertSee('Queue depth')
            ->assertSee('Analytics rollups')
            ->assertSee('SRC-clockworks-tiktok-scraper');
    }

    public function test_client_viewer_is_denied_on_every_module1_surface(): void
    {
        $creator = Creator::factory()->create();
        $account = PlatformAccount::factory()->create(['creator_id' => $creator->id]);
        $content = ContentItem::factory()->create(['platform_account_id' => $account->id]);

        $this->actingAs($this->makeUser(RoleName::ClientViewer));

        foreach ([
            '/monitoring',
            '/monitoring/creators',
            "/monitoring/creators/{$creator->id}",
            "/monitoring/content/{$content->id}",
            '/monitoring/review',
            '/monitoring/emv',
            '/monitoring/exports',
            '/monitoring/operations',
            '/dashboard',
        ] as $url) {
            $this->get($url)->assertForbidden();
        }

        // The one surface a client viewer may reach: approved reports.
        $this->get('/reports')->assertOk();
    }
}
