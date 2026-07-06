<?php

namespace Tests\Feature\Monitoring;

use App\Modules\CRM\Models\Campaign;
use App\Modules\CRM\Models\Creator;
use App\Modules\CRM\Models\PlatformAccount;
use App\Modules\Monitoring\Models\Comment;
use App\Modules\Monitoring\Models\ContentItem;
use App\Modules\Monitoring\Models\Mention;
use App\Modules\Monitoring\Models\MetricSnapshot;
use App\Modules\Monitoring\Models\MonitoredSubject;
use App\Modules\Monitoring\Models\RecognitionDetection;
use App\Modules\Monitoring\Models\SentimentAnalysis;
use App\Modules\Monitoring\Models\Story;
use App\Shared\Enums\ContentType;
use App\Shared\Enums\MentionType;
use App\Shared\Enums\MonitoredSubjectType;
use App\Shared\Enums\Platform;
use App\Shared\Enums\RecognitionType;
use App\Shared\Enums\SentimentLabel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ModelRelationshipsTest extends TestCase
{
    use RefreshDatabase;

    public function test_platform_account_chain_up_to_creator(): void
    {
        $contentItem = ContentItem::factory()
            ->for(PlatformAccount::factory()->forCreator())
            ->create();

        $contentItem->load('platformAccount.creator');

        $this->assertInstanceOf(PlatformAccount::class, $contentItem->platformAccount);
        $this->assertInstanceOf(Creator::class, $contentItem->platformAccount->creator);
        $this->assertTrue(
            $contentItem->platformAccount->creator->platformAccounts()->whereKey($contentItem->platform_account_id)->exists()
        );
    }

    public function test_monitored_subject_links_creator_campaign_and_mentions(): void
    {
        $subject = MonitoredSubject::factory()
            ->for(Campaign::factory())
            ->create();
        $mention = Mention::factory()->create(['monitored_subject_id' => $subject->id]);

        $subject->load(['creator', 'campaign', 'mentions']);

        $this->assertInstanceOf(Creator::class, $subject->creator);
        $this->assertInstanceOf(Campaign::class, $subject->campaign);
        $this->assertTrue($subject->mentions->contains($mention));
    }

    public function test_content_item_has_all_observation_relations(): void
    {
        $contentItem = ContentItem::factory()->create();

        $comment = Comment::factory()->create(['content_item_id' => $contentItem->id]);
        $mention = Mention::factory()->create(['content_item_id' => $contentItem->id]);
        $detection = RecognitionDetection::factory()->create(['content_item_id' => $contentItem->id]);
        $sentiment = SentimentAnalysis::factory()->create(['content_item_id' => $contentItem->id]);
        $snapshot = MetricSnapshot::factory()->contentLevel()->create(['content_item_id' => $contentItem->id]);

        $contentItem->load(['comments', 'mentions', 'recognitionDetections', 'sentimentAnalyses', 'metricSnapshots']);

        $this->assertTrue($contentItem->comments->contains($comment));
        $this->assertTrue($contentItem->mentions->contains($mention));
        $this->assertTrue($contentItem->recognitionDetections->contains($detection));
        $this->assertTrue($contentItem->sentimentAnalyses->contains($sentiment));
        $this->assertTrue($contentItem->metricSnapshots->contains($snapshot));
    }

    public function test_story_carries_mentions_and_detections(): void
    {
        $mention = Mention::factory()->inStory()->create();
        $story = Story::query()->findOrFail($mention->story_id);
        $detection = RecognitionDetection::factory()->inStory()->create(['story_id' => $story->id]);

        $story->load(['platformAccount', 'mentions', 'recognitionDetections']);

        $this->assertInstanceOf(PlatformAccount::class, $story->platformAccount);
        $this->assertTrue($story->mentions->contains($mention));
        $this->assertTrue($story->recognitionDetections->contains($detection));
    }

    public function test_comment_thread_and_sentiment_relations(): void
    {
        $comment = Comment::factory()->create();
        $reply = Comment::factory()->create([
            'content_item_id' => $comment->content_item_id,
            'parent_comment_id' => $comment->id,
        ]);
        $sentiment = SentimentAnalysis::factory()->create([
            'content_item_id' => null,
            'comment_id' => $comment->id,
        ]);

        $comment->load(['replies', 'sentimentAnalyses']);
        $reply->load('parent');

        $this->assertTrue($comment->replies->contains($reply));
        $this->assertTrue($comment->sentimentAnalyses->contains($sentiment));
        $this->assertTrue($reply->parent->is($comment));
    }

    public function test_enum_casts_hydrate_canonical_enums(): void
    {
        $contentItem = ContentItem::factory()->create(['content_type' => ContentType::Reel])->fresh();
        $subject = MonitoredSubject::factory()->create()->fresh();
        $mention = Mention::factory()->create(['mention_type' => MentionType::Unknown])->fresh();
        $detection = RecognitionDetection::factory()->create()->fresh();
        $sentiment = SentimentAnalysis::factory()->create(['label' => SentimentLabel::Positive])->fresh();

        $this->assertSame(Platform::Instagram, $contentItem->platform);
        $this->assertSame(ContentType::Reel, $contentItem->content_type);
        $this->assertSame(MonitoredSubjectType::Creator, $subject->subject_type);
        $this->assertSame(MentionType::Unknown, $mention->mention_type);
        $this->assertSame(RecognitionType::Logo, $detection->recognition_type);
        $this->assertSame(SentimentLabel::Positive, $sentiment->label);

        // MonitoredSubject.platforms is a list of ENUM-Platform values.
        $this->assertSame(
            ['INSTAGRAM', 'TIKTOK'],
            $subject->platforms->map(fn (Platform $platform) => $platform->value)->all(),
        );
    }

    public function test_only_creator_subject_type_is_active_in_v1(): void
    {
        // ADR-0011 / DEF-006: roster-first — CREATOR is the only v1 subject type.
        $this->assertTrue(MonitoredSubjectType::Creator->isActiveInV1());

        foreach ([MonitoredSubjectType::Brand, MonitoredSubjectType::Keyword, MonitoredSubjectType::Hashtag, MonitoredSubjectType::Handle] as $deferred) {
            $this->assertFalse($deferred->isActiveInV1());
        }
    }
}
