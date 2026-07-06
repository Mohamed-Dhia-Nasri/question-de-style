<?php

namespace Tests\Feature\Enrichment;

use App\Modules\CRM\Models\Brand;
use App\Modules\CRM\Models\Campaign;
use App\Modules\CRM\Models\Creator;
use App\Modules\CRM\Models\PlatformAccount;
use App\Modules\Monitoring\Models\ContentHashtag;
use App\Modules\Monitoring\Models\ContentItem;
use App\Modules\Monitoring\Models\EmvConfiguration;
use App\Modules\Monitoring\Models\EmvResult;
use App\Modules\Monitoring\Models\HashtagList;
use App\Modules\Monitoring\Models\Mention;
use App\Modules\Monitoring\Models\MonitoredSubject;
use App\Modules\Monitoring\Models\RecognitionDetection;
use App\Modules\Monitoring\Models\ReviewAction;
use App\Platform\Enrichment\Attribution\AttributionService;
use App\Platform\Enrichment\Recognition\MediaFetcher;
use App\Platform\Enrichment\Support\HashtagScope;
use App\Platform\Ingestion\SourceRegistry;
use App\Shared\Enums\ConfidenceLevel;
use App\Shared\Enums\MentionType;
use App\Shared\Enums\MetricTier;
use App\Shared\Enums\Platform;
use App\Shared\Enums\VerificationStatus;
use App\Shared\ValueObjects\ConfidenceAssessment;
use App\Shared\ValueObjects\MetricValue;
use App\Shared\ValueObjects\Provenance;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression coverage for the deep-review hardening fixes
 * (reviews/REVIEW-module1-enrichment-2026-07-05.md §9): rejected-hashtag
 * evidence inversion, stale-mention retraction, recognition re-detection
 * after human correction, the SSRF media guard, and DB-level append-only
 * enforcement.
 */
class EnrichmentHardeningTest extends TestCase
{
    use RefreshDatabase;

    private Creator $creator;

    private MonitoredSubject $subject;

    private ContentItem $content;

    protected function setUp(): void
    {
        parent::setUp();

        $this->creator = Creator::factory()->create();

        $account = PlatformAccount::factory()
            ->forCreator($this->creator)
            ->onPlatform(Platform::Instagram)
            ->create();

        $this->subject = MonitoredSubject::factory()->create([
            'creator_id' => $this->creator->id,
            'platforms' => [Platform::Instagram],
            'active' => true,
        ]);

        $this->content = ContentItem::factory()->create([
            'platform_account_id' => $account->id,
            'platform' => Platform::Instagram,
            'published_at' => CarbonImmutable::parse('2026-06-10 12:00:00'),
        ]);
    }

    /**
     * Finding #1/#6: rejecting an ambiguous hashtag must NOT re-promote its
     * conflicting matches into attribution — the mention stays UNKNOWN, it
     * does not become LIKELY_ORGANIC/SEEDED (DP-004).
     */
    public function test_rejected_ambiguous_hashtag_carries_no_attribution_evidence(): void
    {
        $brandA = Brand::factory()->create();
        $campaignB = Campaign::factory()->create();

        $listA = HashtagList::factory()->create([
            'scope' => HashtagScope::Brand,
            'brand_id' => $brandA->id,
            'campaign_id' => null,
            'hashtag' => '#glow',
            'normalized' => 'glow',
        ]);
        $listB = HashtagList::factory()->forCampaign()->create([
            'campaign_id' => $campaignB->id,
            'hashtag' => '#glow',
            'normalized' => 'glow',
        ]);

        // Ambiguous extracted hashtag matching both lists, already resolved
        // to NONE by a reviewer (reject): resolved_at set, list id null.
        $hashtag = ContentHashtag::factory()->create([
            'content_item_id' => $this->content->id,
            'original' => '#glow',
            'normalized' => 'glow',
            'matches' => [
                ['hashtag_list_id' => $listA->id, 'scope' => 'BRAND', 'campaign_id' => null, 'brand_id' => $brandA->id, 'product_label' => null],
                ['hashtag_list_id' => $listB->id, 'scope' => 'CAMPAIGN', 'campaign_id' => $campaignB->id, 'brand_id' => null, 'product_label' => null],
            ],
            'is_ambiguous' => false,
            'resolved_hashtag_list_id' => null,
            'resolved_at' => CarbonImmutable::now(),
        ]);

        $mentions = app(AttributionService::class)->enrich($this->content);

        // No relevance evidence survives → no mention is created at all
        // (a rejected hashtag contributes nothing, not two targeted matches).
        $this->assertSame([], $mentions);
        $this->assertSame(0, Mention::query()->count());
    }

    /**
     * Finding #3: when the sole supporting evidence is human-rejected and the
     * classifier now returns null, a pre-existing AI SEEDED mention must be
     * retracted to UNKNOWN/LOW so it re-enters the review queue (DP-004),
     * not left as a stale, invisible SEEDED claim.
     */
    public function test_stale_ai_mention_is_retracted_when_evidence_disappears(): void
    {
        $mention = Mention::factory()->create([
            'monitored_subject_id' => $this->subject->id,
            'content_item_id' => $this->content->id,
            'story_id' => null,
            'mention_type' => MentionType::Seeded,
            'classification' => new ConfidenceAssessment(
                MentionType::Seeded->value,
                ConfidenceLevel::Medium,
                ['shipment-record:1', 'recognition:LOGO:X:MEDIUM'],
                VerificationStatus::AiAssessed,
            ),
        ]);

        // No evidence now exists for the content → classify() returns null.
        $result = app(AttributionService::class)->enrich($this->content);

        $this->assertCount(1, $result);

        $fresh = $mention->fresh();
        $this->assertSame(MentionType::Unknown, $fresh->mention_type);
        $this->assertSame(ConfidenceLevel::Low, $fresh->classification->confidenceLevel);
        $this->assertContains('evidence-retracted', $fresh->classification->signals);
        // Retracted to LOW/AI_ASSESSED → visible again in the review queue.
        $this->assertTrue($fresh->classification->needsHumanReview());
    }

