<?php

namespace Tests\Feature\Monitoring;

use App\Modules\Monitoring\Models\Story;
use App\Shared\Enums\ContentType;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Binding rule (data model / glossary F8): stories are ENT-Story, never a
 * ContentItem — STORY is not a member of ENUM-ContentType.
 */
class StoryContentSeparationTest extends TestCase
{
    use RefreshDatabase;

    public function test_content_type_enum_has_no_story_value(): void
    {
        $this->assertNotContains('STORY', array_column(ContentType::cases(), 'value'));
    }

    public function test_database_rejects_a_story_typed_content_item(): void
    {
        $this->expectException(QueryException::class);

        DB::table('content_items')->insert([
            'platform_account_id' => Story::factory()->create()->platform_account_id,
            'platform' => 'INSTAGRAM',
            'content_type' => 'STORY',
            'provenance' => json_encode([
                'source' => 'SRC-apify-instagram-story-details',
                'fetchedAt' => now()->toIso8601String(),
                'sourceVersion' => 'test',
            ]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_stories_live_in_their_own_table_with_archival_fields(): void
    {
        $story = Story::factory()->create();

        $this->assertDatabaseHas('stories', ['id' => $story->id]);
        $this->assertNotNull($story->captured_at);
        $this->assertTrue(Schema::hasColumn('stories', 'expires_at'));
        $this->assertFalse(Schema::hasColumn('stories', 'content_type'));
    }
}
