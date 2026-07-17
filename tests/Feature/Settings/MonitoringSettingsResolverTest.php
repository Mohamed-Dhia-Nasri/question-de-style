<?php

namespace Tests\Feature\Settings;

use App\Models\Tenant;
use App\Modules\Monitoring\Models\MonitoringSetting;
use App\Shared\Settings\MonitoringSettingsResolver;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Context-safe settings reads (ADR-0025). The trap this kills: a tenant-
 * less read must fall back to config defaults — NEVER to another tenant's
 * latest row (the documented MonitoringPlanSetting::current() limitation).
 */
class MonitoringSettingsResolverTest extends TestCase
{
    use RefreshDatabase;

    private function saveRow(Tenant $tenant, array $overrides = []): void
    {
        $row = new MonitoringSetting(array_merge([
            'shipment_window_days' => 60,
            'engagement_trend_window_days' => 30,
            'story_retention_days' => 180,
            'communication_retention_days' => 0,
        ], $overrides));
        $row->tenant_id = $tenant->id;
        $row->save();
    }

    public function test_no_tenant_context_returns_config_defaults_never_another_tenants_row(): void
    {
        app(TenantContext::class)->clear();

        $other = Tenant::factory()->create();
        $this->saveRow($other, ['shipment_window_days' => 7, 'engagement_trend_window_days' => 14]);

        $resolver = app(MonitoringSettingsResolver::class);

        $this->assertSame(60, $resolver->shipmentWindowDays());
        $this->assertSame(30, $resolver->engagementTrendWindowDays());
    }

    public function test_active_context_reads_that_tenants_latest_row(): void
    {
        $tenant = Tenant::factory()->create();
        $this->saveRow($tenant, ['shipment_window_days' => 45]);
        $this->saveRow($tenant, ['shipment_window_days' => 10, 'engagement_trend_window_days' => 14]);

        $days = app(TenantContext::class)->runAs(
            $tenant->id,
            fn (): array => [
                app(MonitoringSettingsResolver::class)->shipmentWindowDays(),
                app(MonitoringSettingsResolver::class)->engagementTrendWindowDays(),
            ],
        );

        $this->assertSame([10, 14], $days);
    }

    public function test_explicit_tenant_reads_are_isolated_per_tenant_with_config_fallback(): void
    {
        $configured = Tenant::factory()->create();
        $bare = Tenant::factory()->create();
        $this->saveRow($configured, ['story_retention_days' => 30, 'communication_retention_days' => 365]);

        $resolver = app(MonitoringSettingsResolver::class);

        $this->assertSame(30, $resolver->storyRetentionDaysFor($configured->id));
        $this->assertSame(365, $resolver->communicationRetentionDaysFor($configured->id));
        // No row → the existing config defaults (story 180, comms 0 = keep forever).
        $this->assertSame(180, $resolver->storyRetentionDaysFor($bare->id));
        $this->assertSame(0, $resolver->communicationRetentionDaysFor($bare->id));
    }

    public function test_trend_window_config_default_is_30(): void
    {
        $this->assertSame(30, config('qds.enrichment.engagement_trend_window_days'));
    }
}
