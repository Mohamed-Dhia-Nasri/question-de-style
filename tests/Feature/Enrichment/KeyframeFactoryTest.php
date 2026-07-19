<?php

namespace Tests\Feature\Enrichment;

use App\Modules\Monitoring\Models\ContentItem;
use App\Modules\Monitoring\Models\Keyframe;
use App\Shared\Enums\KeyframeKind;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class KeyframeFactoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_factory_creates_a_valid_tenant_stamped_frame(): void
    {
        $frame = Keyframe::factory()->create();

        $this->assertNotNull($frame->tenant_id);
        // No morph map — owner_type stores the FQCN (B's seam audit 4).
        $this->assertSame(ContentItem::class, $frame->owner_type);
        $this->assertSame(KeyframeKind::VideoSample, $frame->kind);
        $this->assertSame(64, strlen($frame->checksum));
        $this->assertSame(64, strlen($frame->source_checksum));

        $owner = ContentItem::query()->findOrFail($frame->owner_id);
        $this->assertSame($frame->tenant_id, $owner->tenant_id);
    }

    public function test_for_owner_attaches_multiple_frames_with_distinct_ordinals(): void
    {
        $item = ContentItem::factory()->create();

        $frames = Keyframe::factory()->count(3)->forOwner($item)->create();

        $this->assertSame([$item->id, $item->id, $item->id], $frames->pluck('owner_id')->all());
        // Distinct ordinals — the (owner_type, owner_id, ordinal) unique holds.
        $this->assertCount(3, array_unique($frames->pluck('ordinal')->all()));
        $this->assertTrue($frames->first()->owner()->is($item));
    }

    public function test_thumbnail_and_source_image_states_have_no_timeline_position(): void
    {
        $thumbnail = Keyframe::factory()->thumbnail()->create();
        $sourceImage = Keyframe::factory()->sourceImage()->create();

        $this->assertSame(KeyframeKind::Thumbnail, $thumbnail->kind);
        $this->assertNull($thumbnail->timestamp_ms);
        $this->assertSame(KeyframeKind::SourceImage, $sourceImage->kind);
        $this->assertNull($sourceImage->timestamp_ms);
    }

    public function test_keyframes_carry_the_id_tenant_unique_composite_fk_anchor(): void
    {
        // Task 5's keyframe_embeddings composite FK
        // (keyframe_id, tenant_id) → keyframes (id, tenant_id) needs this
        // unique (reach_results tenant-FK pattern, ADR-0019/0020).
        $this->assertNotNull(DB::selectOne(
            "SELECT indexname FROM pg_indexes WHERE tablename = 'keyframes' AND indexname = 'keyframes_id_tenant_id_unique'"
        ));
    }
}
