<?php

namespace Tests\Feature\Monitoring;

use App\Modules\CRM\Models\Campaign;
use App\Modules\Monitoring\Contracts\ContentMatchFeedback;
use App\Modules\Monitoring\Models\ContentItem;
use App\Modules\Monitoring\Models\Mention;
use App\Modules\Monitoring\Services\ContentMatchFeedbackRecorder;
use App\Shared\Enums\ConfidenceLevel;
use App\Shared\Enums\MentionType;
use App\Shared\Enums\VerificationStatus;
use App\Shared\ValueObjects\ConfidenceAssessment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * XMC-002 (module-3 §5): the M1-side recorder is the single write path for
 * mentions.campaign_id. Confirm stamps ONLY the mention(s) whose evidence
 * supports the campaign — signals referencing the evidencing shipments, or
 * the single unattributed mention when no ambiguity exists (deep-review
 * finding C1: a blanket stamp leaked one brand's campaign onto sibling
 * mentions of a multi-brand post). Deny retracts exactly the named campaign;
 * a different, already-set attribution is never clobbered (DP-004).
 */
class ContentMatchFeedbackTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_contract_is_bound_to_the_m1_recorder(): void
    {
        $this->assertInstanceOf(ContentMatchFeedbackRecorder::class, app(ContentMatchFeedback::class));
    }

    /** @param list<string> $signals */
    private function makeMention(ContentItem $content, array $signals, ?int $campaignId = null): Mention
    {
        return Mention::factory()->create([
            'content_item_id' => $content->id,
            'campaign_id' => $campaignId,
            'mention_type' => MentionType::Seeded,
            'classification' => new ConfidenceAssessment(
                MentionType::Seeded->value,
                ConfidenceLevel::High,
                $signals,
                VerificationStatus::AiAssessed,
            ),
        ]);
    }

    public function test_confirm_stamps_only_the_mention_evidenced_by_the_shipment(): void
    {
        // C1 — one post, two brands: two unattributed mentions whose signals
        // reference DIFFERENT shipments. Confirming brand A's campaign with
        // shipment A's evidence must not touch brand B's mention.
        $content = ContentItem::factory()->create();
        $campaignA = Campaign::factory()->create();
        $mentionA = $this->makeMention($content, ['shipment-record:11']);
        $mentionB = $this->makeMention($content, ['shipment-record:22']);

        app(ContentMatchFeedback::class)->confirm($content, $campaignA->id, [11]);

        $this->assertSame($campaignA->id, $mentionA->refresh()->campaign_id);
        $this->assertNull($mentionB->refresh()->campaign_id);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'content_match.confirmed',
            'subject_id' => $mentionA->id,
        ]);
        $this->assertDatabaseMissing('audit_logs', [
            'action' => 'content_match.confirmed',
            'subject_id' => $mentionB->id,
        ]);

        // The sibling is still attributable to ITS campaign afterwards — the
        // pre-fix blanket stamp made this second confirm a silent no-op.
        $campaignB = Campaign::factory()->create();
        app(ContentMatchFeedback::class)->confirm($content, $campaignB->id, [22]);

        $this->assertSame($campaignB->id, $mentionB->refresh()->campaign_id);
        $this->assertSame($campaignA->id, $mentionA->refresh()->campaign_id);
    }

    public function test_confirm_falls_back_to_a_single_unambiguous_mention_without_evidence(): void
    {
        // Manual-link path: the classifier never saw the shipment, so no
        // signal matches — but with exactly one unattributed mention the
        // human decision is unambiguous and lands on it.
        $content = ContentItem::factory()->create();
        $campaign = Campaign::factory()->create();
        $only = $this->makeMention($content, ['no-seeding-record']);

        app(ContentMatchFeedback::class)->confirm($content, $campaign->id, [999]);

        $this->assertSame($campaign->id, $only->refresh()->campaign_id);
    }

    public function test_a_lone_unattributed_sibling_is_never_fallback_stamped(): void
    {
        // Verification finding on the first C1 fix: after the evidenced
        // mention is attributed, the idempotent linker re-fires confirm on
        // its next pass — the candidates then shrink to the OTHER brand's
        // lone null sibling, and a candidates-only fallback would stamp it
        // (the original corruption, delayed one run). The fallback must
        // require the content to carry a single mention IN TOTAL.
        $content = ContentItem::factory()->create();
        $campaignA = Campaign::factory()->create();
        $evidenced = $this->makeMention($content, ['shipment-record:11'], $campaignA->id);
        $sibling = $this->makeMention($content, ['no-seeding-record']);

        app(ContentMatchFeedback::class)->confirm($content, $campaignA->id, [11]);

        $this->assertNull($sibling->refresh()->campaign_id);
        $this->assertSame($campaignA->id, $evidenced->refresh()->campaign_id);
        $this->assertDatabaseMissing('audit_logs', [
            'action' => 'content_match.confirmed',
            'subject_id' => $sibling->id,
        ]);
    }

    public function test_confirm_stamps_nothing_when_several_unevidenced_mentions_compete(): void
    {
        // Never guess (spec D3 doctrine): two unattributed mentions, neither
        // evidencing the shipment — ambiguity is left to humans.
        $content = ContentItem::factory()->create();
        $campaign = Campaign::factory()->create();
        $first = $this->makeMention($content, ['no-seeding-record']);
        $second = $this->makeMention($content, ['weak-signal']);

        app(ContentMatchFeedback::class)->confirm($content, $campaign->id, [999]);

        $this->assertNull($first->refresh()->campaign_id);
        $this->assertNull($second->refresh()->campaign_id);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'content_match.confirmed']);
    }

    public function test_confirm_never_overwrites_a_different_existing_attribution(): void
    {
        $content = ContentItem::factory()->create();
        $campaign = Campaign::factory()->create();
        $other = Campaign::factory()->create();
        $alreadySet = $this->makeMention($content, ['shipment-record:11'], $other->id);

        app(ContentMatchFeedback::class)->confirm($content, $campaign->id, [11]);

        // Evidenced, but already attributed elsewhere — untouched (DP-004).
        $this->assertSame($other->id, $alreadySet->refresh()->campaign_id);
    }

    public function test_deny_retracts_exactly_the_named_campaign(): void
    {
        $content = ContentItem::factory()->create();
        $campaign = Campaign::factory()->create();
        $other = Campaign::factory()->create();
        $attributed = $this->makeMention($content, ['shipment-record:11'], $campaign->id);
        $otherAttribution = $this->makeMention($content, ['shipment-record:22'], $other->id);

        app(ContentMatchFeedback::class)->deny($content, $campaign->id);

        $this->assertNull($attributed->refresh()->campaign_id);
        $this->assertSame($other->id, $otherAttribution->refresh()->campaign_id);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'content_match.denied',
            'subject_id' => $attributed->id,
        ]);
    }
}
