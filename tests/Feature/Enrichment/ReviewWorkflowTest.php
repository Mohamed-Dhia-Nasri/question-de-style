<?php

namespace Tests\Feature\Enrichment;

use App\Models\User;
use App\Modules\CRM\Models\Creator;
use App\Modules\CRM\Models\PlatformAccount;
use App\Modules\Monitoring\Models\ContentHashtag;
use App\Modules\Monitoring\Models\ContentItem;
use App\Modules\Monitoring\Models\HashtagList;
use App\Modules\Monitoring\Models\Mention;
use App\Modules\Monitoring\Models\MonitoredSubject;
use App\Modules\Monitoring\Models\RecognitionDetection;
use App\Modules\Monitoring\Models\ReviewAction;
use App\Modules\Monitoring\Models\SentimentAnalysis;
use App\Platform\Enrichment\Attribution\AttributionService;
use App\Platform\Enrichment\Review\ReviewQueue;
use App\Platform\Enrichment\Review\ReviewService;
use App\Platform\Enrichment\Support\HashtagScope;
use App\Platform\Enrichment\Support\ReviewDecision;
use App\Shared\Audit\AuditLog;
use App\Shared\Enums\ConfidenceLevel;
use App\Shared\Enums\MentionType;
use App\Shared\Enums\RoleName;
use App\Shared\Enums\SentimentLabel;
use App\Shared\Enums\VerificationStatus;
use App\Shared\ValueObjects\ConfidenceAssessment;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use LogicException;
use Tests\TestCase;

/**
 * Human review workflow (DP-004): the queue is a query over the
 * ConfidenceAssessment envelope, every decision is authorized, snapshotted
 * into the append-only review_actions history, audit-logged, and — once a
 * human corrected a value — never overwritten by a later AI run.
 */
