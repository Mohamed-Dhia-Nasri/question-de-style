<?php

namespace Tests\Feature\Crm;

use App\Models\User;
use App\Modules\CRM\Livewire\Seeding\SeedingCampaignsIndex;
use App\Modules\CRM\Models\Brand;
use App\Modules\CRM\Models\Campaign;
use App\Modules\CRM\Models\Product;
use App\Modules\CRM\Models\SeedingCampaign;
use App\Modules\CRM\Models\Shipment;
use App\Shared\Audit\AuditLog;
use App\Shared\Authorization\PermissionsCatalog;
use App\Shared\Enums\MetricTier;
use App\Shared\Enums\RoleName;
use App\Shared\Enums\SeedingCampaignStatus;
use App\Shared\Enums\SeedingType;
use App\Shared\ValueObjects\MetricValue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Seeding campaigns (REQ-M3-006, AC-M3-010): every run records exactly one
 * of the four variants and a closed-set status; the optional product must
 * belong to the run's brand; transitions are recorded.
 */
class SeedingCampaignsCrudTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsCrmStaff(): User
    {
        $this->seedRoles();

        $staff = $this->makeUser(RoleName::InfluencerRelationsManager);
        $this->actingAs($staff);

        return $staff;
    }

    public function test_component_renders_and_client_viewers_are_refused(): void
    {
        $this->actingAsCrmStaff();
        $this->get('/crm/seeding')->assertOk()->assertSeeLivewire(SeedingCampaignsIndex::class);

        $this->actingAs($this->makeUser(RoleName::ClientViewer));
        $this->get('/crm/seeding')->assertForbidden();
        Livewire::test(SeedingCampaignsIndex::class)->assertForbidden();
    }

    public function test_create_validates_the_variant_closed_set_and_required_fields(): void
    {
        $this->actingAsCrmStaff();

        Livewire::test(SeedingCampaignsIndex::class)
            ->call('create')
            ->set('seeding_name', '')
            ->set('seeding_type', 'FREEBIE')
            ->call('save')
            ->assertHasErrors([
                'seeding_name' => 'required',
                'seeding_type' => 'in',
                'seeding_brand_id' => 'required',
            ]);
    }

    public function test_edit_validates_the_status_closed_set(): void
    {
        $this->actingAsCrmStaff();

        $seeding = SeedingCampaign::factory()->create();

        Livewire::test(SeedingCampaignsIndex::class)
            ->call('edit', $seeding->id)
            ->set('seeding_status', 'RUNNING')
            ->call('save')
            ->assertHasErrors(['seeding_status' => 'in']);
    }

    public function test_a_product_of_another_brand_is_refused(): void
    {
        $this->actingAsCrmStaff();

        $brand = Brand::factory()->create();
        $foreignProduct = Product::factory()->create();

        Livewire::test(SeedingCampaignsIndex::class)
            ->call('create')
            ->set('seeding_name', 'Herbst Gifting')
            ->set('seeding_type', SeedingType::Gifting->value)
            ->set('seeding_brand_id', (string) $brand->id)
            ->set('seeding_product_id', (string) $foreignProduct->id)
            ->call('save')
            ->assertHasErrors(['seeding_product_id']);
    }

    public function test_a_parent_campaign_of_another_brand_is_refused(): void
    {
        // Deep-review finding M1: a cross-brand parent campaign would route
        // this brand's seeded-content attribution into the other brand's
        // campaign results — same coherence rule as the product guard.
        $this->actingAsCrmStaff();

        $brand = Brand::factory()->create();
        $foreignCampaign = Campaign::factory()->create();

        Livewire::test(SeedingCampaignsIndex::class)
            ->call('create')
            ->set('seeding_name', 'Herbst Gifting')
            ->set('seeding_type', SeedingType::Gifting->value)
            ->set('seeding_brand_id', (string) $brand->id)
            ->set('seeding_campaign_id', (string) $foreignCampaign->id)
            ->call('save')
            ->assertHasErrors(['seeding_campaign_id']);

        // Editing an existing run onto a mismatched campaign is blocked too.
        $seeding = SeedingCampaign::factory()->create(['brand_id' => $brand->id]);

        Livewire::test(SeedingCampaignsIndex::class)
            ->call('edit', $seeding->id)
            ->set('seeding_campaign_id', (string) $foreignCampaign->id)
            ->call('save')
            ->assertHasErrors(['seeding_campaign_id']);

        $this->assertNull($seeding->refresh()->campaign_id);
    }

    public function test_create_persists_variant_product_and_parent_campaign(): void
    {
        $this->actingAsCrmStaff();

        $brand = Brand::factory()->create();
        $product = Product::factory()->create(['brand_id' => $brand->id]);
        $campaign = Campaign::factory()->create(['brand_id' => $brand->id]);

        Livewire::test(SeedingCampaignsIndex::class)
            ->call('create')
            ->set('seeding_name', 'Herbst Gifting')
            ->set('seeding_type', SeedingType::GiftingWithPost->value)
            ->set('seeding_brand_id', (string) $brand->id)
            ->set('seeding_product_id', (string) $product->id)
            ->set('seeding_campaign_id', (string) $campaign->id)
            ->call('save')
            ->assertHasNoErrors()
            ->assertDispatched('notify', message: 'Seeding run created.');

        $seeding = SeedingCampaign::where('name', 'Herbst Gifting')->firstOrFail();
        // AC-M3-010: exactly one of the four variants + a closed-set status.
        $this->assertSame(SeedingType::GiftingWithPost, $seeding->seeding_type);
        // Create always starts as a draft (Task 2) — status closed-set
        // coverage for edit lives in test_edit_validates_the_status_closed_set.
        $this->assertSame(SeedingCampaignStatus::Draft, $seeding->status);
        $this->assertSame($product->id, $seeding->product_id);
        $this->assertSame($campaign->id, $seeding->campaign_id);
        $this->assertDatabaseHas('audit_logs', ['action' => 'seeding_campaign.created', 'subject_id' => $seeding->id]);
    }

    public function test_spend_is_stored_as_a_confirmed_metric_value(): void
    {
        $this->actingAsCrmStaff();

        $seeding = SeedingCampaign::factory()->create();

        Livewire::test(SeedingCampaignsIndex::class)
            ->call('edit', $seeding->id)
            ->set('seeding_spend', '450.75')
            ->call('save')
            ->assertHasNoErrors();

        $fresh = $seeding->fresh();
        $this->assertNotNull($fresh);
        // AC-M3-015 input: agency-entered spend rides the envelope at tier CONFIRMED.
        $this->assertInstanceOf(MetricValue::class, $fresh->spend);
        $this->assertSame(450.75, $fresh->spend->amount);
        $this->assertSame(MetricTier::Confirmed, $fresh->spend->tier);
        $this->assertSame('spend', $fresh->spend->metric);
    }

    public function test_blank_spend_clears_the_stored_value(): void
    {
        $this->actingAsCrmStaff();

        $seeding = SeedingCampaign::factory()->create([
            'spend' => new MetricValue(300.0, MetricTier::Confirmed, 'spend'),
        ]);

        Livewire::test(SeedingCampaignsIndex::class)
            ->call('edit', $seeding->id)
            ->set('seeding_spend', '')
            ->call('save')
            ->assertHasNoErrors();

        $fresh = $seeding->fresh();
        $this->assertNotNull($fresh);
        $this->assertNull($fresh->spend);
    }

    public function test_negative_spend_is_rejected(): void
    {
        $this->actingAsCrmStaff();

        $seeding = SeedingCampaign::factory()->create();

        Livewire::test(SeedingCampaignsIndex::class)
            ->call('edit', $seeding->id)
            ->set('seeding_spend', '-1')
            ->call('save')
            ->assertHasErrors(['seeding_spend' => 'min']);
    }

    public function test_status_transitions_are_recorded(): void
    {
        $this->actingAsCrmStaff();

        $seeding = SeedingCampaign::factory()->create(['status' => SeedingCampaignStatus::Draft]);

        Livewire::test(SeedingCampaignsIndex::class)
            ->call('edit', $seeding->id)
            ->set('seeding_status', SeedingCampaignStatus::Shipping->value)
            ->call('save')
            ->assertHasNoErrors();

        $log = AuditLog::query()
            ->where('action', 'seeding_campaign.status_changed')
            ->where('subject_id', $seeding->id)
            ->firstOrFail();

        $this->assertSame('DRAFT', $log->context['from']);
        $this->assertSame('SHIPPING', $log->context['to']);
    }

    public function test_delete_is_refused_while_shipments_exist(): void
    {
        $this->actingAsCrmStaff();

        $seeding = SeedingCampaign::factory()->create();
        Shipment::factory()->create(['seeding_campaign_id' => $seeding->id]);

        Livewire::test(SeedingCampaignsIndex::class)
            ->call('confirmDelete', $seeding->id)
            ->call('delete');

        $this->assertDatabaseHas('seeding_campaigns', ['id' => $seeding->id]);
    }

    public function test_mutating_actions_require_crm_manage_not_just_crm_view(): void
    {
        $this->seedRoles();

        $viewer = User::factory()->create();
        $viewer->givePermissionTo(PermissionsCatalog::CRM_VIEW);
        $this->actingAs($viewer);

        $seeding = SeedingCampaign::factory()->create();

        Livewire::test(SeedingCampaignsIndex::class)->assertOk()
            ->call('create')->assertForbidden();
        Livewire::test(SeedingCampaignsIndex::class)
            ->set('seeding_name', 'Smuggled Seeding')
            ->call('save')->assertForbidden();
        Livewire::test(SeedingCampaignsIndex::class)
            ->set('confirmingDeleteId', $seeding->id)
            ->call('delete')->assertForbidden();

        $this->assertDatabaseMissing('seeding_campaigns', ['name' => 'Smuggled Seeding']);
        $this->assertDatabaseHas('seeding_campaigns', ['id' => $seeding->id]);
    }
}
