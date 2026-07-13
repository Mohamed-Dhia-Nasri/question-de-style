<?php

namespace Tests\Feature\Monitoring;

use App\Modules\CRM\Models\PlatformAccount;
use App\Modules\Monitoring\Models\ContentItem;
use App\Modules\Monitoring\Models\Mention;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ForeignKeyConstraintsTest extends TestCase
{
    use RefreshDatabase;

    public function test_content_item_requires_an_existing_platform_account(): void
    {
        $this->expectException(QueryException::class);

        DB::table('content_items')->insert([
            'tenant_id' => $this->defaultTenant->id,
            'platform_account_id' => 999_999,
            'platform' => 'INSTAGRAM',
            'content_type' => 'REEL',
            'provenance' => json_encode([
                'source' => 'SRC-apify-instagram-scraper',
                'fetchedAt' => now()->toIso8601String(),
                'sourceVersion' => 'test',
            ]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_mention_requires_an_existing_monitored_subject(): void
    {
        $contentItem = ContentItem::factory()->create();

        $this->expectException(QueryException::class);

        DB::table('mentions')->insert([
            'tenant_id' => $this->defaultTenant->id,
            'monitored_subject_id' => 999_999,
            'content_item_id' => $contentItem->id,
            'mention_type' => 'UNKNOWN',
            'classification' => json_encode([
                'value' => 'UNKNOWN',
                'confidenceLevel' => 'LOW',
                'signals' => ['weak-signal'],
                'verificationStatus' => 'AI_ASSESSED',
            ]),
            'provenance' => json_encode([
                'source' => 'SRC-apify-instagram-scraper',
                'fetchedAt' => now()->toIso8601String(),
                'sourceVersion' => 'test',
            ]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_platform_account_with_content_cannot_be_deleted(): void
    {
        // Restrictive FKs: externally-sourced history is never silently
        // cascaded away. Deletion tooling is P4 hardening scope (DP-005).
        $contentItem = ContentItem::factory()->create();

        $this->expectException(QueryException::class);

        PlatformAccount::query()->whereKey($contentItem->platform_account_id)->delete();
    }

    public function test_content_item_with_mentions_cannot_be_deleted(): void
    {
        $mention = Mention::factory()->create();

        $this->expectException(QueryException::class);

        ContentItem::query()->whereKey($mention->content_item_id)->delete();
    }
}
