<?php

namespace Tests\Feature\Tenancy;

use App\Modules\Monitoring\Livewire\Operations\OperationsDashboard;
use App\Platform\Ingestion\Models\IngestionAlert;
use App\Platform\Ingestion\Observability\AlertService;
use App\Platform\Ingestion\Support\AlertType;
use App\Shared\Enums\RoleName;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * ADR-0019 — the P4 data-quality alerts embed tenant creator handles, so
 * they are attributed and deduplicated PER TENANT (an explicit tenant id
 * passed to AlertService), while provider-level incidents stay GLOBAL even
 * when raised from inside a tenant-bound ingestion job. The operations
 * dashboard shows an operator their own roster alerts plus the global
 * provider ones — never a competitor's roster.
 */
class CrossTenantAlertTest extends TestCase
{
    use RefreshDatabase;

    public function test_same_incident_in_two_tenants_produces_two_isolated_alerts(): void
    {
        $alerts = app(AlertService::class);

        $tenantA = $this->defaultTenant;
        $tenantB = $this->makeTenant('Tenant B');

        $alerts->raise(AlertType::MetricAnomaly, 'INSTAGRAM', '@alice 1000→0', 'critical', $tenantA->id);
        $alerts->raise(AlertType::MetricAnomaly, 'INSTAGRAM', '@bob 1000→0', 'critical', $tenantB->id);

        // Two distinct rows — the fingerprint embeds the tenant, so B's
        // incident does NOT dedupe into A's open alert.
        $this->assertSame(2, IngestionAlert::query()->count());
        $this->assertSame(1, IngestionAlert::query()->where('tenant_id', $tenantA->id)->count());
        $this->assertSame(1, IngestionAlert::query()->where('tenant_id', $tenantB->id)->count());

        // A's alert names only A's creator; B's only B's.
        $this->assertStringContainsString('@alice', (string) IngestionAlert::query()->where('tenant_id', $tenantA->id)->value('message'));
        $this->assertStringContainsString('@bob', (string) IngestionAlert::query()->where('tenant_id', $tenantB->id)->value('message'));
    }

    public function test_resolve_only_clears_the_given_tenants_alert(): void
    {
        $alerts = app(AlertService::class);

        $tenantA = $this->defaultTenant;
        $tenantB = $this->makeTenant('Tenant B');

        $alerts->raise(AlertType::SnapshotGap, 'TIKTOK', '@alice gap', 'warning', $tenantA->id);
        $alerts->raise(AlertType::SnapshotGap, 'TIKTOK', '@bob gap', 'warning', $tenantB->id);

        // Resolving for A must not touch B's open alert.
        $alerts->resolve(AlertType::SnapshotGap, 'TIKTOK', $tenantA->id);

        $this->assertNotNull(IngestionAlert::query()->where('tenant_id', $tenantA->id)->value('resolved_at'));
        $this->assertNull(IngestionAlert::query()->where('tenant_id', $tenantB->id)->value('resolved_at'));
    }

    public function test_provider_alert_stays_global_even_under_a_bound_tenant_context(): void
    {
        // Regression guard: provider/infra alerts (repeated failures, job
        // failures) are raised from inside per-account jobs that run under
        // runAs(account tenant). They must NOT be tenant-stamped — a shared
        // provider outage is global, deduped once, and visible to everyone.
        $context = app(TenantContext::class);
        $alerts = app(AlertService::class);

        $tenantA = $this->defaultTenant;

        // Raise WITHOUT a tenant id while a tenant context is bound.
        $context->runAs($tenantA, fn () => $alerts->raise(AlertType::RepeatedFailures, 'YOUTUBE', 'provider down'));

        $alert = IngestionAlert::query()->where('alert_type', AlertType::RepeatedFailures->value)->firstOrFail();
        $this->assertNull($alert->tenant_id, 'A provider alert must stay global (tenant_id NULL), not inherit the ambient tenant');

        // A second occurrence from another tenant's poll dedupes into the
        // SAME global alert — not a per-tenant duplicate.
        $tenantB = $this->makeTenant('Tenant B');
        $context->runAs($tenantB, fn () => $alerts->raise(AlertType::RepeatedFailures, 'YOUTUBE', 'provider still down'));

        $this->assertSame(1, IngestionAlert::query()->where('alert_type', AlertType::RepeatedFailures->value)->count());
        $this->assertSame(2, (int) IngestionAlert::query()->where('alert_type', AlertType::RepeatedFailures->value)->value('count'));
    }

