<?php

namespace Tests\Feature\Crm;

use App\Models\User;
use App\Modules\CRM\Livewire\Seeding\ShipmentsPanel;
use App\Modules\CRM\Models\Brand;
use App\Modules\CRM\Models\Campaign;
use App\Modules\CRM\Models\Creator;
use App\Modules\CRM\Models\PlatformAccount;
use App\Modules\CRM\Models\Product;
use App\Modules\CRM\Models\SeedingCampaign;
use App\Modules\CRM\Models\Shipment;
use App\Modules\Monitoring\Models\ContentItem;
use App\Modules\Monitoring\Models\Mention;
use App\Shared\Audit\AuditLog;
use App\Shared\Authorization\PermissionsCatalog;
use App\Shared\Enums\MetricTier;
use App\Shared\Enums\RoleName;
use App\Shared\Enums\ShipmentStatus;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Shipments (REQ-M3-007, AC-M3-012) + the operator half of XMC-002:
 * manual status updates with recorded transitions, recipient/product
 * coherence checks, and the confirm/deny content-link actions that drive
 * posted/postedAt and the mention campaign attribution.
 */
class ShipmentsPanelTest extends TestCase
{
    use RefreshDatabase;

    private SeedingCampaign $seeding;

    private Creator $creator;

    private Product $product;

    private function actingAsCrmStaff(): User
    {
        $this->seedRoles();

        $staff = $this->makeUser(RoleName::InfluencerRelationsManager);
        $this->actingAs($staff);

        return $staff;
    }

    private function makeSeedingRun(?int $campaignId = null): void
    {
        $brand = Brand::factory()->create();
        $this->seeding = SeedingCampaign::factory()->create([
            'brand_id' => $brand->id,
            'campaign_id' => $campaignId,
        ]);
        $this->creator = Creator::factory()->create();
        $this->seeding->creators()->attach($this->creator->id);
        $this->product = Product::factory()->create(['brand_id' => $brand->id]);
    }

    public function test_client_viewers_cannot_mount_the_panel(): void
    {
        $this->seedRoles();
        $this->actingAs($this->makeUser(RoleName::ClientViewer));

        Livewire::test(ShipmentsPanel::class, ['seedingCampaign' => SeedingCampaign::factory()->create()])
            ->assertForbidden();
    }

    public function test_a_shipment_is_created_for_an_attached_creator_with_confirmed_tier_value(): void
    {
        $this->actingAsCrmStaff();
        $this->makeSeedingRun();

        Livewire::test(ShipmentsPanel::class, ['seedingCampaign' => $this->seeding])
            ->call('create')
            ->set('shipment_creator_id', (string) $this->creator->id)
            ->set('shipment_product_id', (string) $this->product->id)
            ->set('shipment_status', ShipmentStatus::Preparing->value)
            ->set('shipment_quantity', '2')
            ->set('shipment_value', '99.80')
            ->set('shipment_posting_required', true)
            ->call('save')
            ->assertHasNoErrors();

        $shipment = Shipment::where('seeding_campaign_id', $this->seeding->id)->firstOrFail();
        $this->assertSame(ShipmentStatus::Preparing, $shipment->status);
        $this->assertSame(2, $shipment->quantity);
        $this->assertSame(99.80, $shipment->product_value_at_ship->amount);
        $this->assertSame(MetricTier::Confirmed, $shipment->product_value_at_ship->tier);
        $this->assertTrue($shipment->posting_required);
        // posted/postedAt are matching-owned — a new shipment is never "posted".
        $this->assertFalse((bool) $shipment->posted);
        $this->assertDatabaseHas('audit_logs', ['action' => 'shipment.created', 'subject_id' => $shipment->id]);
    }

    public function test_unattached_recipients_and_foreign_brand_products_are_refused(): void
    {
        $this->actingAsCrmStaff();
        $this->makeSeedingRun();

        Livewire::test(ShipmentsPanel::class, ['seedingCampaign' => $this->seeding])
            ->call('create')
            ->set('shipment_creator_id', (string) Creator::factory()->create()->id)
            ->set('shipment_product_id', (string) $this->product->id)
            ->set('shipment_status', ShipmentStatus::Pending->value)
            ->call('save')
            ->assertHasErrors(['shipment_creator_id']);

        Livewire::test(ShipmentsPanel::class, ['seedingCampaign' => $this->seeding])
            ->call('create')
            ->set('shipment_creator_id', (string) $this->creator->id)
            ->set('shipment_product_id', (string) Product::factory()->create()->id)
            ->set('shipment_status', ShipmentStatus::Pending->value)
            ->call('save')
            ->assertHasErrors(['shipment_product_id']);

        $this->assertDatabaseCount('shipments', 0);
    }

