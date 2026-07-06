<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\CRM\Models\Creator;
use App\Modules\CRM\Models\PlatformAccount;
use App\Modules\Monitoring\Livewire\Dashboard\CreatorsIndex;
use App\Modules\Monitoring\Models\ContentItem;
use App\Modules\Monitoring\Models\Mention;
use App\Modules\Monitoring\Models\MonitoredSubject;
use App\Modules\Monitoring\Models\RecognitionDetection;
use App\Modules\Monitoring\Models\ReviewAction;
use App\Modules\Monitoring\Models\Story;
use App\Platform\Analytics\Contracts\AnalyticsService;
use App\Platform\Enrichment\Contracts\EnrichmentService;
use App\Platform\Enrichment\Review\ReviewQueue;
use App\Platform\Enrichment\Review\ReviewService;
use App\Platform\Export\ExportManager;
use App\Platform\Export\ReportBuilder;
use App\Platform\Ingestion\Jobs\IngestContentJob;
use App\Platform\Ingestion\Jobs\IngestStoriesJob;
use App\Platform\Snapshots\DatabaseSnapshotScheduler;
use App\Platform\Snapshots\Jobs\CreateSnapshotsJob;
use App\Shared\Enums\ConfidenceLevel;
use App\Shared\Enums\ExportFormat;
use App\Shared\Enums\MentionType;
use App\Shared\Enums\MetricTier;
use App\Shared\Enums\MonitoredSubjectType;
use App\Shared\Enums\Platform;
use App\Shared\Enums\RoleName;
use App\Shared\Enums\VerificationStatus;
use App\Shared\ValueObjects\ConfidenceAssessment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Support\FakesProviderResponses;
use Tests\TestCase;

/**
 * The Module 1 delivery chain end-to-end over fakes (no live provider —
 * DP-005 synthetic data only):
 *
 * existing CRM creator → roster assignment → monitoring execution →
 * content/story persistence (+ Provenance) → metric snapshot → metric
 * calculation (tiers) → AI enrichment → low-confidence review → analyst
 * correction (DP-004) → rollup refresh (SVC-Analytics) → dashboard result
 * → export preserving tiers/confidence disclosures (SVC-Export).
 */
class EndToEndMonitoringFlowTest extends TestCase
{
    use FakesProviderResponses;
    use RefreshDatabase;

