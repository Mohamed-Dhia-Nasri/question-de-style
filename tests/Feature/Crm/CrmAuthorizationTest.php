<?php

namespace Tests\Feature\Crm;

use App\Modules\CRM\Models\Brand;
use App\Modules\CRM\Models\BrandPreference;
use App\Modules\CRM\Models\Campaign;
use App\Modules\CRM\Models\Client;
use App\Modules\CRM\Models\CommunicationLog;
use App\Modules\CRM\Models\Contact;
use App\Modules\CRM\Models\Creator;
use App\Modules\CRM\Models\DocumentAttachment;
use App\Modules\CRM\Models\PlatformAccount;
use App\Modules\CRM\Models\Product;
use App\Modules\CRM\Models\SeedingCampaign;
use App\Modules\CRM\Models\Shipment;
use App\Modules\CRM\Models\Task;
use App\Shared\Enums\RoleName;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Every M3-owned CRM record is policy-guarded: staff read with crm.view
 * and write with crm.manage; CLIENT_VIEWER (approved reports only,
 * REQ-M3-012) holds neither. User/Role writes stay ADMIN-only (AC-M3-018,
 * covered by UsersCrudTest).
 */
class CrmAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    /** All 13 policy-guarded CRM model classes (User has its own policy). */
    private const CRM_MODELS = [
        Client::class,
        Brand::class,
        Creator::class,
        PlatformAccount::class,
        Campaign::class,
        Product::class,
        Contact::class,
        BrandPreference::class,
        SeedingCampaign::class,
        Shipment::class,
        CommunicationLog::class,
        DocumentAttachment::class,
        Task::class,
    ];

    public function test_every_staff_role_can_view_and_manage_crm_records(): void
    {
        $this->seedRoles();

        foreach (RoleName::staff() as $role) {
            $user = $this->makeUser($role);

            foreach (self::CRM_MODELS as $model) {
                $this->assertTrue(
                    $user->can('viewAny', $model),
                    "{$role->value} should view {$model}"
                );
                $this->assertTrue(
                    $user->can('create', $model),
                    "{$role->value} should manage {$model}"
                );
            }
        }
    }

    public function test_client_viewer_can_neither_view_nor_manage_crm_records(): void
    {
        $this->seedRoles();
        $viewer = $this->makeUser(RoleName::ClientViewer);

        foreach (self::CRM_MODELS as $model) {
            $this->assertFalse($viewer->can('viewAny', $model), "CLIENT_VIEWER must not view {$model}");
            $this->assertFalse($viewer->can('create', $model), "CLIENT_VIEWER must not manage {$model}");
        }
    }

    public function test_update_and_delete_follow_the_manage_permission(): void
    {
        $this->seedRoles();
        $manager = $this->makeUser(RoleName::CampaignManager);
        $viewer = $this->makeUser(RoleName::ClientViewer);

        $product = Product::factory()->create();

        $this->assertTrue($manager->can('update', $product));
        $this->assertTrue($manager->can('delete', $product));
        $this->assertFalse($viewer->can('update', $product));
        $this->assertFalse($viewer->can('delete', $product));
    }
}
