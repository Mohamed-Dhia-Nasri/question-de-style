<?php

namespace Tests\Feature\Enrichment;

use App\Models\User;
use App\Modules\Monitoring\Models\ContentHashtag;
use App\Modules\Monitoring\Models\ContentItem;
use App\Modules\Monitoring\Models\HashtagList;
use App\Platform\Enrichment\Hashtags\HashtagEnricher;
use App\Platform\Enrichment\Hashtags\HashtagMatch;
use App\Platform\Enrichment\Review\ReviewQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Hashtag matching + persistence doctrine (ADR-0008, DP-003/DP-004):
 * configured lists match by normalized form only; generic hashtags never
 * count as evidence; multi-target hashtags are AMBIGUOUS and route to the
 * human review queue; re-runs are idempotent and NEVER overwrite a human
 * resolution. Synthetic data only (DP-005) — no live provider is called.
 */
class HashtagPipelineTest extends TestCase
{
    use RefreshDatabase;

    private function enrich(ContentItem $contentItem): array
    {
        return app(HashtagEnricher::class)->enrich($contentItem);
    }

    private function row(ContentItem $contentItem, string $normalized): ?ContentHashtag
    {
        return ContentHashtag::query()
            ->where('content_item_id', $contentItem->id)
            ->where('normalized', $normalized)
            ->first();
    }

    public function test_configured_lists_match_by_normalized_form(): void
    {
        $campaignList = HashtagList::factory()->forCampaign()->hashtag('#SummerGlow2026')->create();
        $brandList = HashtagList::factory()->hashtag('#AcmeBeauty')->create();
        $productList = HashtagList::factory()->forProduct('Glow Serum')->hashtag('#GlowSerumFR')->create();
        $agencyList = HashtagList::factory()->agency()->hashtag('#QdsCrew')->create();

        // Caption casing differs from every configured form: matching is
        // driven by the normalized form, never the verbatim original.
        $contentItem = ContentItem::factory()->create([
            'caption' => 'Launch! #summerglow2026 #ACMEBEAUTY #glowserumfr #QDSCREW #unlisted',
        ]);

        $this->enrich($contentItem);

        $expected = [
            'summerglow2026' => [$campaignList, 'CAMPAIGN'],
            'acmebeauty' => [$brandList, 'BRAND'],
            'glowserumfr' => [$productList, 'PRODUCT'],
            'qdscrew' => [$agencyList, 'AGENCY'],
        ];

        foreach ($expected as $normalized => [$list, $scope]) {
            $row = $this->row($contentItem, $normalized);

            $this->assertNotNull($row, "[{$normalized}] should be persisted.");
            $this->assertCount(1, $row->matches);
            $this->assertSame($list->id, $row->matches[0]['hashtag_list_id']);
            $this->assertSame($scope, $row->matches[0]['scope']);
            $this->assertFalse($row->is_ambiguous);
        }

        $this->assertSame($campaignList->campaign_id, $this->row($contentItem, 'summerglow2026')->matches[0]['campaign_id']);
        $this->assertSame($brandList->brand_id, $this->row($contentItem, 'acmebeauty')->matches[0]['brand_id']);
        $this->assertSame('Glow Serum', $this->row($contentItem, 'glowserumfr')->matches[0]['product_label']);

        // An unconfigured hashtag persists with NO matches — absence of
        // evidence is empty, never a fabricated hit.
        $unlisted = $this->row($contentItem, 'unlisted');
        $this->assertNotNull($unlisted);
        $this->assertSame([], $unlisted->matches);
        $this->assertFalse($unlisted->is_ambiguous);
    }

    public function test_generic_hashtag_never_matches_even_with_a_configured_list(): void
    {
        $this->assertContains('beauty', config('qds.enrichment.hashtags.generic'));

        // Someone (mis)configured the generic tag in a brand list anyway.
        HashtagList::factory()->hashtag('#beauty')->create();

        $contentItem = ContentItem::factory()->create(['caption' => 'Feeling #Beauty today']);

        $matches = $this->enrich($contentItem);

        $this->assertCount(1, $matches);
        $this->assertInstanceOf(HashtagMatch::class, $matches[0]);
        $this->assertTrue($matches[0]->isGeneric);
        $this->assertSame([], $matches[0]->matches);
        $this->assertFalse($matches[0]->isAmbiguous);

        $row = $this->row($contentItem, 'beauty');
        $this->assertNotNull($row);
        $this->assertSame('#Beauty', $row->original);
        $this->assertSame([], $row->matches);
        $this->assertFalse($row->is_ambiguous);

        // Generic tags carry no evidential weight and need no human review.
        $this->assertCount(0, app(ReviewQueue::class)->items(['kind' => 'hashtag']));
    }