    public function test_full_monitoring_delivery_chain(): void
    {
        $this->seedRoles();
        $this->fakeProviderCredentials();
        Storage::fake('media');
        Storage::fake('exports');

        // 1. Existing CRM creator (Module 3 system of record) + roster
        //    assignment (MonitoredSubject type CREATOR — REQ-M1-001).
        $creator = Creator::factory()->create(['display_name' => 'Lena Fashion']);
        $account = PlatformAccount::factory()->create([
            'creator_id' => $creator->id,
            'platform' => Platform::Instagram,
            'handle' => 'styleicon.de',
        ]);
        MonitoredSubject::factory()->create([
            'subject_type' => MonitoredSubjectType::Creator->value,
            'creator_id' => $creator->id,
            'platforms' => [Platform::Instagram],
            'active' => true,
        ]);

        // 2. Monitoring execution against faked frozen providers.
        Http::fake([
            'api.apify.com/v2/acts/'.config('services.apify.actors.instagram_post').'/*' => Http::response($this->fixture('instagram-posts')),
            'api.apify.com/v2/acts/'.config('services.apify.actors.instagram_reel').'/*' => Http::response($this->fixture('instagram-reels')),
            'api.apify.com/v2/acts/'.config('services.apify.actors.instagram_story').'/*' => Http::response($this->fixture('instagram-stories')),
            'cdn.example/*' => Http::response('FAKE-MEDIA', 200, ['Content-Type' => 'image/jpeg']),
        ]);

        IngestContentJob::dispatchSync($account->id, null, 'corr-e2e');
        IngestStoriesJob::dispatchSync($account->id, null, 'corr-e2e-story');

        // 3. Content + story persisted with mandatory Provenance (DP-002).
        $this->assertGreaterThan(0, ContentItem::query()->count());
        $this->assertGreaterThan(0, Story::query()->count());

        $content = ContentItem::query()->orderBy('id')->firstOrFail();
        $this->assertNotEmpty($content->provenance->source);
        $this->assertStringStartsWith('SRC-', $content->provenance->source);

        // 4. Metric snapshot via SVC-SnapshotScheduler (ADR-0003).
        config(['qds.snapshots.enabled' => true]);
        (new CreateSnapshotsJob)->handle(app(DatabaseSnapshotScheduler::class));

        $this->assertGreaterThan(0, DB::table('metric_snapshots')->count());

        // 5. AI enrichment (SVC-EnrichmentAI) with a low-confidence
        //    recognition signal → the attribution stage yields an UNKNOWN
        //    mention at LOW confidence, AI_ASSESSED (DP-003).
        RecognitionDetection::factory()->create([
            'content_item_id' => $content->id,
            'story_id' => null,
            'detected_brand' => 'Maison Lumière',
            'assessment' => new ConfidenceAssessment(
                value: 'Maison Lumière',
                confidenceLevel: ConfidenceLevel::Low,
                signals: ['ocr-partial-match:0.41'],
                verificationStatus: VerificationStatus::AiAssessed,
            ),
        ]);

        config(['qds.enrichment.enabled' => true]);
        app(EnrichmentService::class)->enrich($content);

        $mention = Mention::query()->firstOrFail();
        $this->assertSame(MentionType::Unknown, $mention->mention_type);
        $this->assertSame(ConfidenceLevel::Low, $mention->classification->confidenceLevel);
        $this->assertSame(VerificationStatus::AiAssessed, $mention->classification->verificationStatus);

        // 6. The low-confidence output routes to the review queue (DP-004)…
        $queued = app(ReviewQueue::class)->items(['kind' => 'mention']);
        $this->assertTrue($queued->contains(fn (array $i): bool => $i['item']->is($mention)));

        // …and an analyst corrects it. Organic is never asserted as fact,
        // so the honest correction is LIKELY_ORGANIC.
        /** @var User $analyst */
        $analyst = $this->makeUser(RoleName::Analyst);
        app(ReviewService::class)->correct(
            $mention,
            ['mention_type' => MentionType::LikelyOrganic->value],
            $analyst,
            'No seeding/paid record; caption reads organic.',
        );

        $mention->refresh();
        $this->assertSame(MentionType::LikelyOrganic, $mention->mention_type);
        $this->assertSame(VerificationStatus::HumanCorrected, $mention->classification->verificationStatus);
        $this->assertSame(1, ReviewAction::query()->count());

        // 7. Rollup refresh (SVC-Analytics): facts load append-only, the
        //    creator rollup carries the observed PUBLIC sums.
        app(AnalyticsService::class)->refreshRollups();

        $this->assertGreaterThan(0, DB::table('fact_content_metric')->count());
        $this->assertSame(1, DB::table('fact_mention')->count());

        $bucket = DB::table('rollup_creator_by_period')
            ->where('grain', 'month')
            ->where('creator_id', $creator->id)
            ->orderByDesc('bucket_start')
            ->first();
        $this->assertNotNull($bucket);
        $this->assertNotNull($bucket->views_sum);

        // 8. Dashboard result: the roster list shows the creator with its
        //    rollup-backed stats (server-side rendering).
        $this->actingAs($analyst);
        Livewire::test(CreatorsIndex::class)->assertSee('Lena Fashion');

        // 9. Export preserving tiers and confidence/deferral disclosures.
        $job = app(ExportManager::class)
            ->request($analyst, ReportBuilder::MONITORING_SUMMARY, ExportFormat::Csv, ['grain' => 'month'])
            ->fresh();

        $csv = Storage::disk('exports')->get($job->file_path);
        $this->assertStringContainsString('Lena Fashion', $csv);
        $this->assertStringContainsString('[PUBLIC]', $csv);
        $this->assertStringContainsString('[DERIVED]', $csv);
        $this->assertStringContainsString('[ESTIMATED]', $csv);
        $this->assertStringContainsString('DEF-003', $csv);

        // The tier vocabulary used everywhere is the canonical closed set.
        $this->assertSame(
            ['PUBLIC', 'DERIVED', 'ESTIMATED', 'CONFIRMED'],
            array_map(fn (MetricTier $t) => $t->value, MetricTier::cases()),
        );
    }
}
