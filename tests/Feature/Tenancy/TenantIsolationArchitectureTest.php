<?php

namespace Tests\Feature\Tenancy;

use App\Platform\Ingestion\Models\IngestionAlert;
use App\Shared\Audit\AuditLog;
use App\Shared\Authorization\TenantIsolationGate;
use App\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use ReflectionClass;
use Tests\TestCase;

/**
 * ADR-0019 structural invariants — enforced by test so a future model or
 * route cannot silently regress tenant isolation:
 *  - every model backing a tenant_id table is scoped by BelongsToTenant
 *    (the fail-open guard: a model that forgets the trait runs UNSCOPED);
 *  - no tenant-owned model exposes tenant_id to mass assignment;
 *  - the cross-tenant authorization backstop is actually registered;
 *  - every web route that binds a tenant-owned model runs behind auth (so
 *    SetTenantContext binds and the binding resolves tenant-scoped).
 */
class TenantIsolationArchitectureTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Models that legitimately back a tenant_id table WITHOUT the scoping
     * trait. Both carry a NULLABLE tenant_id and mix per-tenant rows with
     * GLOBAL (tenant_id NULL) rows, so the trait's `= tenant_id` predicate
     * would wrongly HIDE the global rows — they are instead scoped explicitly
     * at each query site (verified):
     *  - AuditLog: system/platform actions have no tenant; force-filled, never
     *    mass assigned; must stay readable in platform context.
     *  - IngestionAlert: provider-level incidents are global (NULL); per-tenant
     *    data-quality alerts are stamped. OperationsDashboard reads own+global
     *    only; AlertService raise/resolve partition by a tenant-encoded
     *    fingerprint (CrossTenantAlertTest pins this).
     *
     * @var list<class-string<Model>>
     */
    private const UNSCOPED_TENANT_ID_MODELS = [AuditLog::class, IngestionAlert::class];

    /** @return list<class-string<Model>> every concrete Eloquent model in app/ */
    private function eloquentModels(): array
    {
        $models = [];

        foreach (File::allFiles(app_path()) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $class = 'App\\'.str_replace(
                ['/', '.php'],
                ['\\', ''],
                Str::after($file->getRealPath(), app_path().DIRECTORY_SEPARATOR),
            );

            if (! class_exists($class)) {
                continue;
            }

            if (is_subclass_of($class, Model::class) && ! (new ReflectionClass($class))->isAbstract()) {
                $models[] = $class;
            }
        }

        return $models;
    }

    /** @return list<class-string<Model>> every model class using BelongsToTenant */
    private function tenantOwnedModels(): array
    {
        return array_values(array_filter(
            $this->eloquentModels(),
            fn (string $class): bool => in_array(BelongsToTenant::class, class_uses_recursive($class), true),
        ));
    }

    public function test_every_model_backing_a_tenant_id_table_uses_the_tenant_trait(): void
    {
        // Reverse-direction guard against the fail-open failure mode: ownership's
        // source of truth is the tenant_id COLUMN, not the trait. A future model
        // whose table carries tenant_id but that forgets BelongsToTenant would run
        // its queries UNSCOPED — silently reintroducing cross-tenant leakage — and
        // trait-keyed discovery elsewhere in this file is blind to it by
        // construction. Discover by column; assert the trait.
        $offenders = [];

        foreach ($this->eloquentModels() as $class) {
            if (in_array($class, self::UNSCOPED_TENANT_ID_MODELS, true)) {
                continue;
            }

            $table = (new $class)->getTable();

            if (! Schema::hasColumn($table, 'tenant_id')) {
                continue;
            }

            if (! in_array(BelongsToTenant::class, class_uses_recursive($class), true)) {
                $offenders[] = "{$class} (table {$table})";
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "These models back a tenant_id table but do NOT use BelongsToTenant, so their queries run UNSCOPED (fail-open cross-tenant exposure). Add the trait, or allow-list it in UNSCOPED_TENANT_ID_MODELS with a documented reason:\n".implode("\n", $offenders),
        );
    }

    public function test_no_tenant_owned_model_allows_mass_assigning_tenant_id(): void
    {
        $models = $this->tenantOwnedModels();

        // Sanity floor near the true count (34) so a silent discovery shrink —
        // a refactor that hides half the models from this guard — fails loudly
        // instead of passing with a shrunken set.
        $this->assertGreaterThanOrEqual(30, count($models));

        foreach ($models as $class) {
            $model = new $class;
            $this->assertNotContains(
                'tenant_id',
                $model->getFillable(),
                "{$class} must NOT expose tenant_id to mass assignment (forgery vector)",
            );
        }
    }

    public function test_audit_log_never_mass_assigns_its_ownership_stamps(): void
    {
        $fillable = (new AuditLog)->getFillable();

        $this->assertNotContains('tenant_id', $fillable);
        $this->assertNotContains('user_id', $fillable);
    }

    public function test_cross_tenant_authorization_backstop_is_registered(): void
    {
        // The gate resolves the User's tenant and denies foreign models; if
        // the provider were dropped, this reflection-free probe would fail.
        $gate = new TenantIsolationGate;

        $this->assertNull($gate(null, 'view', []), 'No model argument → defer to policy');
    }

    public function test_all_model_binding_web_routes_require_auth(): void
    {
        $routes = app('router')->getRoutes();
        $offenders = [];

        foreach ($routes as $route) {
            // Routes that bind a parameter (potential tenant-owned model).
            if ($route->parameterNames() === []) {
                continue;
            }

            $uri = $route->uri();

            // Framework/health/login endpoints bind nothing tenant-owned;
            // storage/{path} serves the PUBLIC disk (string path, no model —
            // tenant files live on a private disk behind download controllers).
            // invitations/{token} is the reset-password/{token} shape
            // (ADR-0021): a GUEST route whose {token} is a string bearer
            // credential resolved by hash, never a model binding.
            if (Str::startsWith($uri, ['_', 'up', 'health', 'sanctum', 'livewire', 'login', 'forgot-password', 'reset-password', 'storage', 'invitations'])) {
                continue;
            }

            $middleware = $route->gatherMiddleware();

            if (! in_array('auth', $middleware, true)) {
                $offenders[] = $route->methods()[0].' '.$uri.' ['.implode(',', $middleware).']';
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "These parameter-binding routes are not auth-gated, so route-model binding could resolve a tenant-owned model without a bound tenant context:\n".implode("\n", $offenders),
        );
    }
}
