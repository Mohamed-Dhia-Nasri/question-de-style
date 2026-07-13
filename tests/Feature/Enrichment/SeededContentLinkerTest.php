<?php

namespace Tests\Feature\Enrichment;

use App\Modules\CRM\Models\Brand;
use App\Modules\CRM\Models\Campaign;
use App\Modules\CRM\Models\Creator;
use App\Modules\CRM\Models\PlatformAccount;
use App\Modules\CRM\Models\SeedingCampaign;
use App\Modules\CRM\Models\Shipment;
use App\Modules\Monitoring\Models\ContentItem;
use App\Modules\Monitoring\Models\Mention;
use App\Platform\Enrichment\Matching\SeededContentLinker;
use App\Shared\Enums\ConfidenceLevel;
use App\Shared\Enums\MentionType;
use App\Shared\Enums\Platform;
use App\Shared\Enums\VerificationStatus;
use App\Shared\ValueObjects\ConfidenceAssessment;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * REQ-M3-008 / AC-M3-013: the linker materializes shipment↔content links
 * from SEEDED mentions. Auto-links only above the established review
 * cut-point (AI_ASSESSED at HIGH/MEDIUM) or after a human blessed the
 * mention; low-confidence rows stay in the review queue unlinked; the
 * mention's campaign attribution flows through XMC-002 only when the parent
 * campaign is unambiguous.
 */
class SeededContentLinkerTest extends TestCase
{
    use RefreshDatabase;

    private Creator $creator;

    private ContentItem $content;

    protected function setUp(): void
    {
        parent::setUp();

        $this->creator = Creator::factory()->create();
        $account = PlatformAccount::factory()->forCreator($this->creator)->create();
        $this->content = ContentItem::factory()->create([
            'platform_account_id' => $account->id,
            'published_at' => CarbonImmutable::parse('2026-06-10 12:00:00'),
        ]);
    }

    private function makeShipment(?int $campaignId = null): Shipment
    {
        $brand = Brand::factory()->create();

        return Shipment::factory()->create([
            'seeding_campaign_id' => SeedingCampaign::factory()->create([
                'brand_id' => $brand->id,
                'campaign_id' => $campaignId,
            ])->id,
            'creator_id' => $this->creator->id,
            'shipped_at' => now()->subDays(5),
        ]);
    }

    private function makeSeededMention(
        array $signals,
        ConfidenceLevel $level = ConfidenceLevel::High,
        VerificationStatus $status = VerificationStatus::AiAssessed,
    ): Mention {
        return Mention::factory()->create([
            'content_item_id' => $this->content->id,
            'mention_type' => MentionType::Seeded,
            'classification' => new ConfidenceAssessment(
                MentionType::Seeded->value,
                $level,
                $signals,
                $status,
            ),
        ]);
    }

    public function test_high_confidence_seeded_mentions_auto_link_and_confirm_the_campaign(): void
    {
        $campaign = Campaign::factory()->create();
        $shipment = $this->makeShipment($campaign->id);
        $mention = $this->makeSeededMention(['shipment-record:'.$shipment->id]);

        $summary = app(SeededContentLinker::class)->run();

        $this->assertSame(1, $summary->linked);
        $this->assertSame(1, $summary->campaignsConfirmed);
        $this->assertDatabaseHas('shipment_resulting_content', [
            'shipment_id' => $shipment->id,
            'content_item_id' => $this->content->id,
        ]);

        $shipment->refresh();
        $this->assertTrue($shipment->posted);
        // postedAt = publish time of the resulting content (data model).
        $this->assertTrue($shipment->posted_at->equalTo('2026-06-10 12:00:00'));

        // XMC-002: the mention now carries the parent-campaign attribution.
        $this->assertSame($campaign->id, $mention->refresh()->campaign_id);
        $this->assertDatabaseHas('audit_logs', ['action' => 'content_match.confirmed', 'subject_id' => $mention->id]);
    }

