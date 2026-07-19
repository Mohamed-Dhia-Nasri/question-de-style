<?php

namespace Tests\Feature\Enrichment;

use App\Modules\CRM\Models\Creator;
use App\Modules\CRM\Models\PlatformAccount;
use App\Modules\Monitoring\Models\ContentItem;
use App\Modules\Monitoring\Models\Keyframe;
use App\Platform\Enrichment\Keyframes\KeyframeRepository;
use App\Shared\Enums\KeyframeKind;
use App\Shared\ValueObjects\Provenance;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KeyframeModelTest extends TestCase
{
    use RefreshDatabase;

    private function makeContentItem(): ContentItem
    {
        $account = PlatformAccount::factory()->for(Creator::factory())->create();

        return ContentItem::factory()->for($account, 'platformAccount')->create();
    }

    private function frameAttributes(ContentItem $item, int $ordinal): array
    {
        return [
            'owner_type' => $item->getMorphClass(),
            'owner_id' => $item->id,
            'ordinal' => $ordinal,
            'timestamp_ms' => $ordinal * 3000,
            'storage_disk' => 'media',
            'storage_path' => "tenants/1/keyframes/instagram/1/content-x/{$ordinal}.jpg",
            'width' => 1280,
            'height' => 720,
            'kind' => KeyframeKind::VideoSample,
            'checksum' => str_repeat('a', 64),
            'source_checksum' => str_repeat('b', 64),
            'provenance' => new Provenance('SRC-apify-instagram-reel-scraper', CarbonImmutable::now(), 'keyframes-v1'),
        ];
    }

    public function test_frames_are_tenant_stamped_and_owner_reachable(): void
    {
        $item = $this->makeContentItem();
        $frame = Keyframe::query()->create($this->frameAttributes($item, 0));

        $this->assertSame($item->tenant_id, $frame->tenant_id);
        $this->assertTrue($frame->owner()->is($item));
        $this->assertTrue($item->keyframes()->whereKey($frame->id)->exists());
        $this->assertSame(KeyframeKind::VideoSample, $frame->kind);
        $this->assertSame(str_repeat('b', 64), $frame->source_checksum);
    }

    public function test_owner_plus_ordinal_is_unique(): void
    {
        $item = $this->makeContentItem();
        Keyframe::query()->create($this->frameAttributes($item, 0));

        $this->expectException(UniqueConstraintViolationException::class);
        Keyframe::query()->create($this->frameAttributes($item, 0));
    }

    public function test_repository_returns_frames_ordered_by_ordinal(): void
    {
        $item = $this->makeContentItem();
        Keyframe::query()->create($this->frameAttributes($item, 2));
        Keyframe::query()->create($this->frameAttributes($item, 0));
        Keyframe::query()->create($this->frameAttributes($item, 1));

        $set = app(KeyframeRepository::class)->forOwner($item);

        $this->assertSame('extracted', $set->status);
        $this->assertFalse($set->isEmpty());
        $this->assertSame([0, 1, 2], array_map(fn ($f) => $f->ordinal, $set->frames));
    }

    public function test_repository_reports_empty_for_frameless_owner(): void
    {
        $set = app(KeyframeRepository::class)->forOwner($this->makeContentItem());

        $this->assertSame('empty', $set->status);
        $this->assertTrue($set->isEmpty());
    }
}