    public function test_status_transitions_are_recorded(): void
    {
        $this->actingAsCrmStaff();
        $this->makeSeedingRun();

        $shipment = Shipment::factory()->create([
            'seeding_campaign_id' => $this->seeding->id,
            'creator_id' => $this->creator->id,
            'product_id' => $this->product->id,
            'status' => ShipmentStatus::Pending,
        ]);

        Livewire::test(ShipmentsPanel::class, ['seedingCampaign' => $this->seeding])
            ->call('edit', $shipment->id)
            ->set('shipment_status', ShipmentStatus::Shipped->value)
            ->set('shipment_shipped_at', '2026-07-01T09:00')
            ->call('save')
            ->assertHasNoErrors();

        $log = AuditLog::query()
            ->where('action', 'shipment.status_changed')
            ->where('subject_id', $shipment->id)
            ->firstOrFail();

        $this->assertSame('PENDING', $log->context['from']);
        $this->assertSame('SHIPPED', $log->context['to']);
    }

    public function test_manually_linking_content_confirms_the_match_end_to_end(): void
    {
        $this->actingAsCrmStaff();
        $campaign = Campaign::factory()->create();
        $this->makeSeedingRun($campaign->id);

        $shipment = Shipment::factory()->create([
            'seeding_campaign_id' => $this->seeding->id,
            'creator_id' => $this->creator->id,
            'product_id' => $this->product->id,
        ]);

        $account = PlatformAccount::factory()->forCreator($this->creator)->create();
        $content = ContentItem::factory()->create([
            'platform_account_id' => $account->id,
            'published_at' => CarbonImmutable::parse('2026-06-15 08:00:00'),
        ]);
        $mention = Mention::factory()->create(['content_item_id' => $content->id, 'campaign_id' => null]);

        Livewire::test(ShipmentsPanel::class, ['seedingCampaign' => $this->seeding])
            ->call('openLinkForm', $shipment->id)
            ->set('link_content_id', (string) $content->id)
            ->call('linkContent')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('shipment_resulting_content', [
            'shipment_id' => $shipment->id,
            'content_item_id' => $content->id,
        ]);

        $shipment->refresh();
        $this->assertTrue($shipment->posted);
        $this->assertTrue($shipment->posted_at->equalTo('2026-06-15 08:00:00'));

        // XMC-002 confirm: the mention now carries the parent campaign.
        $this->assertSame($campaign->id, $mention->refresh()->campaign_id);
        $this->assertDatabaseHas('audit_logs', ['action' => 'match.confirmed', 'subject_id' => $shipment->id]);
    }

    public function test_content_of_another_creator_cannot_be_linked(): void
    {
        $this->actingAsCrmStaff();
        $this->makeSeedingRun();

        $shipment = Shipment::factory()->create([
            'seeding_campaign_id' => $this->seeding->id,
            'creator_id' => $this->creator->id,
            'product_id' => $this->product->id,
        ]);

        $foreignContent = ContentItem::factory()->create();

        Livewire::test(ShipmentsPanel::class, ['seedingCampaign' => $this->seeding])
            ->call('openLinkForm', $shipment->id)
            ->set('link_content_id', (string) $foreignContent->id)
            ->call('linkContent')
            ->assertHasErrors(['link_content_id']);

        $this->assertDatabaseCount('shipment_resulting_content', 0);
    }

