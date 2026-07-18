<?php

namespace Tests\Unit\Ingestion;

use App\Platform\Ingestion\DTO\ContentData;
use App\Platform\Ingestion\DTO\ProductTag;
use App\Shared\Enums\ContentType;
use App\Shared\Enums\Platform;
use App\Shared\ValueObjects\Provenance;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class ContentDataSignalsTest extends TestCase
{
    public function test_content_data_defaults_signal_fields_empty(): void
    {
        $data = new ContentData(
            platform: Platform::Instagram,
            externalId: 'p1',
            contentType: ContentType::Reel,
            caption: 'hi',
            mediaUrls: [],
            publishedAt: CarbonImmutable::now(),
            publicMetrics: [],
            provenance: new Provenance('SRC-agency-manual-entry', CarbonImmutable::now(), 'v1'),
        );

        $this->assertSame([], $data->mentions);
        $this->assertSame([], $data->productTags);
        $this->assertSame([], $data->collaborators);
        $this->assertNull($data->brandedContentLabel);
    }

    public function test_product_tag_carries_identity(): void
    {
        $tag = new ProductTag('glossier', 'You Perfume', 'GLO-YOU-50', 'ig-123');

        $this->assertSame('You Perfume', $tag->productName);
        $this->assertSame('ig-123', $tag->providerTagId);
    }
}
