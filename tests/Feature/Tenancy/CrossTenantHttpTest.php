<?php

namespace Tests\Feature\Tenancy;

use App\Models\User;
use App\Modules\CRM\Models\Brand;
use App\Modules\CRM\Models\Campaign;
use App\Modules\CRM\Models\Creator;
use App\Modules\CRM\Models\PlatformAccount;
use App\Modules\CRM\Models\SeedingCampaign;
use App\Modules\Monitoring\Models\ContentItem;
use App\Modules\Monitoring\Models\Story;
use App\Platform\Export\Models\ExportJob;
use App\Platform\Export\Support\ExportJobStatus;
use App\Shared\Enums\RoleName;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * ADR-0019 — IDOR is closed by tenant-scoped route-model binding. Every
 * authenticated web route binds through SetTenantContext (pinned before
 * SubstituteBindings), so a foreign-tenant id resolves to 404 — the
 * resource's existence is never even disclosed — for a fully-privileged
 * ADMIN of Tenant A. A validly-signed download URL for a Tenant B artifact
 * 404s just the same.
 */
class CrossTenantHttpTest extends TestCase
{
    use RefreshDatabase;

    private User $adminA;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
        $this->adminA = $this->makeUser(RoleName::Admin);
        $this->actingAs($this->adminA);
    }

    public function test_crm_detail_routes_404_on_a_foreign_tenant_id(): void
    {
        $tenantB = $this->makeTenant('Tenant B');

        [$creator, $campaign, $seeding, $brand] = $this->withTenant($tenantB, fn () => [
            Creator::factory()->create(),
            Campaign::factory()->create(),
            SeedingCampaign::factory()->create(),
            Brand::factory()->create(),
        ]);

        $this->get("/crm/creators/{$creator->id}")->assertNotFound();
        $this->get("/crm/campaigns/{$campaign->id}")->assertNotFound();
        $this->get("/crm/seeding/{$seeding->id}")->assertNotFound();
        $this->get("/crm/brands/{$brand->id}")->assertNotFound();
    }

    public function test_own_tenant_crm_detail_routes_still_resolve(): void
    {
        // Same shapes in the acting tenant A resolve normally — the 404s
        // above are tenant isolation, not a broken route.
        $creator = Creator::factory()->create();
        $campaign = Campaign::factory()->create();

        $this->get("/crm/creators/{$creator->id}")->assertOk();
        $this->get("/crm/campaigns/{$campaign->id}")->assertOk();
    }

    public function test_monitoring_detail_routes_404_on_a_foreign_tenant_id(): void
    {
        $tenantB = $this->makeTenant('Tenant B');

        [$creator, $content] = $this->withTenant($tenantB, function () {
            $creator = Creator::factory()->create();
            $account = PlatformAccount::factory()->create(['creator_id' => $creator->id]);

            return [$creator, ContentItem::factory()->create(['platform_account_id' => $account->id])];
        });

        $this->get("/monitoring/creators/{$creator->id}")->assertNotFound();
        $this->get("/monitoring/content/{$content->id}")->assertNotFound();
    }

    public function test_signed_export_download_404s_for_a_foreign_tenant_job(): void
    {
        $tenantB = $this->makeTenant('Tenant B');

        $foreignJob = $this->withTenant($tenantB, fn () => ExportJob::factory()->create([
            'status' => ExportJobStatus::Completed,
            'expires_at' => now()->addDay(),
        ]));

        // A VALID signature for the foreign job id — isolation must not rest
        // on the signature being unguessable. The scoped binding 404s before
        // the policy/expiry ever run.
        $signed = URL::signedRoute('exports.download', ['exportJob' => $foreignJob->id]);

        $this->get($signed)->assertNotFound();
    }

    public function test_story_media_url_issue_404s_for_a_foreign_tenant_story(): void
    {
        $tenantB = $this->makeTenant('Tenant B');

        $foreignStory = $this->withTenant($tenantB, fn () => Story::factory()->create([
            'media_url' => 'tenants/999/stories/x.mp4',
        ]));

        // The mint endpoint resolves {story} tenant-scoped → 404, so a
        // Tenant A user can never even obtain a signed URL for Tenant B media.
        $this->getJson("/monitoring/stories/{$foreignStory->id}/media-url")->assertNotFound();
    }
}