    public function test_unlinking_denies_the_match_and_recomputes_the_posted_state(): void
    {
        $this->actingAsCrmStaff();
        $campaign = Campaign::factory()->create();
        $this->makeSeedingRun($campaign->id);

        $shipment = Shipment::factory()->create([
            'seeding_campaign_id' => $this->seeding->id,
            'creator_id' => $this->creator->id,
            'product_id' => $this->product->id,
        ]);

        $account = PlatformAccount::factory()->forCreator($this->creator)->create();
        $content = ContentItem::factory()->create(['platform_account_id' => $account->id]);
        $mention = Mention::factory()->create(['content_item_id' => $content->id, 'campaign_id' => $campaign->id]);

        $shipment->resultingContent()->attach($content->id);
        $shipment->forceFill(['posted' => true, 'posted_at' => $content->published_at])->save();

        Livewire::test(ShipmentsPanel::class, ['seedingCampaign' => $this->seeding])
            ->call('confirmUnlink', $shipment->id, $content->id)
            ->call('unlink');

        $this->assertDatabaseMissing('shipment_resulting_content', [
            'shipment_id' => $shipment->id,
            'content_item_id' => $content->id,
        ]);

        $shipment->refresh();
        $this->assertFalse((bool) $shipment->posted);
        $this->assertNull($shipment->posted_at);

        // XMC-002 deny: the campaign attribution is retracted.
        $this->assertNull($mention->refresh()->campaign_id);
        $this->assertDatabaseHas('audit_logs', ['action' => 'match.denied', 'subject_id' => $shipment->id]);
    }

    public function test_unlinking_one_of_two_shipments_keeps_the_surviving_attribution(): void
    {
        // Deep-review finding M3: when two shipments of the same run both
        // link the content, removing ONE link must not retract the campaign
        // attribution the other link still justifies; removing the LAST
        // link retracts it.
        $this->actingAsCrmStaff();
        $campaign = Campaign::factory()->create();
        $this->makeSeedingRun($campaign->id);

        $first = Shipment::factory()->create([
            'seeding_campaign_id' => $this->seeding->id,
            'creator_id' => $this->creator->id,
            'product_id' => $this->product->id,
        ]);
        $second = Shipment::factory()->create([
            'seeding_campaign_id' => $this->seeding->id,
            'creator_id' => $this->creator->id,
            'product_id' => $this->product->id,
        ]);

        $account = PlatformAccount::factory()->forCreator($this->creator)->create();
        $content = ContentItem::factory()->create(['platform_account_id' => $account->id]);
        $mention = Mention::factory()->create(['content_item_id' => $content->id, 'campaign_id' => $campaign->id]);

        $first->resultingContent()->attach($content->id);
        $second->resultingContent()->attach($content->id);

        Livewire::test(ShipmentsPanel::class, ['seedingCampaign' => $this->seeding])
            ->call('confirmUnlink', $first->id, $content->id)
            ->call('unlink');

        // The second link still justifies the attribution — not retracted.
        $this->assertSame($campaign->id, $mention->refresh()->campaign_id);

        Livewire::test(ShipmentsPanel::class, ['seedingCampaign' => $this->seeding])
            ->call('confirmUnlink', $second->id, $content->id)
            ->call('unlink');

        // Last link gone — now the retraction is correct.
        $this->assertNull($mention->refresh()->campaign_id);
    }

    public function test_mutating_actions_require_crm_manage_not_just_crm_view(): void
    {
        $this->seedRoles();

        $viewer = User::factory()->create();
        $viewer->givePermissionTo(PermissionsCatalog::CRM_VIEW);
        $this->actingAs($viewer);

        $seeding = SeedingCampaign::factory()->create();
        $shipment = Shipment::factory()->create(['seeding_campaign_id' => $seeding->id]);

        Livewire::test(ShipmentsPanel::class, ['seedingCampaign' => $seeding])->assertOk()
            ->call('create')->assertForbidden();
        Livewire::test(ShipmentsPanel::class, ['seedingCampaign' => $seeding])
            ->set('shipment_creator_id', '1')
            ->call('save')->assertForbidden();
        Livewire::test(ShipmentsPanel::class, ['seedingCampaign' => $seeding])
            ->set('linkingShipmentId', $shipment->id)
            ->set('link_content_id', (string) ContentItem::factory()->create()->id)
            ->call('linkContent')->assertForbidden();
        Livewire::test(ShipmentsPanel::class, ['seedingCampaign' => $seeding])
            ->set('confirmingDeleteId', $shipment->id)
            ->call('delete')->assertForbidden();

        $this->assertDatabaseHas('shipments', ['id' => $shipment->id]);
        $this->assertDatabaseCount('shipment_resulting_content', 0);
    }
}
