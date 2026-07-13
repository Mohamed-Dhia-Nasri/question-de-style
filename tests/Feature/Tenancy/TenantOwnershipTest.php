<?php

namespace Tests\Feature\Tenancy;

use App\Modules\CRM\Models\Campaign;
use App\Modules\CRM\Models\Client;
use App\Modules\CRM\Models\Creator;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ADR-0019 — tenant ownership of business records: context stamping,
 * required ownership, and context-scoped queries.
 */
class TenantOwnershipTest extends TestCase
{
    use RefreshDatabase;

    public function test_tenant_owned_models_are_stamped_from_the_active_context(): void
    {
        $creator = Creator::factory()->create();

        $this->assertSame($this->defaultTenant->id, $creator->tenant_id);
    }

    public function test_nested_factory_chains_land_in_one_tenant(): void
    {
        $campaign = Campaign::factory()->create(); // chains Brand → Client

        $this->assertSame($this->defaultTenant->id, $campaign->tenant_id);
        $this->assertSame($this->defaultTenant->id, $campaign->brand->tenant_id);
        $this->assertSame($this->defaultTenant->id, $campaign->brand->client->tenant_id);
    }

    public function test_context_switching_owns_new_records(): void
    {
        $tenantB = $this->makeTenant('Tenant B');

        $client = $this->withTenant($tenantB, fn () => Client::factory()->create());

        $this->assertSame($tenantB->id, $client->tenant_id);
    }

    public function test_explicit_tenant_id_wins_over_context(): void
    {
        // The explicit value must DIFFER from the active context, or this
        // pins nothing (adversarial-review fix).
        $tenantB = $this->makeTenant('Tenant B');
        $tenantC = $this->makeTenant('Tenant C');

        $client = $this->withTenant(
            $tenantB,
            fn () => Client::factory()->create(['tenant_id' => $tenantC->id]),
        );

        $this->assertSame($tenantC->id, $client->tenant_id);
    }

    public function test_tenant_owned_creation_fails_loudly_without_context_or_explicit_tenant(): void
    {
        $this->expectException(QueryException::class);

        app(TenantContext::class)->runAs(null, function (): void {
            $client = new Client(['name' => 'Ownerless GmbH']);
            $client->saveQuietly();
        });
    }

    public function test_queries_are_scoped_to_the_active_tenant_context(): void
    {
        Creator::factory()->count(2)->create();

        $tenantB = $this->makeTenant('Tenant B');
        $this->withTenant($tenantB, fn () => Creator::factory()->create());

        // Context = default tenant → only its two creators are visible.
        $this->assertSame(2, Creator::query()->count());

        // Context = tenant B → only its one creator.
        $this->assertSame(1, $this->withTenant($tenantB, fn () => Creator::query()->count()));

        // Platform context (null) → unscoped; hard enforcement is Phase 2.
        $this->assertSame(3, app(TenantContext::class)->runAs(null, fn () => Creator::query()->count()));
    }

    public function test_queued_jobs_inherit_and_restore_the_dispatchers_tenant_context(): void
    {
        // The sync driver shares the dispatcher's in-process context, which
        // would mask a broken payload propagation — use the database driver
        // and a real worker pass so the tenant genuinely travels through
        // the payload (adversarial-review fix).
        config(['queue.default' => 'database']);

        $context = app(TenantContext::class);

        dispatch(new CaptureTenantContextJob);

        $payload = json_decode((string) DB::table('jobs')->value('payload'), true);
        $this->assertSame(
            $this->defaultTenant->id,
            $payload['tenantId'] ?? null,
            'the queue payload must record the dispatching tenant',
        );

        // A fresh worker has no ambient context.
        $context->clear();
        CaptureTenantContextJob::$seenTenantId = -1;

        $this->artisan('queue:work', ['--once' => true, '--sleep' => 0])->assertSuccessful();

        $this->assertSame(
            $this->defaultTenant->id,
            CaptureTenantContextJob::$seenTenantId,
            'the worker must restore the dispatching tenant from the payload',
        );
        $this->assertNull($context->id(), 'the worker context must be restored (pre-job state) afterwards');
    }

    public function test_a_failing_sync_job_does_not_clobber_the_dispatchers_context(): void
    {
        // A single job emits TWO terminal queue events on failure paths —
        // the pop must fire exactly once per job or the second pop nulls
        // the request's own context (adversarial-review fix).
        $context = app(TenantContext::class);

        // Path 1: the job calls $this->fail() and returns
        // (JobFailed then JobProcessed).
        dispatch(new FailQuietlyJob);
        $this->assertSame(
            $this->defaultTenant->id,
            $context->id(),
            'fail()-and-return sync job must leave the dispatcher context intact',
        );

        // Path 2: an exception escapes handle()
        // (JobExceptionOccurred then JobFailed).
        try {
            dispatch(new AlwaysThrowsJob);
        } catch (\RuntimeException) {
            // expected — sync dispatch rethrows
        }

        $this->assertSame(
            $this->defaultTenant->id,
            $context->id(),
            'a throwing sync job must leave the dispatcher context intact',
        );
    }
}

class CaptureTenantContextJob implements ShouldQueue
{
    use Dispatchable, \Illuminate\Queue\InteractsWithQueue, Queueable;

    public static ?int $seenTenantId = null;

    public function handle(): void
    {
        self::$seenTenantId = app(TenantContext::class)->id();
    }
}

class FailQuietlyJob implements ShouldQueue
{
    use Dispatchable, \Illuminate\Queue\InteractsWithQueue, Queueable;

    public function handle(): void
    {
        $this->fail(new \RuntimeException('permanent provider error'));
    }
}

class AlwaysThrowsJob implements ShouldQueue
{
    use Dispatchable, \Illuminate\Queue\InteractsWithQueue, Queueable;

    public function handle(): void
    {
        throw new \RuntimeException('boom');
    }
}
