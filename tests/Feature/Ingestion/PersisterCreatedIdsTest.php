<?php

namespace Tests\Feature\Ingestion;

use App\Modules\CRM\Models\PlatformAccount;
use App\Modules\Monitoring\Models\ContentItem;
use App\Platform\Ingestion\DTO\ContentData;
use App\Platform\Ingestion\Persistence\ContentItemPersister;
use App\Shared\Enums\Platform;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ADR-0023 (per-pull enrichment): the persister reports WHICH rows a
 * batch created — a metric refresh of an existing row must never look
 * like new content (it would re-bill recognition and append duplicate
 * EMV/reach results downstream).
 */
class PersisterCreatedIdsTest extends TestCase
{
    use RefreshDatabase;

    private function item(string $externalId): ContentData
    {
        $proto = ContentItem::factory()->make(['platform' => Platform::Instagram]);

        return new ContentData(
            platform: Platform::Instagram,
            externalId: $externalId,
            contentType: $proto->content_type,
            caption: 'hello',
            mediaUrls: [],
            publishedAt: CarbonImmutable::now()->subDay(),
            publicMetrics: [],
            provenance: $proto->provenance,
        );
    }

    public function test_created_rows_are_reported_and_refreshes_are_not(): void
    {
        $account = PlatformAccount::factory()->create(['platform' => Platform::Instagram]);
        $persister = app(ContentItemPersister::class);

        $first = $persister->persist($account, [$this->item('per-pull-1'), $this->item('per-pull-2')]);

        $this->assertCount(2, $first->createdIds);
        $this->assertSame(2, $first->created);
        $this->assertEqualsCanonicalizing(
            ContentItem::query()->withoutGlobalScopes()->pluck('id')->all(),
            $first->createdIds,
        );

        // Re-seeing the same records refreshes them — created ids stay empty.
        $second = $persister->persist($account, [$this->item('per-pull-1'), $this->item('per-pull-2')]);

        $this->assertSame([], $second->createdIds);
        $this->assertSame(2, $second->duplicates);
    }
}
