<?php

namespace App\Platform\Enrichment\Http;

use App\Platform\Enrichment\Support\AiPayloadGuard;
use App\Platform\Ingestion\Exceptions\ProviderCallException;
use App\Platform\Ingestion\Support\ErrorCategory;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Shared HTTP plumbing for the frozen SRC-google-* AI providers
 * (ADR-0001). Mirrors the ApifyClient security boundary:
 *  - the API key travels ONLY in the X-Goog-Api-Key header (never in the
 *    URL, never in logs, never in exceptions);
 *  - every outbound payload passes the AiPayloadGuard (DP-005 — no
 *    personal data, no credentials, no signed URLs leave the platform);
 *  - every failure is classified into a normalized ErrorCategory with a
 *    sanitized message (External API Monitoring).
 */
abstract class GoogleApiClient
{
    /** The SRC-* contract id this client speaks for. */
    abstract protected function sourceId(): string;

    /** The config/services.php key holding credentials for this client. */
    abstract protected function configKey(): string;

    public function isConfigured(): bool
    {
        return (string) config("services.{$this->configKey()}.api_key") !== '';
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed> decoded JSON body
     */
    protected function post(string $path, array $payload): array
    {
        $key = (string) config("services.{$this->configKey()}.api_key");

        if ($key === '') {
            throw new ProviderCallException(
                $this->sourceId(),
                ErrorCategory::Authentication,
                "{$this->sourceId()} API key is not configured.",
            );
        }

        AiPayloadGuard::assertSafe($payload);

        $url = rtrim((string) config("services.{$this->configKey()}.base_url"), '/').'/'.ltrim($path, '/');

        try {
            $response = Http::withHeaders(['X-Goog-Api-Key' => $key])
                ->acceptJson()
                ->timeout((int) config("services.{$this->configKey()}.timeout"))
                ->connectTimeout(10)
                ->post($url, $payload);
        } catch (ConnectionException $e) {
            $timedOut = str_contains(strtolower($e->getMessage()), 'time');

            throw new ProviderCallException(
                $this->sourceId(),
                $timedOut ? ErrorCategory::Timeout : ErrorCategory::Network,
                $timedOut
                    ? "{$this->sourceId()} request timed out."
                    : "{$this->sourceId()} was unreachable (network error).",
            );
        }

        $this->assertSuccessful($response);

        $body = $response->json();

        if (! is_array($body)) {
            throw new ProviderCallException(
                $this->sourceId(),
                ErrorCategory::MalformedResponse,
                "{$this->sourceId()} returned a non-JSON body.",
                $response->status(),
            );
        }

        return $body;
    }

    private function assertSuccessful(Response $response): void
    {
        $status = $response->status();

        if ($response->successful()) {
            return;
        }

        $reason = $this->errorReason($response);

        $category = match (true) {
            $status === 429 => ErrorCategory::RateLimited,
            in_array($reason, ['rateLimitExceeded', 'userRateLimitExceeded', 'quotaExceeded', 'RESOURCE_EXHAUSTED'], true) => ErrorCategory::RateLimited,
            $status === 401, $status === 403 => ErrorCategory::Authentication,
            $status === 408 => ErrorCategory::Timeout,
            $status >= 500 => ErrorCategory::UpstreamError,
            $status === 400 && in_array($reason, ['API_KEY_INVALID', 'keyInvalid'], true) => ErrorCategory::Authentication,
            default => ErrorCategory::Unknown,
        };

        $retryAfter = null;

        if ($category === ErrorCategory::RateLimited) {
            $header = $response->header('Retry-After');
            $retryAfter = is_numeric($header) ? (int) $header : null;
        }

        throw new ProviderCallException(
            $this->sourceId(),
            $category,
            "{$this->sourceId()} request failed (HTTP {$status}".($reason !== null ? ", {$reason}" : '').').',
            $status,
            $retryAfter,
        );
    }

    private function errorReason(Response $response): ?string
    {
        $body = $response->json();

        if (! is_array($body)) {
            return null;
        }

        $status = $body['error']['status'] ?? null;

        if (is_string($status) && $status !== '') {
            return $status;
        }

        $reason = $body['error']['errors'][0]['reason'] ?? ($body['error']['details'][0]['reason'] ?? null);

        return is_string($reason) && $reason !== '' ? $reason : null;
    }
}
