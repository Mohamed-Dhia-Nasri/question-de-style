<?php

namespace Tests\Feature\Crm;

use App\Modules\CRM\Models\Campaign;
use App\Modules\CRM\Models\CommunicationLog;
use App\Modules\CRM\Models\SeedingCampaign;
use App\Modules\CRM\Models\Task;
use App\Shared\Enums\RoleName;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Stage D schema foundation: nullable seeding-run anchors on tasks and
 * communication_logs (tenant-scoped via a composite FK, mirroring the
 * document_attachments anchor), plus a lightweight campaign brief
 * (objective, markets).
 */
class StageDSchemaTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsCrmStaff(): void
    {
        $this->seedRoles();
        $this->actingAs($this->makeUser(RoleName::InfluencerRelationsManager));
    }

    public function test_new_columns_exist_and_relations_resolve(): void
    {
        $this->actingAsCrmStaff();
        $run = SeedingCampaign::factory()->create();
        $task = Task::factory()->create(['seeding_campaign_id' => $run->id]);
        $log = CommunicationLog::factory()->create(['seeding_campaign_id' => $run->id]);
        $campaign = Campaign::factory()->create(['objective' => 'Awareness in DACH', 'markets' => ['DE', 'AT']]);

        $this->assertTrue($task->seedingCampaign->is($run));
        $this->assertTrue($log->seedingCampaign->is($run));
        $this->assertSame(['DE', 'AT'], $campaign->fresh()->markets);
        $this->assertSame('Awareness in DACH', $campaign->fresh()->objective);
        $this->assertTrue($run->tasks->contains($task));
        $this->assertTrue($run->communicationLogs->contains($log));
    }

    public function test_seeding_anchor_is_tenant_scoped(): void
    {
        $this->actingAsCrmStaff();
        $foreignRun = $this->withTenant($this->makeTenant('Tenant B'), fn () => SeedingCampaign::factory()->create());
        // A task cannot point at a foreign-tenant run (composite FK blocks it at the DB layer).
        $this->expectException(QueryException::class);
        Task::factory()->create(['seeding_campaign_id' => $foreignRun->id]);
    }
}
