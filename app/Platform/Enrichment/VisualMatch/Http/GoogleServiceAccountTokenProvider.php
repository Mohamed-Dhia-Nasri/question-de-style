<?php

namespace App\Platform\Enrichment\VisualMatch\Http;

use App\Platform\Ingestion\Exceptions\ProviderCallException;
use App\Platform\Ingestion\SourceRegistry;
use App\Platform\Ingestion\Support\ErrorCategory;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * OAuth bearer tokens for SRC-google-gemini-embeddings (sub-project C,
 * ADR-0029). API keys CANNOT call :embedContent (verified 2026-07-19), so
 * this is the repo's first service-account flow: sign a self-issued RS256
 * JWT from the configured JSON key file (openssl — no new dependency) and
 * exchange it at Google's token endpoint per the documented
 * server-to-server flow, then cache the bearer token until shortly before
 * expiry.
 *
 * Security invariants (house rules): key material and tokens never appear
 * in URLs, logs, or exception messages; every failure surfaces as a
 * SANITIZED ProviderCallException(Authentication) — callers skip, never
 * crash an enrichment run. This is auth plumbing, not an AI payload: the
 * JWT assertion legitimately IS a credential, so it does not pass
 * AiPayloadGuard (which keeps credentials/personal data out of AI request
 * bodies — the embedding payload itself is guarded in
 * GeminiMultimodalEmbeddingProvider).
 */
final class GoogleServiceAccountTokenProvider
{
    /** Shared across workers; also the test seam for pre-warming a token. */
    public const CACHE_KEY = 'qds:google-embeddings-token';

    private const TOKEN_ENDPOINT = 'https://oauth2.googleapis.com/token';

    private const SCOPE = 'https://www.googleapis.com/auth/cloud-platform';

    private const GRANT_TYPE = 'urn:ietf:params:oauth:grant-type:jwt-bearer';

    /** Google's maximum assertion lifetime is one hour. */
    private const TOKEN_LIFETIME_SECONDS = 3600;

    /** Refresh this many seconds BEFORE the token would expire. */
    private const EXPIRY_SAFETY_SECONDS = 60;

    public function isConfigured(): bool
    {
        $path = (string) config('services.google_embeddings.credentials_path');

        return $path !== ''
            && is_readable($path)
            && (string) config('services.google_embeddings.project_id') !== '';
    }

    public function token(): string
    {
        $cached = Cache::get(self::CACHE_KEY);

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        ['token' => $token, 'expires_in' => $expiresIn] = $this->exchange(
            $this->signAssertion($this->credentials()),
        );

        Cache::put(self::CACHE_KEY, $token, max(1, $expiresIn - self::EXPIRY_SAFETY_SECONDS));

        return $token;
    }

    /**
     * @return array{client_email: string, private_key: string}
     */
    private function credentials(): array
    {
        $path = (string) config('services.google_embeddings.credentials_path');
        $raw = $path !== '' && is_readable($path) ? file_get_contents($path) : false;
        $decoded = is_string($raw) ? json_decode($raw, true) : null;

        $clientEmail = is_array($decoded) ? ($decoded['client_email'] ?? null) : null;
        $privateKey = is_array($decoded) ? ($decoded['private_key'] ?? null) : null;

        if (! is_string($clientEmail) || $clientEmail === '' || ! is_string($privateKey) || $privateKey === '') {
            throw $this->failure('service-account key file is missing, unreadable, or malformed.');
        }

        return ['client_email' => $clientEmail, 'private_key' => $privateKey];
    }

    /**
     * Self-issued RS256 JWT per Google's server-to-server OAuth flow
     * (verified 2026-07-19): aud = the token endpoint, scope =
     * cloud-platform, lifetime <= 1 h.
     *
     * @param  array{client_email: string, private_key: string}  $credentials
     */
    private function signAssertion(array $credentials): string
    {
        $now = CarbonImmutable::now()->getTimestamp();

        $signingInput = $this->base64UrlEncode((string) json_encode(['alg' => 'RS256', 'typ' => 'JWT']))
            .'.'
            .$this->base64UrlEncode((string) json_encode([
                'iss' => $credentials['client_email'],
                'scope' => self::SCOPE,
                'aud' => self::TOKEN_ENDPOINT,
                'iat' => $now,
                'exp' => $now + self::TOKEN_LIFETIME_SECONDS,
            ]));

        $key = openssl_pkey_get_private($credentials['private_key']);

        if ($key === false) {
            throw $this->failure('service-account private key could not be parsed.');
        }

        $signature = '';

        if (! openssl_sign($signingInput, $signature, $key, OPENSSL_ALGO_SHA256)) {
            throw $this->failure('service-account JWT signing failed.');
        }

        return $signingInput.'.'.$this->base64UrlEncode($signature);
    }

    /**
     * @return array{token: string, expires_in: int}
     */
    private function exchange(string $assertion): array
    {
        try {
            $response = Http::asForm()
                ->acceptJson()
                ->timeout((int) config('services.google_embeddings.timeout'))
                ->connectTimeout(10)
                ->post(self::TOKEN_ENDPOINT, [
                    'grant_type' => self::GRANT_TYPE,
                    'assertion' => $assertion,
                ]);
        } catch (ConnectionException) {
            throw $this->failure('token endpoint was unreachable.');
        }

        if (! $response->successful()) {
            throw $this->failure("token exchange failed (HTTP {$response->status()}).", $response->status());
        }

        $token = $response->json('access_token');

        if (! is_string($token) || $token === '') {
            throw $this->failure('token exchange returned no access token.', $response->status());
        }

        $expiresIn = $response->json('expires_in');

        return [
            'token' => $token,
            'expires_in' => is_numeric($expiresIn) ? (int) $expiresIn : self::TOKEN_LIFETIME_SECONDS,
        ];
    }

    /**
     * Every failure of this flow is an Authentication-category provider
     * failure (frozen contract) with a message safe to persist and log.
     */
    private function failure(string $sanitizedMessage, ?int $httpStatus = null): ProviderCallException
    {
        return new ProviderCallException(
            SourceRegistry::GOOGLE_GEMINI_EMBEDDINGS,
            ErrorCategory::Authentication,
            SourceRegistry::GOOGLE_GEMINI_EMBEDDINGS.' '.$sanitizedMessage,
            $httpStatus,
        );
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
