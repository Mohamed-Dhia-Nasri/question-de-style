<?php

namespace Tests\Feature\Billing;

use App\Models\Tenant;
use App\Models\User;
use App\Modules\Billing\Exceptions\SeatLimitExceeded;
use App\Modules\Billing\Models\SubscriptionPlan;
use App\Modules\Billing\Models\TenantSubscription;
use App\Modules\Billing\Services\SeatLimiter;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use PDO;
use Tests\TestCase;

/**
 * REAL concurrency tests for the seat race (ADR-0021: "Do not claim
 * concurrency safety without a real test").
 *
 * SeatLimiter::reserve() claims that every seat-consuming mutation
 * serializes on a `SELECT … FOR UPDATE` of the tenant row, so two
 * simultaneous invitation acceptances can never land 4+2=6 members on a
 * 5-seat plan. These tests prove it against a live PostgreSQL server by
 * opening a SECOND raw PDO connection that competes for the same row lock
 * with deterministic interleaving — no sleeps, no forked processes.
 *
 * DatabaseTruncation (NOT RefreshDatabase) is required: RefreshDatabase
 * wraps each test in an uncommitted transaction, which the second
 * connection could neither see nor lock against. Rows here really commit,
 * so TestCase::setUp() does not auto-provision the default tenant — we
 * create and bind our own, and seed roles ourselves.
 */
class SeatConcurrencyTest extends TestCase
{
    use DatabaseTruncation;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        // TestCase only auto-creates/binds the default tenant for
        // RefreshDatabase tests — with DatabaseTruncation we provision our
        // own so factories (via ResolvesTenant) have an active context.
        $this->seedRoles();
        $this->tenant = Tenant::factory()->create(['name' => 'Seat Race Tenant']);
        app(TenantContext::class)->set($this->tenant);

