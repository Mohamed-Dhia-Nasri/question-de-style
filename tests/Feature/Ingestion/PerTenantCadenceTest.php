<?php

namespace Tests\Feature\Ingestion;

use App\Models\Tenant;
use App\Modules\CRM\Models\Creator;
use App\Modules\CRM\Models\PlatformAccount;
use App\Modules\Monitoring\Models\MonitoredSubject;
use App\Platform\Ingestion\Jobs\IngestStoriesBatchJob;
use App\Platform\Ingestion\Jobs\PollMonitoredAccountJob;
use App\Platform\Ingestion\Jobs\RunMonitoringCycleJob;
use App\Platform\Ingestion\Models\MonitoringPlanSetting;
use App\Platform\Ingestion\Models\ProviderCall;
use App\Platform\Ingestion\SourceRegistry;
use App\Platform\Ingestion\Support\CallOutcome;
use App\Shared\Enums\MonitoredSubjectType;
use App\Shared\Enums\Platform;
use App\Shared\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * H2 regression (ADR-0019/0020): the whole-roster monitoring cycle runs in
 * platform context over EVERY tenant's roster. Cadence — content/profile
 * intervals AND the story-per-day budget — must be resolved from EACH
 * account's OWN tenant plan, not from one global MonitoringPlanSetting row
 * that happened to be written last. Before the fix, that single global row
 * governed every tenant's ingestion.
 */
class PerTenantCadenceTest extends TestCase
{
    use RefreshDatabase;

    /** An active Instagram roster account owned by the given tenant. */
    private function rosterInstagramAccount(Tenant $tenant): PlatformAccount
    {
        return $this->withTenant($tenant, function (): PlatformAccount {
            $creator = Creator::factory()->create();
            $account = PlatformAccount::factory()->create([
                'creator_id' => $creator->id,
                'platform' => Platform::Instagram,
            ]);
            MonitoredSubject::factory()->create([
                'creator_id' => $creator->id,
                'subject_type' => MonitoredSubjectType::Creator,
                'platforms' => [Platform::Instagram],
                'active' => true,
            ]);

            return $account;
        });
    }

    /** @param array<string, mixed> $attrs */
    private function planFor(Tenant $tenant, array $attrs): void
    {
        $this->withTenant($tenant, fn () => MonitoringPlanSetting::query()->create(array_merge([
            'baseline_content_interval_hours' => 84,
            'campaign_content_interval_hours' => 12,
            'stories_per_day' => 1,
            'profile_poll_interval_hours' => 168,
            'apify_plan' => 'STARTER',
        ], $attrs)));
    }

    private function recentContentPoll(PlatformAccount $account, int $hoursAgo): void
    {
        ProviderCall::query()->create([
            'source' => SourceRegistry::APIFY_INSTAGRAM_POST_SCRAPER,
            'operation' => 'content.fetch',
            'correlation_id' => 'corr-history',
            'platform_account_id' => $account->id,
            'started_at' => CarbonImmutable::now()->subHours($hoursAgo),
            'finished_at' => CarbonImmutable::now()->subHours($hoursAgo),
            'outcome' => CallOutcome::Success,
        ]);
    }

    /** Run a cycle the way the scheduler does — in platform (tenant-less) context. */
    private function runCycle(bool $storiesOnly = false): void
    {
        app(TenantContext::class)->runAs(
            null,
            fn () => (new RunMonitoringCycleJob(storiesOnly: $storiesOnly))->handle(),
        );
    }

    public function test_content_cadence_uses_each_tenants_own_plan(): void
    {
        $tenantA = $this->makeTenant('A');
        $tenantB = $this->makeTenant('B');

        // Tenant A: a fast plan (1h ≤ cycle spacing ⇒ always due).
        $accA = $this->rosterInstagramAccount($tenantA);
        $this->recentContentPoll($accA, hoursAgo: 2);
        $this->planFor($tenantA, ['baseline_content_interval_hours' => 1]);

        // Tenant B: a slow plan (999h) with a recent poll ⇒ NOT due. Its row
        // is written LAST, so the buggy global MonitoringPlanSetting::current()
        // would apply this 999h plan to tenant A too and poll neither.
        $accB = $this->rosterInstagramAccount($tenantB);
        $this->recentContentPoll($accB, hoursAgo: 2);
        $this->planFor($tenantB, ['baseline_content_interval_hours' => 999]);

        Queue::fake();

        $this->runCycle();

        Queue::assertPushed(
            fn (PollMonitoredAccountJob $job): bool => $job->platformAccountId === $accA->id,
        );
        Queue::assertNotPushed(
            fn (PollMonitoredAccountJob $job): bool => $job->platformAccountId === $accB->id,
        );
    }

    public function test_story_budget_uses_each_tenants_own_plan(): void
    {
        config([
            'qds.ingestion.stories_enabled' => true,
            'qds.ingestion.adaptive.enabled' => false,
        ]);

        $tenantA = $this->makeTenant('A');
        $tenantB = $this->makeTenant('B');

        $accA = $this->rosterInstagramAccount($tenantA);
        $accB = $this->rosterInstagramAccount($tenantB);

        // Tenant A wants stories (6/day); tenant B has disabled them (0/day).
        // B's row is written last, so the buggy global read (0/day) would
        // skip the whole story cron and starve tenant A of the stories it
        // pays for.
        $this->planFor($tenantA, ['stories_per_day' => 6]);
        $this->planFor($tenantB, ['stories_per_day' => 0]);

        Queue::fake();

        $this->runCycle(storiesOnly: true);

        Queue::assertPushed(
            fn (IngestStoriesBatchJob $job): bool => in_array($accA->id, $job->platformAccountIds, true),
        );
        Queue::assertNotPushed(
            fn (IngestStoriesBatchJob $job): bool => in_array($accB->id, $job->platformAccountIds, true),
        );
    }
}
