<?php

namespace Tests\Feature\Ingestion;

use App\Modules\CRM\Models\PlatformAccount;
use App\Platform\Ingestion\Jobs\IngestContentJob;
use App\Platform\Ingestion\Jobs\IngestProfileJob;
use App\Platform\Ingestion\Jobs\RefreshIngestionStatusJob;
use App\Platform\Ingestion\Models\IngestionAlert;
use App\Platform\Ingestion\Models\IngestionCycle;
use App\Platform\Ingestion\Models\ProviderCall;
use App\Platform\Ingestion\Models\ProviderHealthState;
use App\Platform\Ingestion\Models\ProviderResponseSample;
use App\Platform\Ingestion\Observability\AlertService;
use App\Platform\Ingestion\Observability\ProviderHealthService;
use App\Platform\Ingestion\SourceRegistry;
use App\Platform\Ingestion\Support\AlertType;
use App\Platform\Ingestion\Support\CallOutcome;
use App\Platform\Ingestion\Support\CycleStatus;
use App\Platform\Ingestion\Support\ProviderStatus;
use App\Shared\Enums\Platform;
use App\Shared\Enums\RoleName;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakesProviderResponses;
use Tests\TestCase;

/**
 * External API Monitoring: per-call telemetry with stage timings, provider
 * health-state transitions, deduplicated alerts (repeated failures, schema
 * drift, stale data), stale-cycle finalization, story-polling risk,
 * redacted response sampling, and access control on samples.
 */
