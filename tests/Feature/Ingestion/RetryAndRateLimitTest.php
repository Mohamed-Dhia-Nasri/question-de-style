<?php

namespace Tests\Feature\Ingestion;

use App\Modules\CRM\Models\PlatformAccount;
use App\Platform\Ingestion\Exceptions\ProviderCallException;
use App\Platform\Ingestion\Jobs\IngestProfileJob;
use App\Platform\Ingestion\Models\IngestionAlert;
use App\Platform\Ingestion\Models\ProviderCall;
use App\Platform\Ingestion\Support\AlertType;
use App\Platform\Ingestion\Support\CallOutcome;
use App\Platform\Ingestion\Support\ErrorCategory;
use App\Shared\Enums\Platform;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\Support\FakesProviderResponses;
use Tests\TestCase;

/**
 * Retry behaviour, rate-limit handling, and failed-job visibility
 * (ingestion spec, queues section): 429 + Retry-After releases the job for
 * the provider-stated delay; transient errors rethrow into the queue's
 * exponential backoff; permanent errors fail fast; every final failure is
 * visible as a deduplicated alert (plus Laravel's failed_jobs table).
 */
class RetryAndRateLimitTest extends TestCase
{
    use FakesProviderResponses;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeProviderCredentials();
    }

    private function runProfileJob(PlatformAccount $account): IngestProfileJob
    {
        $job = (new IngestProfileJob($account->id, null, 'corr-rl'))->withFakeQueueInteractions();

        app()->call([$job, 'handle']);

        return $job;
    }

    public function test_backoff_is_exponential(): void
    {
        $job = new IngestProfileJob(1, null, 'corr');

        $this->assertSame([60, 300, 900, 1800], $job->backoff());
    }

    public function test_rate_limited_call_releases_the_job_for_the_provider_stated_delay(): void
    {
        $account = PlatformAccount::factory()->create(['platform' => Platform::Instagram]);

        Http::fake(['api.apify.com/*' => Http::response('slow down', 429, ['Retry-After' => '77'])]);

        $job = $this->runProfileJob($account);

        $job->assertReleased(delay: 77);

        // The rate-limited attempt is still recorded (rate-limit state).
        $call = ProviderCall::query()->firstOrFail();
        $this->assertSame(CallOutcome::Failure, $call->outcome);
        $this->assertSame(ErrorCategory::RateLimited, $call->error_category);
        $this->assertSame(['retry_after' => 77], $call->rate_limit);
    }

    public function test_transient_upstream_error_rethrows_for_queue_backoff_retry(): void
    {
        $account = PlatformAccount::factory()->create(['platform' => Platform::Instagram]);

        Http::fake(['api.apify.com/*' => Http::response('boom', 503)]);

        $this->expectException(ProviderCallException::class);

        try {
            $this->runProfileJob($account);
        } finally {
            $call = ProviderCall::query()->firstOrFail();
            $this->assertSame(ErrorCategory::UpstreamError, $call->error_category);
        }
    }

    public function test_permanent_auth_failure_fails_fast_instead_of_burning_retries(): void
    {
        $account = PlatformAccount::factory()->create(['platform' => Platform::Instagram]);

        Http::fake(['api.apify.com/*' => Http::response('denied', 401)]);

        $job = $this->runProfileJob($account);

        $job->assertFailed();
        $job->assertNotReleased();
    }

    public function test_final_failure_raises_a_deduplicated_job_failed_alert(): void
    {
        $job = new IngestProfileJob(42, null, 'corr-failed');

        $job->failed(new RuntimeException('boom'));
        $job->failed(new RuntimeException('boom again'));

        $alerts = IngestionAlert::query()
            ->where('alert_type', AlertType::JobFailed->value)
            ->get();

        $this->assertCount(1, $alerts); // deduplicated
        $this->assertSame(2, $alerts->first()->count);
        $this->assertStringContainsString('IngestProfileJob', $alerts->first()->message);
        // Raw exception text is not leaked into the alert.
        $this->assertStringNotContainsString('boom', $alerts->first()->message);
    }
}
