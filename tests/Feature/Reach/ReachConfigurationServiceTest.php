<?php
namespace Tests\Feature\Reach;

use App\Modules\Monitoring\Models\ReachConfiguration;
use App\Platform\Enrichment\Reach\ReachConfigurationService;
use App\Platform\Enrichment\Support\ReachConfigurationStatus;
use App\Shared\Enums\RoleName;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class ReachConfigurationServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
    }

    /** @return array<string,mixed> */
    private function attrs(string $version = 'v1'): array
    {
        return [
            'name' => "Reach {$version}",
            'method' => 'qds-estimated-reach',
            'formula_version' => "reach-{$version}",
            'params' => ['view_weight' => 0.7, 'follower_weight' => 0.1],
            'effective_from' => now()->subDay()->toDateString(),
        ];
    }

    public function test_activating_one_config_deactivates_the_previous_active(): void
    {
        $admin = $this->makeUser(RoleName::Admin);
        $svc = app(ReachConfigurationService::class);
        $a = $svc->create($this->attrs('v1'), $admin);
        $b = $svc->create($this->attrs('v2'), $admin);
        $svc->activate($a, $admin);
        $svc->activate($b, $admin);
        $this->assertSame(ReachConfigurationStatus::Inactive, $a->refresh()->status);
        $this->assertSame(ReachConfigurationStatus::Active, $b->refresh()->status);
    }

    public function test_only_draft_configs_are_editable(): void
    {
        $admin = $this->makeUser(RoleName::Admin);
        $svc = app(ReachConfigurationService::class);
        $c = $svc->create($this->attrs(), $admin);
        $svc->activate($c, $admin);
        $this->expectException(InvalidArgumentException::class);
        $svc->update($c, ['name' => 'changed'], $admin);
    }

    public function test_invalid_params_are_rejected_on_create(): void
    {
        $admin = $this->makeUser(RoleName::Admin);
        $svc = app(ReachConfigurationService::class);
        $this->expectException(InvalidArgumentException::class);
        $svc->create([...$this->attrs(), 'params' => ['view_weight' => 1.0, 'follower_weight' => 0.0]], $admin);
    }

    public function test_non_admin_cannot_create(): void
    {
        $analyst = $this->makeUser(RoleName::Analyst);
        $svc = app(ReachConfigurationService::class);
        $this->expectException(AuthorizationException::class);
        $svc->create($this->attrs(), $analyst);
    }
}