class ReviewWorkflowTest extends TestCase
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

    private function lowConfidenceSentiment(): SentimentAnalysis
    {
        return SentimentAnalysis::factory()->create([
            'label' => SentimentLabel::Positive,
            'assessment' => new ConfidenceAssessment(
                SentimentLabel::Positive->value,
                ConfidenceLevel::Low,
                ['caption-tone-weak'],
                VerificationStatus::AiAssessed,
            ),
        ])->fresh();
    }

    /** @return array{0: ContentHashtag, 1: HashtagList, 2: HashtagList} */
    private function ambiguousHashtag(): array
    {
        $brandList = HashtagList::factory()->create();
        $campaignList = HashtagList::factory()->forCampaign()->create();

        $hashtag = ContentHashtag::factory()->ambiguous()->create([
            'matches' => [
                [
                    'hashtag_list_id' => $brandList->id,
                    'scope' => HashtagScope::Brand->value,
                    'campaign_id' => null,
                    'brand_id' => $brandList->brand_id,
                    'product_label' => null,
                ],
                [
                    'hashtag_list_id' => $campaignList->id,
                    'scope' => HashtagScope::Campaign->value,
                    'campaign_id' => $campaignList->campaign_id,
                    'brand_id' => null,
                    'product_label' => null,
                ],
            ],
        ]);

        return [$hashtag, $brandList, $campaignList];
    }

    /** @param Collection<int, array{kind: string, item: Model}> $items */
    private function queueContains(Collection $items, string $kind, Model $model): bool
    {
        return $items->contains(
            fn (array $entry): bool => $entry['kind'] === $kind && $entry['item']->is($model),
        );
    }

    // ------------------------------------------------------------------
    // ReviewQueue
    // ------------------------------------------------------------------

    public function test_queue_serves_every_reviewable_kind_and_hides_reviewed_or_confident_rows(): void
    {
        $mention = Mention::factory()->lowConfidence()->create();
        $recognition = RecognitionDetection::factory()->lowConfidence()->create();
        $sentiment = $this->lowConfidenceSentiment();
        [$hashtag] = $this->ambiguousHashtag();

        // Never in the queue: confident or already human-handled rows.
        $mediumMention = Mention::factory()->create(); // MEDIUM, AI_ASSESSED
        $highRecognition = RecognitionDetection::factory()->create(); // HIGH, AI_ASSESSED
        $reviewedMention = Mention::factory()->create([
            'mention_type' => MentionType::Unknown,
            'classification' => new ConfidenceAssessment(
                MentionType::Unknown->value,
                ConfidenceLevel::Low,
                ['weak-signal', 'human-approved'],
                VerificationStatus::HumanReviewed,
            ),
        ]);
        $resolvedHashtag = ContentHashtag::factory()->ambiguous()->create([
            'resolved_at' => now(),
            'is_ambiguous' => false,
        ]);

        $items = app(ReviewQueue::class)->items();

        $this->assertTrue($this->queueContains($items, 'mention', $mention));
        $this->assertTrue($this->queueContains($items, 'recognition', $recognition));
        $this->assertTrue($this->queueContains($items, 'sentiment', $sentiment));
        $this->assertTrue($this->queueContains($items, 'hashtag', $hashtag));

        $this->assertFalse($this->queueContains($items, 'mention', $mediumMention));
        $this->assertFalse($this->queueContains($items, 'recognition', $highRecognition));
        $this->assertFalse($this->queueContains($items, 'mention', $reviewedMention));
        $this->assertFalse($this->queueContains($items, 'hashtag', $resolvedHashtag));

        $this->assertCount(4, $items);
    }

    public function test_queue_filters_by_kind_and_reports_per_kind_counts(): void
    {
        $mention = Mention::factory()->lowConfidence()->create();
        RecognitionDetection::factory()->lowConfidence()->create();
        $this->lowConfidenceSentiment();
        $this->ambiguousHashtag();

        $onlyMentions = app(ReviewQueue::class)->items(['kind' => 'mention']);

        $this->assertCount(1, $onlyMentions);
        $this->assertSame('mention', $onlyMentions->first()['kind']);
        $this->assertTrue($onlyMentions->first()['item']->is($mention));

        $this->assertSame(
            ['mention' => 1, 'recognition' => 1, 'sentiment' => 1, 'hashtag' => 1],
            app(ReviewQueue::class)->counts(),
        );
    }

    // ------------------------------------------------------------------
    // approve
    // ------------------------------------------------------------------

    public function test_approve_moves_the_mention_to_human_reviewed_and_records_the_original(): void
    {
        $reviewer = $this->analyst();
        $this->actingAs($reviewer);

        $mention = Mention::factory()->lowConfidence()->create()->fresh();

        $this->assertTrue($this->queueContains(app(ReviewQueue::class)->items(), 'mention', $mention));

        $action = app(ReviewService::class)->approve($mention, $reviewer);

        $mention->refresh();
        $this->assertSame(VerificationStatus::HumanReviewed, $mention->classification->verificationStatus);
        $this->assertContains('human-approved', $mention->classification->signals);
        $this->assertSame(MentionType::Unknown, $mention->mention_type);

        // The mention left the queue.
        $this->assertFalse($this->queueContains(app(ReviewQueue::class)->items(), 'mention', $mention));

        // Append-only history row with the ORIGINAL AI output snapshot.
        $this->assertSame(ReviewDecision::Approve, $action->action);
        $this->assertSame($mention->getMorphClass(), $action->reviewable_type);
        $this->assertSame($mention->id, $action->reviewable_id);
        $this->assertSame($mention->getMorphClass(), $action->original['type']);
        $this->assertSame($mention->id, $action->original['id']);
        $this->assertSame(MentionType::Unknown->value, $action->original['attributes']['mention_type']);

        $originalEnvelope = json_decode((string) $action->original['attributes']['classification'], true);
        $this->assertSame(VerificationStatus::AiAssessed->value, $originalEnvelope['verificationStatus']);
        $this->assertNotContains('human-approved', $originalEnvelope['signals']);

        $this->assertSame($reviewer->id, $action->user_id);
        $this->assertSame($reviewer->id, $action->actor_id);
        $this->assertNotNull($action->created_at);

        // Audit trail carries the decision.
        $audit = AuditLog::query()->where('action', 'enrichment.review.approve')->first();
        $this->assertNotNull($audit);
        $this->assertSame($mention->getMorphClass(), $audit->subject_type);
        $this->assertSame($mention->id, $audit->subject_id);
        $this->assertSame($action->id, $audit->context['review_action_id']);
    }

    // ------------------------------------------------------------------
    // correct / reject — mention
    // ------------------------------------------------------------------

    public function test_correct_mention_to_seeded_records_the_manual_confirmation_signal(): void
    {
        $reviewer = $this->analyst();
        $mention = Mention::factory()->lowConfidence()->create()->fresh();

        $action = app(ReviewService::class)->correct(
            $mention,
            ['mention_type' => 'SEEDED'],
            $reviewer,
            reason: 'shipment #99 confirmed by ops',
        );

        $mention->refresh();
        $this->assertSame(MentionType::Seeded, $mention->mention_type);
        $this->assertSame(MentionType::Seeded->value, $mention->classification->value);
        $this->assertSame(VerificationStatus::HumanCorrected, $mention->classification->verificationStatus);
        $this->assertContains('manual-confirmation:shipment #99 confirmed by ops', $mention->classification->signals);

        $this->assertSame(ReviewDecision::Correct, $action->action);
        $this->assertSame(['mention_type' => 'SEEDED'], $action->correction);
        $this->assertSame('shipment #99 confirmed by ops', $action->reason);
    }

    public function test_correct_mention_to_seeded_without_a_proving_reason_throws(): void
    {
        $reviewer = $this->analyst();
        $mention = Mention::factory()->lowConfidence()->create()->fresh();

        try {
            app(ReviewService::class)->correct($mention, ['mention_type' => 'SEEDED'], $reviewer);
            $this->fail('Correcting to SEEDED without a proving reason must throw (AC-M1-003).');
        } catch (InvalidArgumentException) {
            // expected
        }

        // Nothing changed, nothing recorded.
        $mention->refresh();
        $this->assertSame(VerificationStatus::AiAssessed, $mention->classification->verificationStatus);
        $this->assertSame(0, ReviewAction::query()->count());
    }

    // ------------------------------------------------------------------
    // reject — recognition (value nulled, never zero; record kept)
    // ------------------------------------------------------------------

    public function test_reject_recognition_nulls_the_assessed_value_and_keeps_the_record(): void
    {
        $reviewer = $this->analyst();
        $recognition = RecognitionDetection::factory()->lowConfidence()->create()->fresh();

        $action = app(ReviewService::class)->reject($recognition, $reviewer);

        $recognition->refresh();
        $this->assertNull($recognition->assessment->value);
        $this->assertContains('human-rejected', $recognition->assessment->signals);
        $this->assertSame(VerificationStatus::HumanCorrected, $recognition->assessment->verificationStatus);

        // Deletion of AI outputs is never allowed — the record remains.
        $this->assertDatabaseHas('recognition_detections', ['id' => $recognition->id]);
        $this->assertSame(ReviewDecision::Reject, $action->action);
    }

    // ------------------------------------------------------------------
    // correct / reject — sentiment
    // ------------------------------------------------------------------

    public function test_correct_sentiment_updates_label_and_envelope_together(): void
    {
        $reviewer = $this->analyst();
        $sentiment = $this->lowConfidenceSentiment();

        app(ReviewService::class)->correct($sentiment, ['label' => 'NEGATIVE'], $reviewer);

        $sentiment->refresh();
        $this->assertSame(SentimentLabel::Negative, $sentiment->label);
        $this->assertSame(SentimentLabel::Negative->value, $sentiment->assessment->value);
        $this->assertSame(VerificationStatus::HumanCorrected, $sentiment->assessment->verificationStatus);
        $this->assertContains('human-correction', $sentiment->assessment->signals);
    }

    public function test_reject_sentiment_falls_back_to_unknown(): void
    {
        $reviewer = $this->analyst();
        $sentiment = $this->lowConfidenceSentiment();

        app(ReviewService::class)->reject($sentiment, $reviewer);

        $sentiment->refresh();
        $this->assertSame(SentimentLabel::Unknown, $sentiment->label);
        $this->assertSame(SentimentLabel::Unknown->value, $sentiment->assessment->value);
        $this->assertSame(VerificationStatus::HumanCorrected, $sentiment->assessment->verificationStatus);
        $this->assertContains('human-rejected', $sentiment->assessment->signals);
    }

    // ------------------------------------------------------------------
    // hashtag ambiguity resolution
    // ------------------------------------------------------------------

    public function test_correct_hashtag_to_one_of_its_matches_resolves_the_ambiguity(): void
    {
        $reviewer = $this->analyst();
        [$hashtag, $brandList] = $this->ambiguousHashtag();

        app(ReviewService::class)->correct($hashtag, ['hashtag_list_id' => $brandList->id], $reviewer);

        $hashtag->refresh();
        $this->assertSame($brandList->id, $hashtag->resolved_hashtag_list_id);
        $this->assertSame($reviewer->id, $hashtag->resolved_by);
        $this->assertNotNull($hashtag->resolved_at);
        $this->assertFalse($hashtag->is_ambiguous);
        $this->assertFalse($hashtag->needsHumanReview());

        $this->assertFalse($this->queueContains(app(ReviewQueue::class)->items(), 'hashtag', $hashtag));
    }

    public function test_correct_hashtag_to_a_list_outside_its_matches_throws(): void
    {
        $reviewer = $this->analyst();
        [$hashtag] = $this->ambiguousHashtag();

        $unrelated = HashtagList::factory()->create();

        $this->expectException(InvalidArgumentException::class);

        app(ReviewService::class)->correct($hashtag, ['hashtag_list_id' => $unrelated->id], $reviewer);
    }

    public function test_reject_hashtag_resolves_it_with_no_list_entry(): void
    {
        $reviewer = $this->analyst();
        [$hashtag] = $this->ambiguousHashtag();

        app(ReviewService::class)->reject($hashtag, $reviewer);

        $hashtag->refresh();
        $this->assertNotNull($hashtag->resolved_at);
        $this->assertNull($hashtag->resolved_hashtag_list_id);
        $this->assertFalse($hashtag->is_ambiguous);
    }

    public function test_an_ambiguous_hashtag_cannot_be_approved_as_is(): void
    {
        $reviewer = $this->analyst();
        [$hashtag] = $this->ambiguousHashtag();

        $this->expectException(InvalidArgumentException::class);

        app(ReviewService::class)->approve($hashtag, $reviewer);
    }

    // ------------------------------------------------------------------
    // unresolved
    // ------------------------------------------------------------------

    public function test_unresolved_keeps_the_item_queued_and_records_the_look(): void
    {
        $reviewer = $this->analyst();
        $mention = Mention::factory()->lowConfidence()->create()->fresh();

        $action = app(ReviewService::class)->unresolved($mention, $reviewer, 'need shipment records from ops');

        $mention->refresh();
        $this->assertSame(VerificationStatus::AiAssessed, $mention->classification->verificationStatus);
        $this->assertTrue($this->queueContains(app(ReviewQueue::class)->items(), 'mention', $mention));

        $this->assertSame(ReviewDecision::Unresolved, $action->action);
        $this->assertSame('need shipment records from ops', $action->reason);
    }

    // ------------------------------------------------------------------
    // authorization
    // ------------------------------------------------------------------

    public function test_client_viewer_cannot_review_while_analyst_can(): void
    {
        $mention = Mention::factory()->lowConfidence()->create()->fresh();

        try {
            app(ReviewService::class)->approve($mention, $this->makeUser(RoleName::ClientViewer));
            $this->fail('CLIENT_VIEWER must never reach the review workflow.');
        } catch (AuthorizationException) {
            // expected
        }

        $mention->refresh();
        $this->assertSame(VerificationStatus::AiAssessed, $mention->classification->verificationStatus);
        $this->assertSame(0, ReviewAction::query()->count());

        // Analyst holds monitoring.manage — the same call succeeds.
        app(ReviewService::class)->approve($mention->fresh(), $this->analyst());

        $this->assertSame(VerificationStatus::HumanReviewed, $mention->refresh()->classification->verificationStatus);
    }

    // ------------------------------------------------------------------
    // append-only history
    // ------------------------------------------------------------------

    public function test_review_actions_are_append_only(): void
    {
        $reviewer = $this->analyst();
        $mention = Mention::factory()->lowConfidence()->create()->fresh();

        $action = app(ReviewService::class)->approve($mention, $reviewer);

        try {
            $action->update(['reason' => 'rewritten']);
            $this->fail('review_actions must reject updates (DP-004).');
        } catch (LogicException) {
            // expected
        }

        try {
            $action->delete();
            $this->fail('review_actions must reject deletes (DP-004).');
        } catch (LogicException) {
            // expected
        }

        $this->assertDatabaseHas('review_actions', ['id' => $action->id]);
        $this->assertNull($action->fresh()->reason);
    }

    // ------------------------------------------------------------------
    // human precedence over AI reprocessing
    // ------------------------------------------------------------------

    public function test_a_human_correction_survives_ai_reprocessing(): void
    {
        $reviewer = $this->analyst();

        // Wire creator → platform account → subject → content so the
        // attribution stage targets exactly this mention.
        $creator = Creator::factory()->create();
        $account = PlatformAccount::factory()->forCreator($creator)->create();
        $content = ContentItem::factory()->create(['platform_account_id' => $account->id]);
        $subject = MonitoredSubject::factory()->create(['creator_id' => $creator->id]);

        // Strong recognition evidence: an unconstrained AI re-run would
        // classify this LIKELY_ORGANIC at MEDIUM confidence.
        RecognitionDetection::factory()->create(['content_item_id' => $content->id]);

        $mention = Mention::factory()->lowConfidence()->create([
            'monitored_subject_id' => $subject->id,
            'content_item_id' => $content->id,
        ])->fresh();

        app(ReviewService::class)->correct(
            $mention,
            ['mention_type' => 'SEEDED'],
            $reviewer,
            reason: 'shipment #99 confirmed by ops',
        );

        $written = app(AttributionService::class)->enrich($content->fresh());

        // The AI run saw the mention but never overwrote the human decision.
        $this->assertCount(1, $written);
        $this->assertTrue($written[0]->is($mention));
        $this->assertSame(1, Mention::query()->count());

        $mention->refresh();
        $this->assertSame(MentionType::Seeded, $mention->mention_type);
        $this->assertSame(VerificationStatus::HumanCorrected, $mention->classification->verificationStatus);
        $this->assertContains('manual-confirmation:shipment #99 confirmed by ops', $mention->classification->signals);
    }

    // ------------------------------------------------------------------
    // history
    // ------------------------------------------------------------------

    public function test_history_returns_actions_newest_first(): void
    {
        $reviewer = $this->analyst();
        $mention = Mention::factory()->lowConfidence()->create()->fresh();

        $service = app(ReviewService::class);

        $first = $service->unresolved($mention, $reviewer, 'first pass');
        $second = $service->approve($mention->fresh(), $reviewer);

        $history = $service->history($mention);

        $this->assertCount(2, $history);
        $this->assertTrue($history[0]->is($second));
        $this->assertTrue($history[1]->is($first));
    }
}
