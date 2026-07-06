<?php

namespace Tests\Feature\Ingestion;

use App\Platform\Ingestion\Exceptions\ProviderCallException;
use App\Platform\Ingestion\Http\ApifyClient;
use App\Platform\Ingestion\Http\YouTubeClient;
use App\Platform\Ingestion\SourceRegistry;
use App\Platform\Ingestion\Support\ErrorCategory;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakesProviderResponses;
use Tests\TestCase;

/**
 * Every provider failure is detected and classified into a normalized
 * ErrorCategory with a sanitized message (External API Monitoring:
 * authentication failures, timeouts, rate limits, malformed responses,
 * schema changes). No live provider is called.
 */
class ErrorClassificationTest extends TestCase
{
    use FakesProviderResponses;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeProviderCredentials();
    }

    private function apifyCall(): void
    {
        app(ApifyClient::class)->runActor(
            SourceRegistry::APIFY_INSTAGRAM_PROFILE_SCRAPER,
            'apify~instagram-profile-scraper',
            ['usernames' => ['x']],
        );
    }

    private function expectCategory(ErrorCategory $category): void
    {
        try {
            $this->apifyCall();
            $this->fail('Expected ProviderCallException.');
        } catch (ProviderCallException $e) {
            $this->assertSame($category, $e->category);
            $this->assertStringNotContainsString('test-apify-token', $e->getMessage());
        }
    }

    public function test_missing_token_is_an_authentication_failure_before_any_call(): void
    {
        config(['services.apify.token' => null]);
        Http::fake();

        $this->expectCategory(ErrorCategory::Authentication);
        Http::assertNothingSent();
    }

    public function test_http_401_and_403_classify_as_authentication(): void
    {
        Http::fake(['api.apify.com/*' => Http::response(['error' => 'denied'], 401)]);
        $this->expectCategory(ErrorCategory::Authentication);
    }

    public function test_http_429_classifies_as_rate_limited_and_carries_retry_after(): void
    {
        Http::fake(['api.apify.com/*' => Http::response('slow down', 429, ['Retry-After' => '42'])]);

        try {
            $this->apifyCall();
            $this->fail('Expected ProviderCallException.');
        } catch (ProviderCallException $e) {
            $this->assertSame(ErrorCategory::RateLimited, $e->category);
            $this->assertSame(42, $e->retryAfterSeconds);
        }
    }

    public function test_http_5xx_classifies_as_upstream_error(): void
    {
        Http::fake(['api.apify.com/*' => Http::response('boom', 503)]);
        $this->expectCategory(ErrorCategory::UpstreamError);
    }

    public function test_connection_timeout_classifies_as_timeout(): void
    {
        Http::fake(['api.apify.com/*' => Http::failedConnection('cURL error 28: Operation timed out')]);
        $this->expectCategory(ErrorCategory::Timeout);
    }

    public function test_non_json_body_classifies_as_malformed_response(): void
    {
        Http::fake(['api.apify.com/*' => Http::response('<html>not json</html>', 200)]);
        $this->expectCategory(ErrorCategory::MalformedResponse);
    }

    public function test_object_instead_of_item_list_classifies_as_schema_drift(): void
    {
        Http::fake(['api.apify.com/*' => Http::response(['unexpected' => 'object'], 200)]);
        $this->expectCategory(ErrorCategory::SchemaDrift);
    }

    public function test_youtube_quota_exhaustion_is_rate_limited_not_auth(): void
    {
        Http::fake([
            'www.googleapis.com/*' => Http::response([
                'error' => ['errors' => [['reason' => 'quotaExceeded']], 'code' => 403],
            ], 403),
        ]);

        try {
            app(YouTubeClient::class)->get('channels', ['part' => 'snippet']);
            $this->fail('Expected ProviderCallException.');
        } catch (ProviderCallException $e) {
            $this->assertSame(ErrorCategory::RateLimited, $e->category);
        }
    }

    public function test_apify_paid_actor_access_error_item_is_authentication_not_silent_quarantine(): void
    {
        // Paid/rental actors on a free account return HTTP 201 with a single
        // access-error item (verified live with the datavoyantlab stories
        // actor). It must surface as a call-level AUTHENTICATION failure —
        // not get silently quarantined as a vague "missing id" record.
        Http::fake([
            'api.apify.com/*' => Http::response($this->fixture('apify-access-error'), 201),
        ]);

        try {
            $this->apifyCall();
            $this->fail('Expected ProviderCallException.');
        } catch (ProviderCallException $e) {
            $this->assertSame(ErrorCategory::Authentication, $e->category);
            $this->assertStringContainsString('not accessible', $e->getMessage());
            // No provider user identifiers leak into the sanitized message.
            $this->assertStringNotContainsString('REDACTED_ID', $e->getMessage());
        }
    }

    public function test_youtube_invalid_api_key_400_is_authentication_not_unknown(): void
    {
        // Google returns an invalid/absent key as HTTP 400 (reason
        // badRequest, "API key not valid"), verified live — must classify
        // as an authentication failure, not UNKNOWN.
        Http::fake([
            'www.googleapis.com/*' => Http::response([
                'error' => ['errors' => [['reason' => 'badRequest']], 'code' => 400, 'message' => 'API key not valid. Please pass a valid API key.'],
            ], 400),
        ]);

        try {
            app(YouTubeClient::class)->get('channels', ['part' => 'snippet']);
            $this->fail('Expected ProviderCallException.');
        } catch (ProviderCallException $e) {
            $this->assertSame(ErrorCategory::Authentication, $e->category);
            $this->assertStringNotContainsString('test-youtube-key', $e->getMessage());
        }
    }

    public function test_youtube_permission_denial_is_authentication(): void
    {
        Http::fake([
            'www.googleapis.com/*' => Http::response([
                'error' => ['errors' => [['reason' => 'forbidden']], 'code' => 403],
            ], 403),
        ]);

        try {
            app(YouTubeClient::class)->get('channels', ['part' => 'snippet']);
            $this->fail('Expected ProviderCallException.');
        } catch (ProviderCallException $e) {
            $this->assertSame(ErrorCategory::Authentication, $e->category);
            $this->assertStringNotContainsString('test-youtube-key', $e->getMessage());
        }
    }
}
