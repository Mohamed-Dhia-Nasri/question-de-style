<?php

namespace Tests\Feature\Monitoring;

use App\Modules\Monitoring\Models\Mention;
use App\Modules\Monitoring\Models\MonitoredSubject;
use App\Modules\Monitoring\Models\RecognitionDetection;
use App\Modules\Monitoring\Models\SentimentAnalysis;
use App\Modules\Monitoring\Models\Story;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Database-level guardrails: closed enum sets (glossary Part B) and the
 * exactly-one-target rules implied by the canonical nullable FK pairs.
 */
class CheckConstraintsTest extends TestCase
{
    use RefreshDatabase;

    public function test_mention_type_check_rejects_confirmed_organic(): void
    {
        // Deliberately absent value (glossary): organic is never a fact.
        $mention = Mention::factory()->create();

        $this->expectException(QueryException::class);

        DB::table('mentions')->insert([
            'tenant_id' => $this->defaultTenant->id,
            'monitored_subject_id' => $mention->monitored_subject_id,
            'content_item_id' => $mention->content_item_id,
            'mention_type' => 'CONFIRMED_ORGANIC',
            'classification' => $mention->getRawOriginal('classification'),
            'provenance' => $mention->getRawOriginal('provenance'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_mention_must_target_exactly_one_of_content_or_story(): void
    {
        $this->expectException(QueryException::class);

        Mention::factory()->create([
            'content_item_id' => null,
            'story_id' => null,
        ]);
    }

    public function test_mention_cannot_target_both_content_and_story(): void
    {
        $this->expectException(QueryException::class);

        Mention::factory()->create([
            'story_id' => Story::factory(),
        ]);
    }

    public function test_recognition_detection_must_target_exactly_one_source(): void
    {
        $this->expectException(QueryException::class);

        RecognitionDetection::factory()->create([
            'content_item_id' => null,
            'story_id' => null,
        ]);
    }

    public function test_sentiment_analysis_must_target_exactly_one_subject(): void
    {
        $this->expectException(QueryException::class);

        SentimentAnalysis::factory()->create([
            'content_item_id' => null,
            'comment_id' => null,
        ]);
    }

    public function test_creator_subject_requires_a_creator_reference(): void
    {
        // ADR-0011 roster shape: a CREATOR subject must point at its creator.
        $this->expectException(QueryException::class);

        MonitoredSubject::factory()->create(['creator_id' => null]);
    }

    public function test_platform_check_rejects_unknown_platforms(): void
    {
        $story = Story::factory()->create();

        $this->expectException(QueryException::class);

        DB::table('stories')->insert([
            'tenant_id' => $this->defaultTenant->id,
            'platform_account_id' => $story->platform_account_id,
            'platform' => 'SNAPCHAT',
            'captured_at' => now(),
            'provenance' => $story->getRawOriginal('provenance'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
