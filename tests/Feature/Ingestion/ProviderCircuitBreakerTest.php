<?php

namespace Tests\Feature\Ingestion;

use App\Modules\CRM\Models\PlatformAccount;
use App\Platform\Ingestion\Jobs\IngestProfileJob;
use App\Platform\Ingestion\Models\ProviderCall;
use App\Platform\Ingestion\Models\ProviderHealthState;
use App\Platform\Ingestion\Observability\ProviderCircuitBreaker;
use App\Platform\Ingestion\SourceRegistry;
use App\Platform\Ingestion\Support\ErrorCategory;
use App\Platform\Ingestion\Support\ProviderStatus;
use App\Shared\Enums\Platform;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakesProviderResponses;
use Tests\TestCase;

/**
 * Circuit breaker over provider health (cost plan recs 2+9): permanently
 * failing providers (e.g. a paywalled rental actor) stop being re-invoked
 * — and re-billed — every cycle; recovery goes through a single canary
 * probe per cooldown window.
 */
class ProviderCircuitBreakerTest extends TestCase
{
    use FakesProviderResponses;
    use RefreshDatabase;

    private function failingState(string $source, ErrorCategory $category, CarbonImmutable $failedAt): void
    {
        ProviderHealthState::query()->create([
            'source' => $source,
            'status' => ProviderStatus::Failing,
            'last_failure_at' => $failedAt,
            'consecutive_failures' => 3,
            'last_error_category' => $category,
            'last_error_message' => 'kaputt',
        ]);
    }

    public function test_permanent_failures_open_the_breaker_within_the_cooldown(): void
    {
        $source = SourceRegistry::APIFY_INSTAGRAM_STORY_DETAILS;
        $this->failingState($source, ErrorCategory::Authentication, CarbonImmutable::now()->subMinutes(10));

        $this->assertTrue(app(ProviderCircuitBreaker::class)->shouldSkip($source));
    }

    public function test_transient_failures_never_open_the_breaker(): void
    {
        $source = SourceRegistry::APIFY_INSTAGRAM_POST_SCRAPER;
        $this->failingState($source, ErrorCategory::Timeout, CarbonImmutable::now()->subMinutes(10));

        $this->assertFalse(app(ProviderCircuitBreaker::class)->shouldSkip($source));
    }

    public function test_healthy_and_unknown_providers_are_never_skipped(): void
    {
        $breaker = app(ProviderCircuitBreaker::class);

        $this->assertFalse($breaker->shouldSkip(SourceRegistry::APIFY_INSTAGRAM_PROFILE_SCRAPER));
    }

    public function test_disabled_breaker_skips_nothing(): void
    {
        config(['qds.ingestion.circuit_breaker.enabled' => false]);

        $source = SourceRegistry::APIFY_INSTAGRAM_STORY_DETAILS;
        $this->failingState($source, ErrorCategory::Authentication, CarbonImmutable::now()->subMinutes(10));

        $this->assertFalse(app(ProviderCircuitBreaker::class)->shouldSkip($source));
    }

    public function test_after_the_cooldown_exactly_one_canary_probe_goes_through(): void
    {
        config(['qds.ingestion.circuit_breaker.cooldown_minutes' => 60]);

        $source = SourceRegistry::APIFY_INSTAGRAM_STORY_DETAILS;
        $this->failingState($source, ErrorCategory::Authentication, CarbonImmutable::now()->subMinutes(90));

        $breaker = app(ProviderCircuitBreaker::class);

        $this->assertFalse($breaker->shouldSkip($source)); // canary wins the slot
        $this->assertTrue($breaker->shouldSkip($source));  // everyone else keeps skipping
    }

    public function test_an_open_breaker_prevents_the_provider_call_entirely(): void
    {
        $this->fakeProviderCredentials();

        $account = PlatformAccount::factory()->create(['platform' => Platform::Instagram]);

        $this->failingState(
            SourceRegistry::APIFY_INSTAGRAM_PROFILE_SCRAPER,
            ErrorCategory::Authentication,
            CarbonImmutable::now()->subMinutes(5),
        );

        Http::fake();

        IngestProfileJob::dispatchSync($account->id, null, 'corr-breaker');

        Http::assertNothingSent();
        // No ProviderCall row either — a skipped call is not a call.
        $this->assertSame(0, ProviderCall::query()->count());
    }
}
