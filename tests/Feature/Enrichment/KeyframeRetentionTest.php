<?php

namespace Tests\Feature\Enrichment;

use App\Modules\CRM\Models\Creator;
use App\Modules\CRM\Models\PlatformAccount;
use App\Modules\Monitoring\Models\ContentItem;
use App\Modules\Monitoring\Models\Keyframe;
use App\Modules\Monitoring\Models\MonitoringSetting;
use App\Shared\Enums\KeyframeKind;
use App\Shared\Settings\MonitoringSettingsResolver;
use App\Shared\ValueObjects\Provenance;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class KeyframeRetentionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['qds.ingestion.media_disk' => 'media']);
        Storage::fake('media');
    }

    private function makeFrame(int $ageDays, string $path = 'tenants/1/keyframes/instagram/1/content-x/0.jpg'): Keyframe
    {
        $account = PlatformAccount::factory()->for(Creator::factory())->create();
        $item = ContentItem::factory()->for($account, 'platformAccount')->create();
        Storage::disk('media')->put($path, 'FRAME');

        $frame = Keyframe::query()->create([
            'owner_type' => $item->getMorphClass(),
            'owner_id' => $item->id,
            'ordinal' => 0,
            'timestamp_ms' => 0,
            'storage_disk' => 'media',
            'storage_path' => $path,
            'width' => 100,
            'height' => 100,
            'kind' => KeyframeKind::VideoSample,
            'checksum' => str_repeat('a', 64),
            'source_checksum' => str_repeat('b', 64),
            'provenance' => new Provenance('SRC-apify-instagram-reel-scraper', CarbonImmutable::now(), 'keyframes-v1'),
        ]);
        $frame->timestamps = false;
        $frame->forceFill(['created_at' => CarbonImmutable::now()->subDays($ageDays)])->save();

        return $frame->refresh();
    }

    public function test_resolver_prefers_the_tenant_row_and_falls_back_to_config(): void
    {
        config(['qds.enrichment.keyframes.retention_days' => 180]);
        $tenantId = (int) $this->defaultTenant->id; // [seam-audited property name]

        $resolver = app(MonitoringSettingsResolver::class);
        $this->assertSame(180, $resolver->keyframeRetentionDaysFor($tenantId));

        MonitoringSetting::query()->create([
            'shipment_window_days' => 60,
            'engagement_trend_window_days' => 30,
            'story_retention_days' => 180,
            'communication_retention_days' => 0,
            'keyframe_retention_days' => 7,
        ]);

        $this->assertSame(7, app(MonitoringSettingsResolver::class)->keyframeRetentionDaysFor($tenantId));
    }

    public function test_expired_frames_lose_file_and_row_fresh_frames_survive(): void
    {
        config(['qds.enrichment.keyframes.retention_days' => 30]);
        $expired = $this->makeFrame(40);
        $fresh = $this->makeFrame(5, 'tenants/1/keyframes/instagram/1/content-y/0.jpg');

        $this->artisan('qds:prune-keyframes')->assertSuccessful();

        $this->assertDatabaseMissing('keyframes', ['id' => $expired->id]);
        Storage::disk('media')->assertMissing($expired->storage_path);
        $this->assertDatabaseHas('keyframes', ['id' => $fresh->id]);
        Storage::disk('media')->assertExists($fresh->storage_path);
    }

    public function test_zero_retention_keeps_everything(): void
    {
        config(['qds.enrichment.keyframes.retention_days' => 0]);
        $old = $this->makeFrame(400);

        $this->artisan('qds:prune-keyframes')->assertSuccessful();

        $this->assertDatabaseHas('keyframes', ['id' => $old->id]);
    }
}