    /** A human-corrected mention is never retracted, even with no evidence (DP-004). */
    public function test_human_corrected_mention_is_not_retracted(): void
    {
        $mention = Mention::factory()->create([
            'monitored_subject_id' => $this->subject->id,
            'content_item_id' => $this->content->id,
            'story_id' => null,
            'mention_type' => MentionType::Seeded,
            'classification' => new ConfidenceAssessment(
                MentionType::Seeded->value,
                ConfidenceLevel::High,
                ['manual-confirmation:shipment #9'],
                VerificationStatus::HumanCorrected,
            ),
        ]);

        app(AttributionService::class)->enrich($this->content);

        $this->assertSame(MentionType::Seeded, $mention->fresh()->mention_type);
    }

    /**
     * Finding #2: a human-corrected recognition brand is NOT re-created as a
     * fresh AI row when the same raw provider label is detected again — the
     * upsert keys on the immutable provider_label (DP-004).
     */
    public function test_corrected_recognition_is_not_re_detected(): void
    {
        $detection = RecognitionDetection::factory()->create([
            'content_item_id' => $this->content->id,
            'story_id' => null,
            'provider_label' => 'Addias',
            'detected_brand' => 'Adidas',
            'assessment' => new ConfidenceAssessment(
                'Adidas',
                ConfidenceLevel::High,
                ['logo-match-score:0.94', 'human-correction'],
                VerificationStatus::HumanCorrected,
            ),
        ]);

        // Simulate a re-detection of the same raw provider label via the
        // upsert identity used by RecognitionService::persist.
        $again = RecognitionDetection::query()->firstOrNew([
            'content_item_id' => $this->content->id,
            'recognition_type' => $detection->recognition_type,
            'provider_label' => 'Addias',
        ]);

        $this->assertTrue($again->exists);
        $this->assertSame($detection->id, $again->id);
        $this->assertSame('Adidas', $again->detected_brand);
        $this->assertSame(1, RecognitionDetection::query()->count());
    }

    /** Finding #4: the SSRF guard refuses private/loopback/link-local hosts. */
    public function test_media_fetcher_refuses_internal_hosts(): void
    {
        $fetcher = app(MediaFetcher::class);

        $this->assertNull($fetcher->fromPublicUrl('http://169.254.169.254/latest/meta-data/'));
        $this->assertNull($fetcher->fromPublicUrl('http://127.0.0.1/secret'));
        $this->assertNull($fetcher->fromPublicUrl('http://10.0.0.5/internal'));
        $this->assertNull($fetcher->fromPublicUrl('http://192.168.1.1/router'));
        $this->assertNull($fetcher->fromPublicUrl('http://[::1]/loopback'));
        // Non-http scheme is refused before any resolution.
        $this->assertNull($fetcher->fromPublicUrl('file:///etc/passwd'));
    }

    /** Finding #8: append-only rows resist even query-builder writes (DB trigger). */
    public function test_review_actions_are_append_only_at_the_database(): void
    {
        $mention = Mention::factory()->create([
            'monitored_subject_id' => $this->subject->id,
            'content_item_id' => $this->content->id,
            'story_id' => null,
        ]);

        ReviewAction::query()->create([
            'reviewable_type' => $mention->getMorphClass(),
            'reviewable_id' => $mention->id,
            'action' => 'APPROVE',
            'original' => ['x' => 1],
            'user_id' => null,
            'actor_id' => 1,
        ]);

        // Bypasses model events — only the DB trigger stops it.
        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/append-only/');

        ReviewAction::query()->update(['reason' => 'tampered']);
    }

    public function test_emv_results_are_append_only_at_the_database(): void
    {
        $configuration = EmvConfiguration::factory()->active()->create();

        $result = EmvResult::query()->create([
            'content_item_id' => $this->content->id,
            'emv_configuration_id' => $configuration->id,
            'formula_version' => $configuration->formula_version,
            'rate_card_version' => $configuration->rate_card_version,
            'currency' => 'EUR',
            'value' => new MetricValue(10.0, MetricTier::Estimated, 'emv'),
            'inputs' => [],
            'calculated_at' => CarbonImmutable::now(),
        ]);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/append-only/');

        EmvResult::query()->whereKey($result->id)->update(['currency' => 'USD']);
    }

    /**
     * Finding #5: the DB refuses a duplicate mention for the same
     * (subject, content) — the backstop behind the concurrent upsert.
     */
    public function test_duplicate_mention_is_rejected_by_the_unique_index(): void
    {
        $attributes = [
            'monitored_subject_id' => $this->subject->id,
            'content_item_id' => $this->content->id,
            'story_id' => null,
            'mention_type' => MentionType::Unknown,
            'classification' => new ConfidenceAssessment(
                MentionType::Unknown->value,
                ConfidenceLevel::Low,
                ['weak-signal'],
                VerificationStatus::AiAssessed,
            ),
            'provenance' => new Provenance(
                SourceRegistry::APIFY_INSTAGRAM_SCRAPER,
                CarbonImmutable::now(),
                'test-v1',
            ),
        ];

        Mention::query()->create($attributes);

        $this->expectException(QueryException::class);

        Mention::query()->create($attributes);
    }
}
