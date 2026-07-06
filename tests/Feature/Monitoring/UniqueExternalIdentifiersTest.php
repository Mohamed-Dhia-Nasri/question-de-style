<?php

namespace Tests\Feature\Monitoring;

use App\Modules\CRM\Models\PlatformAccount;
use App\Modules\Monitoring\Models\ContentItem;
use App\Modules\Monitoring\Models\Story;
use App\Shared\Enums\Platform;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Duplicate prevention (AC-M1-001: "no duplicate is created within one
 * cycle"): the platform's native identifiers are unique per platform.
 */
class UniqueExternalIdentifiersTest extends TestCase
{
    use RefreshDatabase;

    public function test_platform_and_handle_are_unique_for_accounts(): void
    {
        PlatformAccount::factory()->create(['platform' => Platform::Instagram, 'handle' => 'qds_creator']);

        $this->expectException(QueryException::class);

        PlatformAccount::factory()->create(['platform' => Platform::Instagram, 'handle' => 'qds_creator']);
    }

    public function test_same_handle_is_allowed_on_a_different_platform(): void
    {
        PlatformAccount::factory()->create(['platform' => Platform::Instagram, 'handle' => 'qds_creator']);
        $tiktok = PlatformAccount::factory()->create(['platform' => Platform::TikTok, 'handle' => 'qds_creator']);

        $this->assertDatabaseHas('platform_accounts', ['id' => $tiktok->id, 'platform' => 'TIKTOK']);
    }

    public function test_content_external_id_is_unique_per_platform(): void
    {
        ContentItem::factory()->create(['platform' => Platform::Instagram, 'external_id' => 'post-1']);

        $this->expectException(QueryException::class);

        ContentItem::factory()->create(['platform' => Platform::Instagram, 'external_id' => 'post-1']);
    }

    public function test_same_content_external_id_is_allowed_on_a_different_platform(): void
    {
        ContentItem::factory()->create(['platform' => Platform::Instagram, 'external_id' => 'post-1']);
        $other = ContentItem::factory()->create(['platform' => Platform::YouTube, 'external_id' => 'post-1']);

        $this->assertDatabaseHas('content_items', ['id' => $other->id, 'platform' => 'YOUTUBE']);
    }

    public function test_story_external_id_is_unique_per_platform(): void
    {
        Story::factory()->create(['platform' => Platform::Instagram, 'external_id' => 'story-1']);

        $this->expectException(QueryException::class);

        Story::factory()->create(['platform' => Platform::Instagram, 'external_id' => 'story-1']);
    }
}
