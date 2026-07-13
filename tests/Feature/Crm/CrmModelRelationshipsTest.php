<?php

namespace Tests\Feature\Crm;

use App\Modules\CRM\Models\Brand;
use App\Modules\CRM\Models\BrandPreference;
use App\Modules\CRM\Models\Campaign;
use App\Modules\CRM\Models\CommunicationLog;
use App\Modules\CRM\Models\Contact;
use App\Modules\CRM\Models\Creator;
use App\Modules\CRM\Models\DocumentAttachment;
use App\Modules\CRM\Models\Product;
use App\Modules\CRM\Models\SeedingCampaign;
use App\Modules\CRM\Models\Shipment;
use App\Modules\CRM\Models\Task;
use App\Shared\Enums\RoleName;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Every new M3 entity is persistable via its factory and its canonical
 * relations resolve (spec §6: factories exist for every new entity).
 */
class CrmModelRelationshipsTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_new_entity_is_persistable_via_its_factory(): void
    {
        $models = [
            Product::class => 'products',
            Contact::class => 'contacts',
            BrandPreference::class => 'brand_preferences',
            SeedingCampaign::class => 'seeding_campaigns',
            Shipment::class => 'shipments',
            CommunicationLog::class => 'communication_logs',
            DocumentAttachment::class => 'document_attachments',
            Task::class => 'tasks',
        ];

        foreach ($models as $model => $table) {
            $record = $model::factory()->create();
            $this->assertDatabaseHas($table, ['id' => $record->id]);
        }
    }

    public function test_product_belongs_to_brand_and_brand_lists_products(): void
    {
        $product = Product::factory()->create();

        $this->assertInstanceOf(Brand::class, $product->brand);
        $this->assertTrue($product->brand->products()->whereKey($product->id)->exists());
    }

    public function test_creator_reaches_contacts_and_brand_preferences(): void
    {
        $creator = Creator::factory()->create();
        $contact = Contact::factory()->create(['creator_id' => $creator->id]);
        $preference = BrandPreference::factory()->create(['creator_id' => $creator->id]);

        $this->assertTrue($creator->contacts()->whereKey($contact->id)->exists());
        $this->assertTrue($creator->brandPreferences()->whereKey($preference->id)->exists());
        $this->assertSame($creator->id, $contact->creator->id);
        $this->assertSame($creator->id, $preference->creator->id);
    }

    public function test_seeding_campaign_resolves_campaign_brand_product_and_shipments(): void
    {
        $seedingCampaign = SeedingCampaign::factory()
            ->forCampaign()
            ->withProduct()
            ->create();
        $shipment = Shipment::factory()->create(['seeding_campaign_id' => $seedingCampaign->id]);

        $this->assertInstanceOf(Campaign::class, $seedingCampaign->campaign);
        $this->assertInstanceOf(Brand::class, $seedingCampaign->brand);
        $this->assertInstanceOf(Product::class, $seedingCampaign->product);
        $this->assertTrue($seedingCampaign->shipments()->whereKey($shipment->id)->exists());
    }

    public function test_shipment_resolves_its_anchors(): void
    {
        $shipment = Shipment::factory()->create();

        $this->assertInstanceOf(SeedingCampaign::class, $shipment->seedingCampaign);
        $this->assertInstanceOf(Creator::class, $shipment->creator);
        $this->assertInstanceOf(Product::class, $shipment->product);
    }

    public function test_communication_log_and_document_and_task_resolve_campaign_anchors(): void
    {
        $campaign = Campaign::factory()->create();

        $log = CommunicationLog::factory()->create(['campaign_id' => $campaign->id]);
        $document = DocumentAttachment::factory()->create(['campaign_id' => $campaign->id, 'creator_id' => null]);
        $task = Task::factory()->create(['campaign_id' => $campaign->id]);

        $this->assertTrue($campaign->communicationLogs()->whereKey($log->id)->exists());
        $this->assertTrue($campaign->documentAttachments()->whereKey($document->id)->exists());
        $this->assertTrue($campaign->tasks()->whereKey($task->id)->exists());
    }

    public function test_document_attachment_resolves_a_seeding_run_anchor(): void
    {
        // Documents attach to seeding runs too (AC-M3-016; spec D6).
        $seeding = SeedingCampaign::factory()->create();

        $document = DocumentAttachment::factory()->create([
            'creator_id' => null,
            'seeding_campaign_id' => $seeding->id,
        ]);

        $this->assertInstanceOf(SeedingCampaign::class, $document->seedingCampaign);
        $this->assertTrue($seeding->documentAttachments()->whereKey($document->id)->exists());
    }

    public function test_task_assignee_resolves_to_a_user(): void
    {
        $this->seedRoles();
        $user = $this->makeUser(RoleName::CampaignManager);

        $task = Task::factory()->create(['assignee_user_id' => $user->id]);

        $this->assertNotNull($task->assignee);
        $this->assertSame($user->id, $task->assignee->id);
    }
}
