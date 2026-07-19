<?php

namespace Tests\Feature\Enrichment;

use App\Platform\Enrichment\VisualMatch\Http\GoogleServiceAccountTokenProvider;
use App\Platform\Ingestion\Exceptions\ProviderCallException;
use App\Platform\Ingestion\SourceRegistry;
use App\Platform\Ingestion\Support\ErrorCategory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * First service-account machinery in the repo (spec §5): RS256 JWT-bearer
 * exchange at Google's token endpoint. Verified flow (2026-07-19): aud =
 * https://oauth2.googleapis.com/token, scope = cloud-platform, lifetime
 * <= 1 h, grant_type = urn:ietf:params:oauth:grant-type:jwt-bearer. Key
 * material and tokens must never surface in URLs or exception messages.
 */
class GoogleServiceAccountTokenProviderTest extends TestCase
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
     * Throwaway RSA service-account key: generated per test, written as a
     * Google-shaped JSON key file, wired into config. Returns the file
     * path and the public key PEM for signature verification.
     *
     * @return array{path: string, public_key: string}
     */
    private function provisionServiceAccount(): array
    {
        $key = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        $this->assertNotFalse($key, 'openssl_pkey_new failed');

        $privatePem = '';
        $this->assertTrue(openssl_pkey_export($key, $privatePem));

        $details = openssl_pkey_get_details($key);
        $this->assertIsArray($details);

        $path = tempnam(sys_get_temp_dir(), 'qds-test-sa-');
        $this->assertIsString($path);
        $this->cleanupPaths[] = $path;

        file_put_contents($path, (string) json_encode([
            'type' => 'service_account',
            'project_id' => 'qds-embeddings-test',
            'private_key_id' => 'test-key-1',
            'private_key' => $privatePem,
            'client_email' => 'qds-embeddings@qds-embeddings-test.iam.gserviceaccount.com',
            'token_uri' => 'https://oauth2.googleapis.com/token',
        ]));

        config([
            'services.google_embeddings.credentials_path' => $path,
            'services.google_embeddings.project_id' => 'qds-embeddings-test',
        ]);

        return ['path' => $path, 'public_key' => (string) $details['key']];
    }

    private function base64UrlDecode(string $value): string
    {
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);
        $this->assertIsString($decoded);

        return $decoded;
    }

    public function test_is_configured_only_when_key_file_is_readable_and_project_is_set(): void
    {
        config([
            'services.google_embeddings.credentials_path' => null,
            'services.google_embeddings.project_id' => null,
        ]);
        $this->assertFalse(app(GoogleServiceAccountTokenProvider::class)->isConfigured());

        $account = $this->provisionServiceAccount();
        $this->assertTrue(app(GoogleServiceAccountTokenProvider::class)->isConfigured());

        config(['services.google_embeddings.project_id' => null]);
        $this->assertFalse(app(GoogleServiceAccountTokenProvider::class)->isConfigured());

        config([
            'services.google_embeddings.project_id' => 'qds-embeddings-test',
            'services.google_embeddings.credentials_path' => $account['path'].'-missing',
        ]);
        $this->assertFalse(app(GoogleServiceAccountTokenProvider::class)->isConfigured());
    }

    public function test_token_exchanges_a_signed_rs256_jwt_bearer_assertion(): void
    {
        $account = $this->provisionServiceAccount();
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'ya29.test-token',
                'expires_in' => 3600,
                'token_type' => 'Bearer',
            ]),
        ]);

        $this->assertSame('ya29.test-token', app(GoogleServiceAccountTokenProvider::class)->token());

        Http::assertSent(function (Request $request) use ($account): bool {
            // Documented server-to-server flow: form-encoded jwt-bearer
            // grant at the token endpoint — nothing in the URL.
            $this->assertSame('https://oauth2.googleapis.com/token', $request->url());
            $this->assertSame('urn:ietf:params:oauth:grant-type:jwt-bearer', $request['grant_type']);

            $segments = explode('.', (string) $request['assertion']);
            $this->assertCount(3, $segments);
            [$header64, $claims64, $signature64] = $segments;

            $header = json_decode($this->base64UrlDecode($header64), true);
            $this->assertSame(['alg' => 'RS256', 'typ' => 'JWT'], $header);

            $claims = json_decode($this->base64UrlDecode($claims64), true);
            $this->assertIsArray($claims);
            $this->assertSame('qds-embeddings@qds-embeddings-test.iam.gserviceaccount.com', $claims['iss']);
            $this->assertSame('https://www.googleapis.com/auth/cloud-platform', $claims['scope']);
            $this->assertSame('https://oauth2.googleapis.com/token', $claims['aud']);
            $this->assertEqualsWithDelta(time(), $claims['iat'], 5);
            $this->assertSame(3600, $claims['exp'] - $claims['iat']);

            // The assertion really is RS256-signed by the configured key.
            $this->assertSame(1, openssl_verify(
                "{$header64}.{$claims64}",
                $this->base64UrlDecode($signature64),
                $account['public_key'],
                OPENSSL_ALGO_SHA256,
            ));

            return true;
        });
    }

    public function test_token_is_cached_and_refetched_only_near_expiry(): void
    {
        $this->provisionServiceAccount();
        Http::fake([
            'oauth2.googleapis.com/token' => Http::sequence()
                ->push(['access_token' => 'token-one', 'expires_in' => 3600, 'token_type' => 'Bearer'])
                ->push(['access_token' => 'token-two', 'expires_in' => 3600, 'token_type' => 'Bearer']),
        ]);

        $provider = app(GoogleServiceAccountTokenProvider::class);

        $this->assertSame('token-one', $provider->token());
        $this->assertSame('token-one', $provider->token());
        Http::assertSentCount(1); // second call served from cache

        // Cached until 60 s before expiry (3600 - 60 = 3540 s).
        $this->travel(3541)->seconds();

        $this->assertSame('token-two', $provider->token());
        Http::assertSentCount(2);
    }

    public function test_exchange_failure_throws_a_sanitized_authentication_exception(): void
    {
        $this->provisionServiceAccount();
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response([
                'error' => 'invalid_grant',
                'error_description' => 'Invalid JWT signature.',
            ], 400),
        ]);

        try {
            app(GoogleServiceAccountTokenProvider::class)->token();
            $this->fail('Expected a ProviderCallException.');
        } catch (ProviderCallException $e) {
            $this->assertSame(SourceRegistry::GOOGLE_GEMINI_EMBEDDINGS, $e->source);
            $this->assertSame(ErrorCategory::Authentication, $e->category);
            $this->assertSame(400, $e->httpStatus);
            // Sanitized: no key material, no raw provider body.
            $this->assertStringNotContainsString('PRIVATE KEY', $e->getMessage());
            $this->assertStringNotContainsString('Invalid JWT signature', $e->getMessage());
        }

        // A failed exchange never poisons the cache.
        $this->assertNull(Cache::get(GoogleServiceAccountTokenProvider::CACHE_KEY));
    }

    public function test_missing_access_token_in_a_successful_response_is_a_failure(): void
    {
        $this->provisionServiceAccount();
        Http::fake(['oauth2.googleapis.com/token' => Http::response(['token_type' => 'Bearer'])]);

        try {
            app(GoogleServiceAccountTokenProvider::class)->token();
            $this->fail('Expected a ProviderCallException.');
        } catch (ProviderCallException $e) {
            $this->assertSame(ErrorCategory::Authentication, $e->category);
        }
    }

    public function test_malformed_key_file_fails_closed_without_a_network_call(): void
    {
        Http::fake();

        $path = tempnam(sys_get_temp_dir(), 'qds-test-sa-');
        $this->assertIsString($path);
        $this->cleanupPaths[] = $path;
        file_put_contents($path, 'not-json');

        config([
            'services.google_embeddings.credentials_path' => $path,
            'services.google_embeddings.project_id' => 'qds-embeddings-test',
        ]);

        try {
            app(GoogleServiceAccountTokenProvider::class)->token();
            $this->fail('Expected a ProviderCallException.');
        } catch (ProviderCallException $e) {
            $this->assertSame(ErrorCategory::Authentication, $e->category);
        }

        Http::assertNothingSent();
    }
}