    public function test_same_hashtag_on_two_campaigns_is_ambiguous_and_enters_review_queue(): void
    {
        $listA = HashtagList::factory()->forCampaign()->hashtag('#GlowLaunch')->create();
        $listB = HashtagList::factory()->forCampaign()->hashtag('#GlowLaunch')->create();

        $this->assertNotSame($listA->campaign_id, $listB->campaign_id);

        $contentItem = ContentItem::factory()->create(['caption' => 'Big #GlowLaunch reveal']);

        $this->enrich($contentItem);

        $row = $this->row($contentItem, 'glowlaunch');

        $this->assertNotNull($row);
        $this->assertTrue($row->is_ambiguous);
        $this->assertTrue($row->needsHumanReview());
        $this->assertCount(2, $row->matches);
        $this->assertEqualsCanonicalizing(
            [$listA->id, $listB->id],
            array_column($row->matches, 'hashtag_list_id'),
        );

        // DP-004: the ambiguity routes to the human review queue.
        $queued = app(ReviewQueue::class)->items(['kind' => 'hashtag']);

        $this->assertCount(1, $queued);
        $this->assertSame('hashtag', $queued[0]['kind']);
        $this->assertTrue($queued[0]['item']->is($row));
    }

    public function test_agency_overlap_alone_does_not_create_ambiguity(): void
    {
        // AGENCY entries are not campaign/brand/product targets — one
        // campaign hit plus an agency hit needs no human decision.
        HashtagList::factory()->forCampaign()->hashtag('#QdsSummer')->create();
        HashtagList::factory()->agency()->hashtag('#QdsSummer')->create();

        $contentItem = ContentItem::factory()->create(['caption' => 'Ready for #QdsSummer']);

        $this->enrich($contentItem);

        $row = $this->row($contentItem, 'qdssummer');

        $this->assertNotNull($row);
        $this->assertCount(2, $row->matches);
        $this->assertFalse($row->is_ambiguous);
        $this->assertCount(0, app(ReviewQueue::class)->items(['kind' => 'hashtag']));
    }

    public function test_rerunning_enrich_does_not_duplicate_rows(): void
    {
        HashtagList::factory()->hashtag('#GlowSerum')->create();

        $contentItem = ContentItem::factory()->create([
            'caption' => 'Use #GlowSerum daily — trust me, #glowserum works',
        ]);

        $this->enrich($contentItem);
        $this->enrich($contentItem);
        $this->enrich($contentItem);

        // Upsert keyed on (content_item_id, normalized): still one row.
        $rows = ContentHashtag::query()->where('content_item_id', $contentItem->id)->get();

        $this->assertCount(1, $rows);
        $this->assertSame('glowserum', $rows[0]->normalized);
        $this->assertSame('#GlowSerum', $rows[0]->original);
        $this->assertSame(2, $rows[0]->occurrences);
        $this->assertCount(1, $rows[0]->matches);
    }

    public function test_rerunning_enrich_never_touches_a_human_resolution(): void
    {
        $listA = HashtagList::factory()->forCampaign()->hashtag('#GlowLaunch')->create();
        HashtagList::factory()->forCampaign()->hashtag('#GlowLaunch')->create();

        $contentItem = ContentItem::factory()->create(['caption' => 'Big #GlowLaunch reveal']);

        $this->enrich($contentItem);

        $row = $this->row($contentItem, 'glowlaunch');
        $this->assertTrue($row->is_ambiguous);

        // A human resolves the ambiguity in favor of campaign A.
        $reviewer = User::factory()->create();
        $resolvedAt = now()->toImmutable()->startOfSecond();

        $row->forceFill([
            'resolved_hashtag_list_id' => $listA->id,
            'resolved_by' => $reviewer->id,
            'resolved_at' => $resolvedAt,
            'is_ambiguous' => false,
        ])->save();

        $this->enrich($contentItem);

        // DP-004: a later AI run NEVER overwrites the human decision.
        $row->refresh();

        $this->assertSame($listA->id, $row->resolved_hashtag_list_id);
        $this->assertSame($reviewer->id, $row->resolved_by);
        $this->assertTrue($resolvedAt->equalTo($row->resolved_at));
        $this->assertFalse($row->is_ambiguous);
        $this->assertFalse($row->needsHumanReview());
        $this->assertCount(0, app(ReviewQueue::class)->items(['kind' => 'hashtag']));

        // Still exactly one row for the tag.
        $this->assertSame(1, ContentHashtag::query()->where('content_item_id', $contentItem->id)->count());
    }
}
