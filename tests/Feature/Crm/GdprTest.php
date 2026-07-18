<?php

namespace Tests\Feature\Crm;

use App\Modules\CRM\Models\CommunicationLog;
use App\Modules\CRM\Models\Contact;
use App\Modules\CRM\Models\Creator;
use App\Modules\CRM\Models\PlatformAccount;
use App\Modules\CRM\Services\Gdpr\CreatorDataExporter;
use App\Modules\CRM\Services\Gdpr\CreatorEraser;
use App\Modules\Monitoring\Models\ContentItem;
use App\Modules\Monitoring\Models\EmvConfiguration;
use App\Modules\Monitoring\Models\EmvResult;
use App\Modules\Monitoring\Models\Mention;
use App\Modules\Monitoring\Models\MetricSnapshot;
use App\Modules\Monitoring\Models\MonitoredSubject;
use App\Modules\Monitoring\Models\ReachConfiguration;
use App\Modules\Monitoring\Models\ReachResult;
use App\Modules\Monitoring\Models\ReviewAction;
use App\Modules\Monitoring\Models\SentimentAnalysis;
use App\Modules\Monitoring\Models\Story;
use App\Platform\Enrichment\Support\ReviewDecision;
use App\Shared\Enums\MetricTier;
use App\Shared\ValueObjects\MetricValue;
use App\Shared\ValueObjects\ReachEstimate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * GDPR tooling (P4 hardening, DP-005): data-subject export contains every
 * personal-data category; erasure removes ALL of it — including the
 * append-only monitoring history and analytics rows the ordinary CRM
 * delete refuses to touch — plus archived files; retention enforcement
 * prunes over-age communication logs.
 */
class GdprTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('media');
        Storage::fake('exports');
    }

    /** @return array{creator: Creator, account: PlatformAccount, content: ContentItem, story: Story, reachResult: ReachResult} */
    private function seedCreatorWithFullFootprint(): array
    {
        $creator = Creator::factory()->create(['display_name' => 'Erika Musterfrau']);
        $account = PlatformAccount::factory()->create([
            'creator_id' => $creator->id,
            'handle' => 'erika.styles',
        ]);

        Contact::factory()->create([
            'creator_id' => $creator->id,
            'email' => 'erika@example.de',
            'postal_address' => 'Musterstraße 1, Berlin',
        ]);
        CommunicationLog::factory()->create(['creator_id' => $creator->id]);

        $subject = MonitoredSubject::factory()->create(['creator_id' => $creator->id]);
        $content = ContentItem::factory()->create(['platform_account_id' => $account->id]);
        $story = Story::factory()->create([
            'platform_account_id' => $account->id,
            'media_url' => 'stories/instagram/'.$account->id.'/story-1.mp4',
        ]);
        Storage::disk('media')->put((string) $story->media_url, 'FAKE-BYTES');

        Mention::factory()->create([
            'monitored_subject_id' => $subject->id,
            'content_item_id' => $content->id,
            'story_id' => null,
        ]);

        // Append-only history rows — the erasure gate must open for these.
        MetricSnapshot::factory()->create(['platform_account_id' => $account->id]);
        MetricSnapshot::factory()->contentLevel()->create(['content_item_id' => $content->id]);

        // Estimated reach (REQ-M1-006, ADR-0022) — append-only like emv_results
        // and FK-restricted to content_items; the eraser must purge it too.
        $reachConfig = ReachConfiguration::factory()->active()->create();
        $reachResult = ReachResult::query()->create([
            'content_item_id' => $content->id,
            'reach_configuration_id' => $reachConfig->id,
            'formula_version' => $reachConfig->formula_version,
            'value' => new ReachEstimate(1000.0, MetricTier::Estimated, 'qds-estimated-reach v1'),
            'inputs' => ['views' => 800, 'followers' => 400],
            'calculated_at' => now(),
        ]);

        // Analytics star-schema rows keyed by the creator (no FKs — loaders
        // stamp ids; minimal columns satisfy the NOT NULLs).
        DB::table('dim_creator')->insert([
            'tenant_id' => $this->defaultTenant->id,
            'creator_id' => $creator->id,
            'display_name' => $creator->display_name,
            'updated_at' => now(),
        ]);
        DB::table('fact_creator_account')->insert([
            'tenant_id' => $this->defaultTenant->id,
            'date_key' => now()->toDateString(),
            'metric_snapshot_id' => 999_001,
            'platform_account_id' => $account->id,
            'creator_id' => $creator->id,
            'platform' => 'INSTAGRAM',
            'captured_at' => now(),
        ]);

        return ['creator' => $creator, 'account' => $account, 'content' => $content, 'story' => $story, 'reachResult' => $reachResult];
    }

    public function test_export_contains_all_personal_data_categories(): void
    {
        ['creator' => $creator] = $this->seedCreatorWithFullFootprint();

        $data = app(CreatorDataExporter::class)->export($creator);

        $this->assertSame('Erika Musterfrau', $data['data_subject']['display_name']);
        $this->assertSame('erika@example.de', $data['contacts'][0]['email']);
        $this->assertSame('Musterstraße 1, Berlin', $data['contacts'][0]['postal_address']);
        $this->assertCount(1, $data['communication_logs']);
        $this->assertSame('erika.styles', $data['platform_accounts'][0]['handle']);
        $this->assertCount(1, $data['monitoring']['content_items']);
        $this->assertCount(1, $data['monitoring']['stories']);
        $this->assertSame(2, $data['monitoring']['metric_snapshot_count']);
    }

    public function test_export_command_writes_json_to_the_private_exports_disk(): void
    {
        ['creator' => $creator] = $this->seedCreatorWithFullFootprint();

        $this->artisan('qds:gdpr-export-creator', ['creator' => $creator->id])
            ->assertSuccessful();

        // ADR-0019: dossiers land under the owning tenant's prefix.
        $files = Storage::disk('exports')->files("tenants/{$creator->tenant_id}/gdpr");
        $this->assertCount(1, $files);

        $decoded = json_decode((string) Storage::disk('exports')->get($files[0]), true);
        $this->assertSame('erika@example.de', $decoded['contacts'][0]['email']);
    }

    public function test_erasure_deletes_a_previously_generated_export_dossier(): void
    {
        ['creator' => $creator] = $this->seedCreatorWithFullFootprint();

        // A dossier was generated before the erasure request.
        $this->artisan('qds:gdpr-export-creator', ['creator' => $creator->id])->assertSuccessful();
        $before = Storage::disk('exports')->files("tenants/{$creator->tenant_id}/gdpr");
        $this->assertCount(1, $before);

        app(CreatorEraser::class)->erase($creator);

        // The richest single PII artifact must be purged synchronously with
        // the erasure, not left for the daily retention sweep (review P4#1).
        Storage::disk('exports')->assertMissing($before[0]);
        $this->assertCount(0, Storage::disk('exports')->files("tenants/{$creator->tenant_id}/gdpr"));
    }

    public function test_erasure_removes_all_rows_history_and_files(): void
    {
        ['creator' => $creator, 'account' => $account, 'content' => $content, 'story' => $story, 'reachResult' => $reachResult]
            = $this->seedCreatorWithFullFootprint();

        // An unrelated creator must be untouched.
        $other = PlatformAccount::factory()->create();
        MetricSnapshot::factory()->create(['platform_account_id' => $other->id]);

        app(CreatorEraser::class)->erase($creator);

        $this->assertDatabaseMissing('creators', ['id' => $creator->id]);
        $this->assertDatabaseMissing('platform_accounts', ['id' => $account->id]);
        $this->assertDatabaseMissing('contacts', ['creator_id' => $creator->id]);
        $this->assertDatabaseMissing('communication_logs', ['creator_id' => $creator->id]);
        $this->assertDatabaseMissing('monitored_subjects', ['creator_id' => $creator->id]);
        $this->assertDatabaseMissing('content_items', ['id' => $content->id]);
        $this->assertDatabaseMissing('stories', ['id' => $story->id]);
        $this->assertDatabaseMissing('mentions', ['content_item_id' => $content->id]);
        $this->assertDatabaseMissing('metric_snapshots', ['platform_account_id' => $account->id]);
        $this->assertDatabaseMissing('metric_snapshots', ['content_item_id' => $content->id]);
        $this->assertDatabaseMissing('reach_results', ['id' => $reachResult->id]);
        $this->assertSame(0, DB::table('dim_creator')->where('creator_id', $creator->id)->count());
        $this->assertSame(0, DB::table('fact_creator_account')->where('creator_id', $creator->id)->count());

        // Archived media is gone; the erasure left an identifier-only audit trail.
        Storage::disk('media')->assertMissing((string) $story->media_url);
        $this->assertDatabaseHas('audit_logs', ['action' => 'creator.gdpr_erased']);

        // The bystander's history survived.
        $this->assertSame(1, MetricSnapshot::query()->where('platform_account_id', $other->id)->count());
    }

    public function test_erasure_purges_emv_results_and_review_actions_history(): void
    {
        // C2 regression: emv_results and review_actions carry the append-only
        // qds_enrichment_append_only trigger, which — unlike the metric_snapshots
        // and analytics fact guards — never received the gdpr_erasure DELETE
        // gate. Any creator with EMV figures or a human review correction (both
        // routine for a monitored creator) could therefore NEVER be erased: the
        // DELETE raised inside the trigger and rolled the whole transaction back.
        // The full-footprint fixture masked it by seeding neither table.
        ['creator' => $creator, 'content' => $content] = $this->seedCreatorWithFullFootprint();

        $emvConfig = EmvConfiguration::factory()->active()->create();
        EmvResult::query()->create([
            'content_item_id' => $content->id,
            'emv_configuration_id' => $emvConfig->id,
            'formula_version' => $emvConfig->formula_version,
            'rate_card_version' => $emvConfig->rate_card_version,
            'currency' => 'EUR',
            'value' => new MetricValue(1234.0, MetricTier::Estimated, 'qds-emv v1'),
            'inputs' => [['metric' => 'likes', 'value' => 10]],
            'calculated_at' => now(),
        ]);

        // A human sentiment correction on the creator's content — an
        // append-only review_actions row anchored to erasable personal data.
        $sentiment = SentimentAnalysis::factory()->create(['content_item_id' => $content->id]);
        ReviewAction::query()->create([
            'reviewable_type' => $sentiment->getMorphClass(),
            'reviewable_id' => $sentiment->id,
            'action' => ReviewDecision::Correct,
            'original' => ['label' => 'POSITIVE'],
            'correction' => ['label' => 'NEGATIVE'],
            'reason' => 'mislabelled',
            'actor_id' => 1,
        ]);

        app(CreatorEraser::class)->erase($creator);

        // The erasure completes and removes the enrichment history too.
        $this->assertDatabaseMissing('creators', ['id' => $creator->id]);
        $this->assertSame(0, DB::table('emv_results')->where('content_item_id', $content->id)->count());
        $this->assertSame(0, DB::table('review_actions')->where('reviewable_id', $sentiment->id)->count());
    }

    public function test_append_only_guards_still_hold_outside_the_erasure_gate(): void
    {
        $snapshot = MetricSnapshot::factory()->create();

        $this->expectException(\Exception::class);
        DB::table('metric_snapshots')->where('id', $snapshot->id)->delete();
    }

    public function test_retention_command_prunes_old_communication_logs_when_configured(): void
    {
        config(['qds.gdpr.communication_log_retention_days' => 365]);

        $creator = Creator::factory()->create();
        $old = CommunicationLog::factory()->create([
            'creator_id' => $creator->id,
            'occurred_at' => now()->subDays(400),
        ]);
        $recent = CommunicationLog::factory()->create([
            'creator_id' => $creator->id,
            'occurred_at' => now()->subDays(10),
        ]);

        $this->artisan('qds:gdpr-enforce-retention')->assertSuccessful();

        $this->assertDatabaseMissing('communication_logs', ['id' => $old->id]);
        $this->assertDatabaseHas('communication_logs', ['id' => $recent->id]);
    }

    public function test_retention_disabled_by_default_keeps_all_communication_logs(): void
    {
        $creator = Creator::factory()->create();
        CommunicationLog::factory()->create([
            'creator_id' => $creator->id,
            'occurred_at' => now()->subYears(5),
        ]);

        $this->artisan('qds:gdpr-enforce-retention')->assertSuccessful();

        $this->assertSame(1, CommunicationLog::query()->count());
    }
}
