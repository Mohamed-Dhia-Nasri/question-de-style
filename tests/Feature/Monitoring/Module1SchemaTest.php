<?php

namespace Tests\Feature\Monitoring;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The Module 1 domain foundation: every canonical entity table exists with
 * its canonical columns (docs/30-data-model/00-data-model.md §3).
 */
class Module1SchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_module_1_tables_exist(): void
    {
        foreach ([
            'monitored_subjects',
            'content_items',
            'stories',
            'comments',
            'mentions',
            'recognition_detections',
            'sentiment_analyses',
            'metric_snapshots',
        ] as $table) {
            $this->assertTrue(Schema::hasTable($table), "Missing Module 1 table [{$table}].");
        }
    }

    public function test_read_dependency_tables_exist(): void
    {
        // M3-owned entities Module 1 reads (ownership matrix): FK anchors only.
        foreach (['clients', 'brands', 'creators', 'platform_accounts', 'campaigns'] as $table) {
            $this->assertTrue(Schema::hasTable($table), "Missing read-dependency table [{$table}].");
        }
    }

    public function test_creator_timestamps_are_not_nullable(): void
    {
        // ENT-Creator is the only in-scope entity whose canonical shape lists
        // createdAt/updatedAt as Required=Yes (docs/30-data-model/00-data-model.md#ent-creator).
        foreach (['created_at', 'updated_at'] as $column) {
            $meta = DB::selectOne(
                'select is_nullable from information_schema.columns where table_name = ? and column_name = ?',
                ['creators', $column],
            );

            $this->assertSame('NO', $meta->is_nullable, "[creators.{$column}] must be NOT NULL (ENT-Creator Required=Yes).");
        }
    }

    public function test_monitored_subjects_columns_match_canonical_shape(): void
    {
        $this->assertTrue(Schema::hasColumns('monitored_subjects', [
            'id', 'subject_type', 'label', 'creator_id', 'terms', 'platforms', 'campaign_id', 'active',
        ]));
    }

    public function test_content_items_columns_match_canonical_shape(): void
    {
        $this->assertTrue(Schema::hasColumns('content_items', [
            'id', 'platform_account_id', 'platform', 'content_type', 'caption',
            'media_urls', 'published_at', 'public_metrics', 'provenance',
        ]));
    }

    public function test_stories_columns_match_canonical_shape(): void
    {
        $this->assertTrue(Schema::hasColumns('stories', [
            'id', 'platform_account_id', 'platform', 'media_url', 'captured_at',
            'expires_at', 'public_metrics', 'provenance',
        ]));
    }

    public function test_comments_columns_match_canonical_shape(): void
    {
        $this->assertTrue(Schema::hasColumns('comments', [
            'id', 'content_item_id', 'parent_comment_id', 'author_handle', 'text',
            'like_count', 'posted_at', 'provenance',
        ]));
    }

    public function test_mentions_columns_match_canonical_shape(): void
    {
        $this->assertTrue(Schema::hasColumns('mentions', [
            'id', 'monitored_subject_id', 'content_item_id', 'story_id', 'campaign_id',
            'mention_type', 'classification', 'provenance',
        ]));
    }

    public function test_recognition_detections_columns_match_canonical_shape(): void
    {
        $this->assertTrue(Schema::hasColumns('recognition_detections', [
            'id', 'content_item_id', 'story_id', 'recognition_type', 'detected_text',
            'detected_brand', 'assessment', 'provenance',
        ]));
    }

    public function test_sentiment_analyses_columns_match_canonical_shape(): void
    {
        $this->assertTrue(Schema::hasColumns('sentiment_analyses', [
            'id', 'content_item_id', 'comment_id', 'label', 'context_summary', 'assessment',
        ]));
    }

    public function test_metric_snapshots_columns_match_canonical_shape(): void
    {
        $this->assertTrue(Schema::hasColumns('metric_snapshots', [
            'id', 'platform_account_id', 'content_item_id', 'captured_at', 'metrics', 'provenance',
        ]));

        // Append-only series: no updated_at by design (ADR-0003).
        $this->assertFalse(Schema::hasColumn('metric_snapshots', 'updated_at'));
    }

    public function test_sentiment_analyses_carry_no_provenance_column(): void
    {
        // ENT-SentimentAnalysis is internal AI output: the canonical shape
        // embeds ConfidenceAssessment only — no Provenance envelope.
        $this->assertFalse(Schema::hasColumn('sentiment_analyses', 'provenance'));
    }
}
