<?php

namespace Tests\Feature\AiBudget;

use App\Platform\AiBudget\AiBudgetGuard;
use App\Platform\AiBudget\Models\TenantAiQuota;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Spec §10 operator surface: the emergency read-only flag and per-tenant
 * quota overrides, both managed from the console in v1 (self-serve quota
 * purchase lands with the billing module later).
 */
class AiBudgetCommandsTest extends TestCase
{
    use RefreshDatabase;

    public function test_read_only_command_flips_and_reports_the_cache_flag(): void
    {
        $this->artisan('qds:ai-read-only', ['mode' => 'on'])
            ->expectsOutputToContain('read-only mode is ON')
            ->assertExitCode(0);
        $this->assertTrue(app(AiBudgetGuard::class)->readOnly());

        $this->artisan('qds:ai-read-only', ['mode' => 'status'])
            ->expectsOutputToContain('ON (cache flag)')
            ->assertExitCode(0);

        $this->artisan('qds:ai-read-only', ['mode' => 'off'])->assertExitCode(0);
        $this->assertFalse(app(AiBudgetGuard::class)->readOnly());

        $this->artisan('qds:ai-read-only', ['mode' => 'sideways'])->assertExitCode(1);
    }

    public function test_quota_command_sets_shows_and_clears_overrides(): void
    {
        $tenantId = $this->defaultTenant->id;

        $this->artisan('qds:ai-quota', ['tenant' => $tenantId, 'capability' => 'embedding', '--daily' => 500])
            ->expectsOutputToContain('daily 500 units (override)')
            ->assertExitCode(0);

        $row = TenantAiQuota::query()->firstOrFail();
        $this->assertSame(500, $row->daily_units);
        $this->assertNull($row->monthly_units); // untouched column stays NULL → config default

        $this->artisan('qds:ai-quota', ['tenant' => $tenantId, 'capability' => 'embedding'])
            ->expectsOutputToContain('monthly 40000 units (config default)')
            ->assertExitCode(0);

        $this->artisan('qds:ai-quota', ['tenant' => $tenantId, 'capability' => 'embedding', '--clear' => true])
            ->expectsOutputToContain('Cleared')
            ->assertExitCode(0);
        $this->assertSame(0, TenantAiQuota::query()->count());
    }

    public function test_quota_command_rejects_unknown_tenant_or_capability(): void
    {
        $this->artisan('qds:ai-quota', ['tenant' => 999999, 'capability' => 'embedding'])->assertExitCode(1);

        $this->artisan('qds:ai-quota', ['tenant' => $this->defaultTenant->id, 'capability' => 'nope'])->assertExitCode(1);

        $this->assertSame(0, TenantAiQuota::query()->count());
    }
}
