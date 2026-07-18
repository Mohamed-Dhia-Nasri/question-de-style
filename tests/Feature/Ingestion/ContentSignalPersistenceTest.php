<?php

namespace Tests\Feature\Ingestion;

use App\Modules\CRM\Models\PlatformAccount;
use App\Modules\Monitoring\Models\ContentItem;
use App\Platform\Ingestion\DTO\ContentData;
use App\Platform\Ingestion\DTO\ProductTag;
use App\Platform\Ingestion\Persistence\ContentItemPersister;
use App\Shared\Enums\ContentType;
use App\Shared\Enums\Platform;
use App\Shared\ValueObjects\Provenance;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContentSignalPersistenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_signals_round_trip_to_content_items(): void
    {
        $account = PlatformAccount::factory()->create(['platform' => Platform::Instagram]);

        $data = new ContentData(
            platform: Platform::Instagram,
            externalId: 'sig-1',
            contentType: ContentType::Reel,
            caption: 'thanks @glossier for the PR',
            mediaUrls: ['https://cdn/x.mp4'],
            publishedAt: CarbonImmutable::now(),
            publicMetrics: [],
            provenance: new Provenance(\App\Platform\Ingestion\SourceRegistry::AGENCY_MANUAL_ENTRY, CarbonImmutable::now(), 'v1'),
            permalink: null,
            mentions: ['glossier'],
            productTags: [new ProductTag('glossier', 'You Perfume', 'GLO-YOU-50', 'ig-1')],
            collaborators: ['glossier'],
            brandedContentLabel: true,
        );

        app(ContentItemPersister::class)->persist($account, [$data]);

        $row = ContentItem::query()->where('external_id', 'sig-1')->firstOrFail();
        $this->assertSame(['glossier'], $row->mentioned_handles);
        $this->assertSame('You Perfume', $row->product_tags[0]['product_name']);
        $this->assertSame(['glossier'], $row->collaborators);
        $this->assertTrue($row->branded_content_label);
    }
}