    public function test_legacy_fingerprint_alerts_reconcile_to_the_new_scheme(): void
    {
        // Upgrade-boundary regression guard: the dedup fingerprint gained a
        // tenant segment (type|source → type|source|tenant). A provider alert
        // written by the OLD 2-part scheme and left OPEN across the upgrade
        // must still be matched by resolve()/raise() afterwards — else it
        // never clears and dedup inserts duplicates. Migration
        // 2026_07_12_100000 backfills existing rows to the 3-part scheme; this
        // proves that backfill formula matches AlertService's fingerprint.
        $alerts = app(AlertService::class);

        $now = now();
        DB::table('ingestion_alerts')->insert([
            'tenant_id' => null,
            'alert_type' => AlertType::RepeatedFailures->value,
            'source' => 'YOUTUBE',
            // Old 2-part fingerprint (pre-Prompt-2 AlertService).
            'fingerprint' => sha1(AlertType::RepeatedFailures->value.'|YOUTUBE'),
            'severity' => 'critical',
            'message' => 'legacy open alert',
            'count' => 1,
            'first_occurred_at' => $now,
            'last_occurred_at' => $now,
        ]);

        // Mirrors migration 2026_07_12_100000 up() backfill (all legacy rows
        // are global → '-' tenant sentinel).
        foreach (DB::table('ingestion_alerts')->get(['id', 'alert_type', 'source']) as $row) {
            DB::table('ingestion_alerts')->where('id', $row->id)->update([
                'fingerprint' => sha1($row->alert_type.'|'.($row->source ?? '-').'|-'),
            ]);
        }

        // After the backfill, the global resolve() matches and closes it.
        $alerts->resolve(AlertType::RepeatedFailures, 'YOUTUBE');

        $this->assertNotNull(
            IngestionAlert::query()->where('source', 'YOUTUBE')->value('resolved_at'),
            'A backfilled legacy alert must be resolvable under the new fingerprint scheme',
        );
        // resolve() matched the existing row (closed it) rather than missing
        // and leaving a stale duplicate behind.
        $this->assertSame(1, IngestionAlert::query()->where('source', 'YOUTUBE')->count());
    }

    public function test_operations_dashboard_shows_only_own_and_global_alerts(): void
    {
        $alerts = app(AlertService::class);

        $tenantA = $this->defaultTenant;
        $tenantB = $this->makeTenant('Tenant B');

        $alerts->raise(AlertType::MetricAnomaly, 'INSTAGRAM', 'OWN-tenant-alert @alice', 'warning', $tenantA->id);
        $alerts->raise(AlertType::MetricAnomaly, 'INSTAGRAM', 'FOREIGN-tenant-alert @bob', 'warning', $tenantB->id);
        // A provider-level (global) incident — no tenant id.
        $alerts->raise(AlertType::RepeatedFailures, 'YOUTUBE', 'GLOBAL-provider-alert');

        $this->seedRoles();
        $admin = $this->makeUser(RoleName::Admin); // in Tenant A, holds operations.view
        $this->actingAs($admin);

        Livewire::test(OperationsDashboard::class)
            ->assertSee('OWN-tenant-alert')
            ->assertSee('GLOBAL-provider-alert')
            ->assertDontSee('FOREIGN-tenant-alert');
    }
}