    public function test_a_repeat_pass_never_stamps_an_unevidenced_sibling_mention(): void
    {
        // Verification finding on the first C1 fix: the linker is
        // idempotent and re-visits already-linked mentions every pass
        // (windowed on updated_at, which the first attribution itself
        // bumps). On the SECOND pass the evidenced mention is attributed,
        // so a candidates-only fallback in confirm() would stamp the
        // remaining unevidenced sibling — the original cross-brand
        // corruption, delayed one run. It must stay null forever.
        $campaign = Campaign::factory()->create();
        $shipment = $this->makeShipment($campaign->id);
        $evidenced = $this->makeSeededMention(['shipment-record:'.$shipment->id]);

        // The other brand's presence on the SAME post: unattributed, no
        // shipment evidence (organic sibling).
        $sibling = Mention::factory()->create([
            'content_item_id' => $this->content->id,
            'campaign_id' => null,
        ]);

        app(SeededContentLinker::class)->run();
        $this->assertSame($campaign->id, $evidenced->refresh()->campaign_id);
        $this->assertNull($sibling->refresh()->campaign_id);

        // The pass the original regression fired on.
        app(SeededContentLinker::class)->run();

        $this->assertNull($sibling->refresh()->campaign_id);
        $this->assertSame($campaign->id, $evidenced->refresh()->campaign_id);
    }

    public function test_medium_confidence_auto_links_but_low_stays_in_the_review_queue(): void
    {
        $medium = $this->makeShipment();
        $this->makeSeededMention(['shipment-record:'.$medium->id], ConfidenceLevel::Medium);

        // A second account for the same creator must sit on a DIFFERENT
        // platform (one-per-platform invariant, now DB-enforced — L1).
        $account = PlatformAccount::factory()->forCreator($this->creator)->onPlatform(Platform::TikTok)->create();
        $otherContent = ContentItem::factory()->create(['platform_account_id' => $account->id]);
        $low = $this->makeShipment();
        Mention::factory()->create([
            'content_item_id' => $otherContent->id,
            'mention_type' => MentionType::Seeded,
            'classification' => new ConfidenceAssessment(
                MentionType::Seeded->value,
                ConfidenceLevel::Low,
                ['shipment-record:'.$low->id],
                VerificationStatus::AiAssessed,
            ),
        ]);

        app(SeededContentLinker::class)->run();

        // MEDIUM is above the review cut-point → linked (AC-M3-013 high path).
        $this->assertDatabaseHas('shipment_resulting_content', ['shipment_id' => $medium->id]);
        // LOW needsHumanReview() → queued, never auto-linked (AC-M3-013 low path).
        $this->assertDatabaseMissing('shipment_resulting_content', ['shipment_id' => $low->id]);
        $this->assertFalse($low->refresh()->posted);
    }

    public function test_a_human_blessed_mention_links_even_after_correction(): void
    {
        $shipment = $this->makeShipment();
        $this->makeSeededMention(
            ['shipment-record:'.$shipment->id],
            ConfidenceLevel::Low,
            VerificationStatus::HumanCorrected,
        );

        $summary = app(SeededContentLinker::class)->run();

        $this->assertSame(1, $summary->linked);
        $this->assertDatabaseHas('shipment_resulting_content', ['shipment_id' => $shipment->id]);
    }

    public function test_human_blessed_mentions_without_shipment_references_are_reported_not_guessed(): void
    {
        $this->makeSeededMention(
            ['recognition:LOGO:SomeBrand:HIGH'],
            ConfidenceLevel::High,
            VerificationStatus::HumanReviewed,
        );

        $summary = app(SeededContentLinker::class)->run();

        $this->assertSame(0, $summary->linked);
        $this->assertSame(1, $summary->withoutReferences);
        $this->assertDatabaseCount('shipment_resulting_content', 0);
    }

