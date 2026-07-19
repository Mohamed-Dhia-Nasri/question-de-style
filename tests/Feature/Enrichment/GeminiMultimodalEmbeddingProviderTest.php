<?php

namespace Tests\Feature\Enrichment;

use App\Platform\Enrichment\VisualMatch\Contracts\EmbeddingProvider;
use App\Platform\Enrichment\VisualMatch\Http\GeminiMultimodalEmbeddingProvider;
use App\Platform\Enrichment\VisualMatch\Http\GoogleServiceAccountTokenProvider;
use App\Platform\Ingestion\Exceptions\ProviderCallException;
use App\Platform\Ingestion\SourceRegistry;
use App\Platform\Ingestion\Support\ErrorCategory;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * SRC-google-gemini-embeddings over the verified :embedContent shape
 * (spec §18, 2026-07-19): one inlineData part → ONE vector at
 * embedding.values; embedContentConfig.outputDimensionality pins the
 * width; EU multi-region endpoint; Bearer-only auth. Every payload passes
 * the AiPayloadGuard BEFORE any byte (or token fetch) leaves.
 */
class GeminiMultimodalEmbeddingProviderTest extends TestCase
{
    /** @var list<string> */
    private array $cleanupPaths = [];

    protected function tearDown(): void
    {
        foreach ($this->cleanupPaths as $path) {
            @unlink($path);
        }

        parent::tearDown();
    }

    /**
     * Configured provider with a pre-warmed bearer token: the OAuth flow
     * has its own test file (Task 6); embedImage never touches the token
     * endpoint here. The credentials file is a stub — it is never read
     * while the token cache is warm; it only satisfies isConfigured().
     */
    private function configureProvider(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'qds-test-sa-');
        $this->assertIsString($path);
        $this->cleanupPaths[] = $path;
        file_put_contents($path, '{"client_email":"qds-embeddings@qds-embeddings-test.iam.gserviceaccount.com"}');

        config([
            'services.google_embeddings.credentials_path' => $path,
            'services.google_embeddings.project_id' => 'qds-embeddings-test',
            'qds.enrichment.visual_match.dimensions' => 3,
        ]);