class ObservabilityTest extends TestCase
{
    use FakesProviderResponses;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeProviderCredentials();
    }

    private function instagramAccount(): PlatformAccount
    {
        return PlatformAccount::factory()->create([
            'platform' => Platform::Instagram,
            'handle' => 'styleicon.de',
        ]);
    }

    public function test_every_call_records_stage_timings_and_record_accounting(): void
    {
        $account = $this->instagramAccount();
        $this->fakeApifyActor('apify~instagram-profile-scraper', $this->fixture('instagram-profile'));

        IngestProfileJob::dispatchSync($account->id, null, 'corr-obs');

        $call = ProviderCall::query()->firstOrFail();

        $this->assertSame(SourceRegistry::APIFY_INSTAGRAM_PROFILE_SCRAPER, $call->source);
        $this->assertSame('profile.fetch', $call->operation);
        $this->assertSame('corr-obs', $call->correlation_id);
        $this->assertSame($account->id, $call->platform_account_id);
        $this->assertSame(CallOutcome::Success, $call->outcome);
        $this->assertSame(200, $call->http_status);
        $this->assertSame(1, $call->result_count);
        $this->assertSame(1, $call->accepted_count);
        $this->assertSame(0, $call->rejected_count);
        $this->assertGreaterThan(0, $call->response_bytes);
        $this->assertGreaterThanOrEqual(0, $call->duration_ms);

        // Pipeline stage timings (request/validation/normalization/persistence/media).
        foreach (['request_ms', 'validation_ms', 'normalization_ms', 'persistence_ms', 'media_ms'] as $stage) {
            $this->assertArrayHasKey($stage, $call->timings);
        }
    }

    public function test_health_state_flips_to_failing_after_consecutive_failures_and_recovers(): void
    {
        config(['qds.ingestion.observability.failing_after_consecutive_failures' => 3]);
        // This test exercises the recorder's health-state semantics; the
        // circuit breaker would otherwise skip the recovery call (its own
        // canary behavior is covered by ProviderCircuitBreakerTest).
        config(['qds.ingestion.circuit_breaker.enabled' => false]);

        $account = $this->instagramAccount();
        $source = SourceRegistry::APIFY_INSTAGRAM_PROFILE_SCRAPER;

        // Three auth failures in a row, then a successful poll.
        Http::fake([
            'api.apify.com/*' => Http::sequence()
                ->push('denied', 401)
                ->push('denied', 401)
                ->push('denied', 401)
                ->push($this->fixture('instagram-profile')),
        ]);

        for ($i = 0; $i < 3; $i++) {
            $job = (new IngestProfileJob($account->id, null, "corr-{$i}"))->withFakeQueueInteractions();
            app()->call([$job, 'handle']);
        }

        $state = ProviderHealthState::query()->where('source', $source)->firstOrFail();
        $this->assertSame(ProviderStatus::Failing, $state->status);
        $this->assertSame(3, $state->consecutive_failures);

        $alert = IngestionAlert::query()->where('alert_type', AlertType::RepeatedFailures->value)->firstOrFail();
        $this->assertNull($alert->resolved_at);

        // Recovery: one success resets the streak and resolves the alert.
        IngestProfileJob::dispatchSync($account->id, null, 'corr-ok');

        $state->refresh();
        $this->assertSame(ProviderStatus::Healthy, $state->status);
        $this->assertSame(0, $state->consecutive_failures);
        $this->assertNotNull($alert->refresh()->resolved_at);
    }

    public function test_schema_drift_in_most_records_raises_a_deduplicated_alert(): void
    {
        config(['qds.ingestion.observability.schema_drift_alert_ratio' => 0.5]);

        $account = $this->instagramAccount();

        // Every post item lacks the id → 100% structural rejection.
        $drifted = [
            ['type' => 'Image', 'caption' => 'a'],
            ['type' => 'Image', 'caption' => 'b'],
        ];

        Http::fake([
            'api.apify.com/v2/acts/apify~instagram-post-scraper/*' => Http::response($drifted),
            'api.apify.com/v2/acts/apify~instagram-reel-scraper/*' => Http::response([]),
        ]);

        IngestContentJob::dispatchSync($account->id, null, 'corr-drift');
        IngestContentJob::dispatchSync($account->id, null, 'corr-drift-2');

        $alerts = IngestionAlert::query()->where('alert_type', AlertType::SchemaDrift->value)->get();
        $this->assertCount(1, $alerts); // deduplicated
        $this->assertSame(2, $alerts->first()->count);
        $this->assertSame(SourceRegistry::APIFY_INSTAGRAM_POST_SCRAPER, $alerts->first()->source);
    }

    public function test_status_refresh_detects_stale_data_and_resolves_when_fresh(): void
    {
        config(['qds.ingestion.observability.stale_after_hours' => 24]);

        ProviderHealthState::query()->create([
            'source' => SourceRegistry::CLOCKWORKS_TIKTOK_SCRAPER,
            'status' => ProviderStatus::Healthy,
            'last_success_at' => CarbonImmutable::now()->subHours(48),
            'consecutive_failures' => 0,
        ]);

        (new RefreshIngestionStatusJob)->handle(app(AlertService::class));

        $alert = IngestionAlert::query()->where('alert_type', AlertType::StaleData->value)->firstOrFail();
        $this->assertSame(SourceRegistry::CLOCKWORKS_TIKTOK_SCRAPER, $alert->source);

        // Fresh success → the warning resolves.
        ProviderHealthState::query()
            ->where('source', SourceRegistry::CLOCKWORKS_TIKTOK_SCRAPER)
            ->update(['last_success_at' => CarbonImmutable::now()]);

        (new RefreshIngestionStatusJob)->handle(app(AlertService::class));

        $this->assertNotNull($alert->refresh()->resolved_at);

        // The health view mirrors the stale warning state.
        $view = app(ProviderHealthService::class)->overview();
        $this->assertFalse($view[SourceRegistry::CLOCKWORKS_TIKTOK_SCRAPER]['stale_data_warning']);
    }

    public function test_status_refresh_finalizes_stale_cycles_and_flags_story_polling_risk(): void
    {
        config([
            'qds.ingestion.cycle_stale_after_minutes' => 180,
            'qds.ingestion.observability.story_polling_risk_hours' => 12,
        ]);

        $staleCycle = IngestionCycle::query()->create([
            'correlation_id' => 'corr-stale',
            'status' => CycleStatus::Running,
            'accounts_count' => 1,
            'jobs_expected' => 3,
            'jobs_pending' => 2,
            'jobs_failed' => 0,
            'started_at' => CarbonImmutable::now()->subHours(6),
        ]);

        // Story polling last succeeded 20h ago — stories are at risk.
        ProviderCall::query()->create([
            'source' => SourceRegistry::APIFY_INSTAGRAM_STORY_DETAILS,
            'operation' => 'stories.fetch',
            'correlation_id' => 'corr-old-story',
            'started_at' => CarbonImmutable::now()->subHours(20),
            'outcome' => CallOutcome::Success,
        ]);

        (new RefreshIngestionStatusJob)->handle(app(AlertService::class));

        $this->assertSame(CycleStatus::Stale, $staleCycle->refresh()->status);
        $this->assertNotNull($staleCycle->finished_at);

        $this->assertTrue(
            IngestionAlert::query()
                ->where('alert_type', AlertType::StoryPollingRisk->value)
                ->whereNull('resolved_at')
                ->exists(),
        );
    }

    public function test_response_sampling_stores_only_redacted_truncated_payloads(): void
    {
        config([
            'qds.ingestion.sampling.defaults' => [
                'enabled' => true,
                'rate' => 1.0,
                'max_items' => 1,
                'retention_days' => 7,
            ],
        ]);

        $account = $this->instagramAccount();

        $profile = $this->fixture('instagram-profile');
        $profile[0]['email'] = 'creator@example.com'; // must never be stored
        $this->fakeApifyActor('apify~instagram-profile-scraper', $profile);

        IngestProfileJob::dispatchSync($account->id, null, 'corr-sample');

        $sample = ProviderResponseSample::query()->firstOrFail();

        $this->assertSame(SourceRegistry::APIFY_INSTAGRAM_PROFILE_SCRAPER, $sample->source);
        $this->assertNotNull($sample->expires_at); // short retention
        $this->assertSame(1, $sample->payload['result_count']);
        $this->assertSame('[REDACTED]', $sample->payload['sampled_items'][0]['email']);
        $this->assertStringNotContainsString('creator@example.com', json_encode($sample->payload));
    }

    public function test_response_samples_are_restricted_to_authorized_technical_users(): void
    {
        $this->seedRoles();

        $sample = ProviderResponseSample::query()->create([
            'source' => SourceRegistry::APIFY_INSTAGRAM_PROFILE_SCRAPER,
            'operation' => 'profile.fetch',
            'correlation_id' => 'corr-x',
            'payload' => ['result_count' => 0, 'sampled_items' => []],
            'sampled_at' => CarbonImmutable::now(),
            'expires_at' => CarbonImmutable::now()->addDays(7),
        ]);

        $this->assertTrue(Gate::forUser($this->makeUser(RoleName::Admin))->allows('view', $sample));
        $this->assertFalse(Gate::forUser($this->makeUser(RoleName::Analyst))->allows('view', $sample));
        $this->assertFalse(Gate::forUser($this->makeUser(RoleName::ClientViewer))->allows('view', $sample));
    }
}
