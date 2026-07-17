<?php

namespace Tests\Feature\Settings;

use App\Models\Tenant;
use App\Modules\CRM\Models\CommunicationLog;
use App\Modules\CRM\Models\Creator;
use App\Modules\CRM\Models\PlatformAccount;
use App\Modules\Monitoring\Models\MonitoringSetting;
use App\Modules\Monitoring\Models\Story;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * ADR-0025: retention cleanups run tenant by tenant with each workspace's
 * own keep-time — never one global number, and 0 means keep forever.
 */
class PerTenantRetentionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('media');
    }

    private function settingsFor(Tenant $tenant, int $storyDays, int $commsDays): void
    {
        $row = new MonitoringSetting([
            'shipment_window_days' => 60,
            'engagement_trend_window_days' => 30,
            'story_retention_days' => $storyDays,
            'communication_retention_days' => $commsDays,
        ]);
        $row->tenant_id = $tenant->id;
        $row->save();
    }

    private function storyFor(Tenant $tenant, string $path, int $ageDays): Story
    {
        Storage::disk('media')->put($path, 'FAKE-BYTES');

        // Nested factory relations (PlatformAccount::factory()) resolve
        // under the AMBIENT TenantContext, not whatever tenant_id we stamp
        // onto the Story afterwards — building the account under the
        // target tenant keeps the composite tenant FK (platform_account_id,
        // tenant_id) satisfied.
        $account = $this->withTenant($tenant, fn () => PlatformAccount::factory()->create());

        $story = Story::factory()->make([
            'platform_account_id' => $account->id,
            'media_url' => $path,
            'captured_at' => now()->subDays($ageDays),
        ]);
        $story->tenant_id = $tenant->id;
        $story->save();

        return $story;
    }

    public function test_each_tenant_is_pruned_with_its_own_story_retention(): void
    {
        $short = Tenant::factory()->create();
        $keeper = Tenant::factory()->create();
        $this->settingsFor($short, storyDays: 30, commsDays: 0);
        $this->settingsFor($keeper, storyDays: 0, commsDays: 0); // keep forever

        $prunedStory = $this->storyFor($short, 'stories/a/old.mp4', ageDays: 60);
        $keptYoung = $this->storyFor($short, 'stories/a/new.mp4', ageDays: 5);
        $keptForever = $this->storyFor($keeper, 'stories/b/old.mp4', ageDays: 400);

        $this->artisan('qds:prune-story-media')->assertSuccessful();

        Storage::disk('media')->assertMissing('stories/a/old.mp4');
        Storage::disk('media')->assertExists('stories/a/new.mp4');
        Storage::disk('media')->assertExists('stories/b/old.mp4');

        $this->assertNull($prunedStory->refresh()->media_url);
        $this->assertNotNull($keptYoung->refresh()->media_url);
        $this->assertNotNull($keptForever->refresh()->media_url);
    }

    public function test_each_tenant_is_pruned_with_its_own_comms_retention(): void
    {
        $short = Tenant::factory()->create();
        $keeper = Tenant::factory()->create();
        $this->settingsFor($short, storyDays: 0, commsDays: 30);
        $this->settingsFor($keeper, storyDays: 0, commsDays: 0);

        $creatorShort = Creator::factory()->create(['tenant_id' => $short->id]);
        $creatorKeeper = Creator::factory()->create(['tenant_id' => $keeper->id]);

        $pruned = CommunicationLog::factory()->create([
            'tenant_id' => $short->id,
            'creator_id' => $creatorShort->id,
            'occurred_at' => now()->subDays(60),
        ]);
        $kept = CommunicationLog::factory()->create([
            'tenant_id' => $keeper->id,
            'creator_id' => $creatorKeeper->id,
            'occurred_at' => now()->subDays(400),
        ]);

        $this->artisan('qds:gdpr-enforce-retention')->assertSuccessful();

        $this->assertDatabaseMissing('communication_logs', ['id' => $pruned->id]);
        $this->assertDatabaseHas('communication_logs', ['id' => $kept->id]);
    }
}
