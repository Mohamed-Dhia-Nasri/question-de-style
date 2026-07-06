<?php

namespace App\Platform\Ingestion\Http;

use App\Platform\Ingestion\Exceptions\ProviderCallException;
use App\Platform\Ingestion\SourceRegistry;
use App\Platform\Ingestion\Support\ErrorCategory;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Thin HTTP client for the official YouTube Data API v3
 * (SRC-youtube-data-api-v3 — public stats only, DEF-004 defers OAuth
 * analytics). The API key comes ONLY from environment-managed config; it is
 * sent as the `key` query parameter as the API requires, but never appears
 * in exceptions or logs (errors are classified + sanitized here).
 */
class YouTubeClient
{
    private const SOURCE = SourceRegistry::YOUTUBE_DATA_API_V3;

    /**
     * @param  array<string, mixed>  $query
     */
    public function get(string $endpoint, array $query): RawJsonResponse
    {
        $apiKey = (string) config('services.youtube.api_key');

        if ($apiKey === '') {
            throw new ProviderCallException(
                self::SOURCE,
                ErrorCategory::Authentication,
                'YouTube API key is not configured (set YOUTUBE_API_KEY).',
            );
        }

        $url = rtrim((string) config('services.youtube.base_url'), '/').'/'.ltrim($endpoint, '/');

        $startedAt = microtime(true);

        try {
            $response = Http::acceptJson()
                ->timeout((int) config('services.youtube.timeout'))
                ->connectTimeout(10)
                ->get($url, [...$query, 'key' => $apiKey]);
        } catch (ConnectionException $e) {
            $timedOut = str_contains(strtolower($e->getMessage()), 'time');

            throw new ProviderCallException(
                self::SOURCE,
                $timedOut ? ErrorCategory::Timeout : ErrorCategory::Network,
                $timedOut
                    ? "YouTube Data API [{$endpoint}] request timed out."
                    : "YouTube Data API [{$endpoint}] was unreachable (network error).",
            );
        }

        $requestMs = (microtime(true) - $startedAt) * 1000;

        $this->assertSuccessful($endpoint, $response);

        $data = $response->json();

        if (! is_array($data)) {
            throw new ProviderCallException(
                self::SOURCE,
                ErrorCategory::MalformedResponse,
                "YouTube Data API [{$endpoint}] returned a non-JSON body.",
                $response->status(),
            );
        }

        return new RawJsonResponse(
            data: $data,
            httpStatus: $response->status(),
            responseBytes: strlen($response->body()),
            requestMs: $requestMs,
        );
    }

    private function assertSuccessful(string $endpoint, Response $response): void
    {
        if ($response->successful()) {
            return;
        }

        $status = $response->status();
        $reason = $this->errorReason($response);

        // Google signals quota exhaustion as 403 with a quota reason —
        // that is a rate limit, not an authentication failure.
        $isQuota = in_array($reason, ['quotaExceeded', 'dailyLimitExceeded', 'rateLimitExceeded', 'userRateLimitExceeded'], true);

        // An invalid/absent key is returned as HTTP 400 (reason `badRequest`,
        // message "API key not valid"), NOT 401/403 — verified live. Treat
        // it as an authentication failure so the health view flags a
        // credential problem rather than a mystery UNKNOWN.
        $isInvalidKey = $status === 400
            && str_contains(strtolower((string) $response->json('error.message')), 'api key');

        [$category, $message] = match (true) {
            $status === 429 || $isQuota => [
                ErrorCategory::RateLimited,
                "YouTube Data API quota/rate limit hit on [{$endpoint}] (HTTP {$status}).",
            ],
            $status === 401, $status === 403, $isInvalidKey => [
                ErrorCategory::Authentication,
                "YouTube Data API rejected the credentials on [{$endpoint}] (HTTP {$status}).",
            ],
            $status >= 500 => [
                ErrorCategory::UpstreamError,
                "YouTube Data API failed upstream on [{$endpoint}] (HTTP {$status}).",
            ],
            default => [
                ErrorCategory::Unknown,
                "YouTube Data API returned unexpected HTTP {$status} on [{$endpoint}].",
            ],
        };

        throw new ProviderCallException(self::SOURCE, $category, $message, $status);
    }

    private function errorReason(Response $response): ?string
    {
        $reason = $response->json('error.errors.0.reason');

        return is_string($reason) ? $reason : null;
    }
}