        Cache::put(GoogleServiceAccountTokenProvider::CACHE_KEY, 'test-bearer-token', 3540);
    }

    private function embedExpectingFailure(): ProviderCallException
    {
        try {
            app(EmbeddingProvider::class)->embedImage('fake-frame-bytes', 'image/jpeg');
        } catch (ProviderCallException $e) {
            return $e;
        }

        $this->fail('Expected a ProviderCallException.');
    }

    public function test_the_container_binds_the_gemini_implementation(): void
    {
        $this->assertInstanceOf(GeminiMultimodalEmbeddingProvider::class, app(EmbeddingProvider::class));
    }

    public function test_embed_image_posts_one_inline_image_and_returns_the_vector(): void
    {
        $this->configureProvider();
        Http::fake([
            'aiplatform.eu.rep.googleapis.com/*' => Http::response(['embedding' => ['values' => [0.25, -0.5, 1.0]]]),
        ]);

        $vector = app(EmbeddingProvider::class)->embedImage('fake-frame-bytes', 'image/jpeg');

        $this->assertSame([0.25, -0.5, 1.0], $vector);

        Http::assertSent(function (Request $request): bool {
            // Verified endpoint (spec §18): EU multi-region host, v1 path,
            // :embedContent method — and NO credentials in the URL (exact
            // match proves no query string; Bearer header only).
            $this->assertSame(
                'https://aiplatform.eu.rep.googleapis.com/v1/projects/qds-embeddings-test/locations/eu/publishers/google/models/gemini-embedding-2:embedContent',
                $request->url(),
            );
            $this->assertSame('Bearer test-bearer-token', $request->header('Authorization')[0] ?? null);
            $this->assertFalse($request->hasHeader('X-Goog-Api-Key'));

            // Verified body shape: one inlineData part; width pinned via
            // embedContentConfig.outputDimensionality (top-level fields
            // are deprecated — never used).
            $this->assertSame([
                'content' => [
                    'parts' => [
                        ['inlineData' => ['mimeType' => 'image/jpeg', 'data' => base64_encode('fake-frame-bytes')]],
                    ],
                ],
                'embedContentConfig' => ['outputDimensionality' => 3],
            ], $request->data());

            return true;
        });
    }

    public function test_model_version_comes_from_config_and_is_configured_delegates_to_the_token_provider(): void
    {
        config([
            'services.google_embeddings.credentials_path' => null,
            'services.google_embeddings.project_id' => null,
        ]);
        $provider = app(EmbeddingProvider::class);

        $this->assertSame('gemini-embedding-2', $provider->modelVersion());
        $this->assertFalse($provider->isConfigured());

        $this->configureProvider();
        $this->assertTrue(app(EmbeddingProvider::class)->isConfigured());
    }

    public function test_embedding_while_unconfigured_fails_closed_without_a_network_call(): void
    {
        config([
            'services.google_embeddings.credentials_path' => null,
            'services.google_embeddings.project_id' => null,
        ]);
        Http::fake();

        $this->assertSame(ErrorCategory::Authentication, $this->embedExpectingFailure()->category);
        Http::assertNothingSent();
    }

    public function test_every_payload_passes_the_ai_payload_guard_before_any_byte_leaves(): void
    {
        $this->configureProvider();
        Http::fake();

        // A signed-URL-style mime type trips the DP-005 credential
        // pattern — proving the guard sits in FRONT of the HTTP call.
        try {
            app(EmbeddingProvider::class)->embedImage('fake-frame-bytes', 'image/jpeg?token=leaked');
            $this->fail('Expected the AiPayloadGuard to reject the payload.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('DP-005', $e->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_rate_limiting_maps_to_rate_limited_with_retry_after(): void
    {
        $this->configureProvider();
        Http::fake([
            'aiplatform.eu.rep.googleapis.com/*' => Http::response(
                ['error' => ['status' => 'RESOURCE_EXHAUSTED']],
                429,
                ['Retry-After' => '7'],
            ),
        ]);

        $e = $this->embedExpectingFailure();

        $this->assertSame(SourceRegistry::GOOGLE_GEMINI_EMBEDDINGS, $e->source);
        $this->assertSame(ErrorCategory::RateLimited, $e->category);
        $this->assertSame(429, $e->httpStatus);
        $this->assertSame(7, $e->retryAfterSeconds);
    }

    public function test_denied_access_maps_to_authentication_and_never_leaks_the_token(): void
    {
        $this->configureProvider();
        Http::fake([
            'aiplatform.eu.rep.googleapis.com/*' => Http::response(['error' => ['status' => 'PERMISSION_DENIED']], 403),
        ]);

        $e = $this->embedExpectingFailure();

        $this->assertSame(ErrorCategory::Authentication, $e->category);
        $this->assertStringNotContainsString('test-bearer-token', $e->getMessage());
    }

    public function test_server_errors_map_to_upstream_error(): void
    {
        $this->configureProvider();
        Http::fake(['aiplatform.eu.rep.googleapis.com/*' => Http::response('', 500)]);

        $this->assertSame(ErrorCategory::UpstreamError, $this->embedExpectingFailure()->category);
    }

    public function test_a_non_json_body_maps_to_malformed_response(): void
    {
        $this->configureProvider();
        Http::fake(['aiplatform.eu.rep.googleapis.com/*' => Http::response('embedding-but-not-json')]);

        $this->assertSame(ErrorCategory::MalformedResponse, $this->embedExpectingFailure()->category);
    }

    public function test_a_response_with_no_vector_key_maps_to_malformed_response(): void
    {
        $this->configureProvider();
        // Well-formed JSON, but the embedding.values key is simply absent —
        // distinct from "present but wrong shape/width" (SchemaDrift).
        Http::fake(['aiplatform.eu.rep.googleapis.com/*' => Http::response('{}')]);

        $e = $this->embedExpectingFailure();

        $this->assertSame(ErrorCategory::MalformedResponse, $e->category);
        $this->assertStringNotContainsString('{}', $e->getMessage());
    }

    public function test_a_wrong_width_vector_maps_to_schema_drift(): void
    {
        $this->configureProvider();
        // 2 values against configured dimensions = 3.
        Http::fake(['aiplatform.eu.rep.googleapis.com/*' => Http::response(['embedding' => ['values' => [0.1, 0.2]]])]);

        $this->assertSame(ErrorCategory::SchemaDrift, $this->embedExpectingFailure()->category);
    }

    public function test_a_connection_timeout_maps_to_timeout(): void
    {
        $this->configureProvider();
        // The token is cached, so the ONLY outbound call is the embed —
        // this exception unambiguously exercises the embed error path.
        Http::fake([
            'aiplatform.eu.rep.googleapis.com/*' => fn () => throw new ConnectionException('cURL error 28: Operation timed out after 30001 ms'),
        ]);

        $this->assertSame(ErrorCategory::Timeout, $this->embedExpectingFailure()->category);
    }

    public function test_an_explicit_base_url_overrides_the_derived_eu_endpoint(): void
    {
        $this->configureProvider();
        config(['services.google_embeddings.base_url' => 'https://aiplatform.proxy.internal/v1']);
        Http::fake([
            'aiplatform.proxy.internal/*' => Http::response(['embedding' => ['values' => [0.1, 0.2, 0.3]]]),
        ]);

        $this->assertSame([0.1, 0.2, 0.3], app(EmbeddingProvider::class)->embedImage('fake-frame-bytes', 'image/png'));

        Http::assertSent(fn (Request $request): bool => str_starts_with(
            $request->url(),
            'https://aiplatform.proxy.internal/v1/projects/qds-embeddings-test/locations/eu/',
        ));
    }
}