        // Seat limits are gated on enforcement (default false).
        config(['billing.enforced' => true]);
    }

    protected function tearDown(): void
    {
        // DatabaseTruncation COMMITS rows and only truncates at the start
        // of its own tests — without this, the LAST test's committed
        // fixtures would leak into the transaction-wrapped RefreshDatabase
        // classes that run afterwards and assert absolute row counts.
        // CASCADE follows the tenant FKs into any dependent tables (all
        // empty in this class) so ordering does not matter.
        DB::statement(
            'TRUNCATE TABLE team_invitations, tenant_subscriptions, subscription_plans, '
            .'stripe_events, audit_logs, model_has_roles, users, tenants RESTART IDENTITY CASCADE'
        );

        parent::tearDown();
    }

    /**
     * A live ACTIVE subscription on a 5-seat plan plus N committed active
     * members. Everything autocommits (no wrapping test transaction), so
     * the second connection sees these rows immediately.
     */
    private function provisionFiveSeatPlanWith(int $activeMembers): void
    {
        $plan = SubscriptionPlan::factory()->seats(5)->create();

        TenantSubscription::factory()->create(['subscription_plan_id' => $plan->id]);

        User::factory()->count($activeMembers)->create();

        $this->assertSame(
            $activeMembers,
            app(SeatLimiter::class)->activeSeats((int) $this->tenant->id),
            'Fixture sanity: the committed active-member count must match before racing.'
        );
    }

    /**
     * "Connection B" — a second, completely independent Postgres session
     * (raw PDO, not Laravel's pool) that can hold row locks against the
     * main test connection.
     */
    private function openSecondConnection(): PDO
    {
        $pdo = new PDO(
            sprintf(
                'pgsql:host=%s;port=%s;dbname=%s',
                config('database.connections.pgsql.host'),
                config('database.connections.pgsql.port'),
                config('database.connections.pgsql.database'),
            ),
            config('database.connections.pgsql.username'),
            config('database.connections.pgsql.password'),
        );

        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        return $pdo;
    }

    /** BEGIN on connection B and take the tenant row lock reserve() uses. */
    private function lockTenantRowOn(PDO $pdo): void
    {
        $pdo->beginTransaction();

        $statement = $pdo->prepare('SELECT id FROM tenants WHERE id = ? FOR UPDATE');
        $statement->execute([(int) $this->tenant->id]);

        $this->assertNotFalse(
            $statement->fetch(PDO::FETCH_ASSOC),
            'Connection B must actually hold the tenant row lock for the race to be real.'
        );
    }

    public function test_seat_mutations_serialize_on_the_tenant_row_lock(): void
    {
        $this->provisionFiveSeatPlanWith(4);

        $pdo = $this->openSecondConnection();

        try {
            // Connection B grabs the per-tenant serialization point and
            // holds it open — a concurrent acceptance mid-flight.
            $this->lockTenantRowOn($pdo);

            // A finite lock_timeout turns "reserve() queues behind the same
            // lock" into an observable QueryException instead of a hung
            // test. If reserve() did NOT take the tenant row lock, the
            // mutation would sail through and no exception would fire.
            DB::statement("SET lock_timeout = '500ms'");

            $thrown = null;

            try {
                app(SeatLimiter::class)->reserve(
                    $this->tenant,
                    fn () => User::factory()->create(['email' => 'blocked-fifth@seat-race.test'])
                );
            } catch (QueryException $exception) {
                $thrown = $exception;
            }

            $this->assertInstanceOf(
                QueryException::class,
                $thrown,
                'reserve() completed without waiting — it did not contend for the tenant row lock, so seat mutations do not serialize.'
            );
            $this->assertSame(
                '55P03',
                (string) $thrown->getCode(),
                'Expected SQLSTATE 55P03 (lock_not_available): reserve() must have been queued on the FOR UPDATE lock held by connection B.'
            );

            // Lock acquisition precedes the mutation, so nothing ran: the
            // count is untouched and the user row was never written.
            $this->assertSame(4, app(SeatLimiter::class)->activeSeats((int) $this->tenant->id));
            $this->assertDatabaseMissing('users', ['email' => 'blocked-fifth@seat-race.test']);
        } finally {
            // Restore the session default so a failure here cannot leak a
            // 500ms lock_timeout into later tests on this connection.
            DB::statement("SET lock_timeout = '0'");

            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $pdo = null;
        }
    }

    public function test_second_acceptance_recounts_after_the_first_commits(): void
    {
        // The two-simultaneous-acceptances scenario, deterministically
        // interleaved: 4 active members, 5 seats, two acceptances racing.
        $this->provisionFiveSeatPlanWith(4);

        $pdo = $this->openSecondConnection();

        try {
            // Acceptance #1 (connection B) wins the lock, seats the 5th
            // ACTIVE member and COMMITs — the exact write pattern of an
            // invitation acceptance on another PHP worker.
            $this->lockTenantRowOn($pdo);

            $insert = $pdo->prepare(
                'INSERT INTO users (tenant_id, display_name, email, password, active, created_at, updated_at)
                 VALUES (?, ?, ?, ?, TRUE, NOW(), NOW())'
            );
            $insert->execute([
                (int) $this->tenant->id,
                'Race Winner',
                'fifth-acceptance@seat-race.test',
                'irrelevant-password-hash',
            ]);

            $pdo->commit();

            // Acceptance #2 (main connection). A naive count()-then-insert
            // would have counted 4 seats BEFORE #1 committed and happily
            // seated a 6th member. reserve() recounts AFTER acquiring the
            // lock, sees 5-of-5, and must refuse.
            $thrown = null;

            try {
                app(SeatLimiter::class)->reserve(
                    $this->tenant,
                    fn () => User::factory()->create(['email' => 'sixth-acceptance@seat-race.test'])
                );
            } catch (SeatLimitExceeded $exception) {
                $thrown = $exception;
            }

            $this->assertInstanceOf(
                SeatLimitExceeded::class,
                $thrown,
                'The second acceptance was admitted — the 4+2=6 race is possible and the seat invariant is broken.'
            );
            $this->assertSame(6, $thrown->seatsUsed, 'The post-mutation recount saw the committed 5th member plus its own insert.');
            $this->assertSame(5, $thrown->seatLimit);
            $this->assertFalse($thrown->wasAlreadyOver, 'The tenant was exactly at the limit, not over it, when acceptance #2 ran.');

            // Final state: the winner's row survives, the loser's rolled
            // back, and the count never exceeded the plan's 5 seats.
            $this->assertSame(5, app(SeatLimiter::class)->activeSeats((int) $this->tenant->id));
            $this->assertDatabaseHas('users', ['email' => 'fifth-acceptance@seat-race.test', 'active' => true]);
            $this->assertDatabaseMissing('users', ['email' => 'sixth-acceptance@seat-race.test']);
        } finally {
            // Only open if the test died before COMMIT — never leave B
            // holding the tenant lock or the whole suite deadlocks.
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $pdo = null;
        }
    }

    public function test_reserve_rolls_back_the_mutation_when_the_limit_is_hit(): void
    {
        $this->provisionFiveSeatPlanWith(5);

        $thrown = null;

        try {
            app(SeatLimiter::class)->reserve(
                $this->tenant,
                fn () => User::factory()->create(['email' => 'over-the-limit@seat-race.test'])
            );
        } catch (SeatLimitExceeded $exception) {
            $thrown = $exception;
        }

        $this->assertInstanceOf(
            SeatLimitExceeded::class,
            $thrown,
            'A 6th active member on a 5-seat plan must be refused.'
        );
        $this->assertFalse(
            $thrown->wasAlreadyOver,
            'At-the-limit refusal must come from the post-mutation recount, not the pre-check for already-over-limit tenants.'
        );

        // The mutation DID run inside reserve()'s transaction — the throw
        // must have rolled its row back, not left a 6th member behind.
        $this->assertDatabaseMissing('users', ['email' => 'over-the-limit@seat-race.test']);
        $this->assertSame(5, app(SeatLimiter::class)->activeSeats((int) $this->tenant->id));
    }
}