    public function test_a_reference_to_another_creators_shipment_is_never_linked(): void
    {
        $foreign = Shipment::factory()->create([
            'creator_id' => Creator::factory()->create()->id,
            'shipped_at' => now()->subDays(5),
        ]);
        $this->makeSeededMention(['shipment-record:'.$foreign->id]);

        $summary = app(SeededContentLinker::class)->run();

        $this->assertSame(0, $summary->linked);
        $this->assertSame(1, $summary->staleReferences);
        $this->assertDatabaseCount('shipment_resulting_content', 0);
    }

    public function test_reruns_are_idempotent(): void
    {
        $shipment = $this->makeShipment();
        $this->makeSeededMention(['shipment-record:'.$shipment->id]);

        $linker = app(SeededContentLinker::class);
        $first = $linker->run();
        $second = $linker->run();

        $this->assertSame(1, $first->linked);
        $this->assertSame(0, $second->linked);
        $this->assertSame(1, $second->alreadyLinked);
        $this->assertDatabaseCount('shipment_resulting_content', 1);
    }

    public function test_ambiguous_parent_campaigns_leave_the_mention_unattributed(): void
    {
        $first = $this->makeShipment(Campaign::factory()->create()->id);
        $second = $this->makeShipment(Campaign::factory()->create()->id);
        $mention = $this->makeSeededMention([
            'shipment-record:'.$first->id,
            'shipment-record:'.$second->id,
        ]);

        $summary = app(SeededContentLinker::class)->run();

        // Both shipments link (M3 side)…
        $this->assertSame(2, $summary->linked);
        // …but the mention's campaign stays null for a human (spec D3).
        $this->assertSame(0, $summary->campaignsConfirmed);
        $this->assertNull($mention->refresh()->campaign_id);
    }

    public function test_the_lookback_window_bounds_scheduled_passes_and_all_rescans(): void
    {
        // GAP-2 pin: a windowed pass skips mentions not updated within the
        // window; run(null) — the --all path — still reaches them.
        $campaign = Campaign::factory()->create();
        $shipment = $this->makeShipment($campaign->id);
        $mention = $this->makeSeededMention(['shipment-record:'.$shipment->id]);

        // Age the mention past any window (bypass Eloquent so nothing
        // bumps updated_at back).
        DB::table('mentions')->where('id', $mention->id)
            ->update(['updated_at' => now()->subDays(30)]);

        $windowed = app(SeededContentLinker::class)->run(now()->subHours(48)->toImmutable());
        $this->assertSame(0, $windowed->linked);
        $this->assertDatabaseMissing('shipment_resulting_content', ['shipment_id' => $shipment->id]);

        $full = app(SeededContentLinker::class)->run();
        $this->assertSame(1, $full->linked);
        $this->assertDatabaseHas('shipment_resulting_content', ['shipment_id' => $shipment->id]);
    }

    public function test_the_all_option_forces_the_full_rescan(): void
    {
        config(['qds.matching.enabled' => true, 'qds.matching.lookback_hours' => 48]);

        $shipment = $this->makeShipment();
        $mention = $this->makeSeededMention(['shipment-record:'.$shipment->id]);
        DB::table('mentions')->where('id', $mention->id)
            ->update(['updated_at' => now()->subDays(30)]);

        // Scheduled (windowed) pass misses the aged mention...
        $this->artisan('qds:link-seeded-content')
            ->expectsOutputToContain('Linked 0 content item(s)')
            ->assertExitCode(0);

        // ...the daily --all pass heals it.
        $this->artisan('qds:link-seeded-content --all')
            ->expectsOutputToContain('Linked 1 content item(s)')
            ->assertExitCode(0);
    }

    public function test_the_command_is_self_gating_on_the_matching_flag(): void
    {
        config(['qds.matching.enabled' => false]);

        $this->artisan('qds:link-seeded-content')
            ->expectsOutputToContain('disabled')
            ->assertExitCode(0);

        config(['qds.matching.enabled' => true]);

        $shipment = $this->makeShipment();
        $this->makeSeededMention(['shipment-record:'.$shipment->id]);

        $this->artisan('qds:link-seeded-content')
            ->expectsOutputToContain('Linked 1 content item(s)')
            ->assertExitCode(0);
    }
}
