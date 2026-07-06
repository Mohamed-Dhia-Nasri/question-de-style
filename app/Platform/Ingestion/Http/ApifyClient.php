<?php

namespace App\Platform\Ingestion\Http;

use App\Platform\Ingestion\DTO\ProviderResponse;
use App\Platform\Ingestion\Exceptions\ProviderCallException;
use App\Platform\Ingestion\Support\ErrorCategory;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Thin HTTP client for Apify actors (the Instagram SRC-apify-* actors and
 * the sole TikTok source SRC-clockworks-tiktok-scraper, ADR-0002). Uses the
 * synchronous run-sync-get-dataset-items endpoint, which returns the actor
 * run's dataset items directly.
 *
 * Security: the token travels ONLY in the Authorization header (never in
 * the URL), comes ONLY from environment-managed config, and never appears
 * in exceptions or logs. All error messages thrown from here are sanitized
 * and classified (ErrorCategory).
 */
class ApifyClient
{
    /**
     * @param  array<string, mixed>  $input  actor input document
     */
    public function runActor(string $sourceId, string $actorId, array $input): ProviderResponse
    {
        $token = (string) config('services.apify.token');

        if ($token === '') {
            throw new ProviderCallException(
                $sourceId,
                ErrorCategory::Authentication,
                'Apify token is not configured (set APIFY_TOKEN).',
            );
        }

        $url = rtrim((string) config('services.apify.base_url'), '/')
            ."/acts/{$actorId}/run-sync-get-dataset-items";

        $startedAt = microtime(true);

        try {
            $response = Http::withToken($token)
                ->acceptJson()
                ->timeout((int) config('services.apify.timeout'))
                ->connectTimeout(10)
                ->post($url, $input);
        } catch (ConnectionException $e) {
            $timedOut = str_contains(strtolower($e->getMessage()), 'time');

            throw new ProviderCallException(
                $sourceId,
                $timedOut ? ErrorCategory::Timeout : ErrorCategory::Network,
                $timedOut
                    ? "Apify actor [{$actorId}] request timed out."
                    : "Apify actor [{$actorId}] was unreachable (network error).",
            );
        }

        $requestMs = (microtime(true) - $startedAt) * 1000;

        $this->assertSuccessful($sourceId, $actorId, $response);

        $items = $response->json();

        if (! is_array($items)) {
            throw new ProviderCallException(
                $sourceId,
                ErrorCategory::MalformedResponse,
                "Apify actor [{$actorId}] returned a non-JSON or non-array body.",
                $response->status(),
            );
        }

        if ($items !== [] && ! array_is_list($items)) {
            throw new ProviderCallException(
                $sourceId,
                ErrorCategory::SchemaDrift,
                "Apify actor [{$actorId}] returned an object where a dataset item list was expected.",
                $response->status(),
            );
        }

        $this->assertNotAccessError($sourceId, $actorId, $items, $response->status());

        /** @var list<mixed> $items */
        return new ProviderResponse(
            items: $items,
            httpStatus: $response->status(),
            responseBytes: strlen($response->body()),
            requestMs: $requestMs,
            sourceVersion: $actorId,
            rateLimit: $this->rateLimitState($response),
        );
    }

    private function assertSuccessful(string $sourceId, string $actorId, Response $response): void
    {
        $status = $response->status();

        if ($response->successful()) {
            return;
        }

        [$category, $message] = match (true) {
            $status === 401, $status === 403 => [
                ErrorCategory::Authentication,
                "Apify rejected the credentials for actor [{$actorId}] (HTTP {$status}).",
            ],
            $status === 429 => [
                ErrorCategory::RateLimited,
                "Apify rate limit hit for actor [{$actorId}] (HTTP 429).",
            ],
            $status === 408 => [
                ErrorCategory::Timeout,
                "Apify actor [{$actorId}] timed out upstream (HTTP 408).",
            ],
            $status >= 500 => [
                ErrorCategory::UpstreamError,
                "Apify actor [{$actorId}] failed upstream (HTTP {$status}).",
            ],
            default => [
                ErrorCategory::Unknown,
                "Apify actor [{$actorId}] returned unexpected HTTP {$status}.",
            ],
        };

        throw new ProviderCallException(
            $sourceId,
            $category,
            $message,
            $status,
            $status === 429 ? $this->retryAfterSeconds($response) : null,
        );
    }

    /**
     * Some Apify actors (paid/rental ones on a free account) return HTTP 201
     * with a SINGLE dataset item that is an access/paywall error object
     * rather than real data — e.g. `{ "error": "…only available for paying
     * users…", "trial_actor_id": "…" }`. Left alone this would be silently
     * quarantined as a vague "missing id" record; surface it as a clear,
     * call-level AUTHENTICATION failure so the health view and alerts flag
     * that the actor is not accessible. Sanitized: no user identifiers.
     *
     * @param  list<mixed>  $items
     */
    private function assertNotAccessError(string $sourceId, string $actorId, array $items, int $httpStatus): void
    {
        if (count($items) !== 1 || ! is_array($items[0])) {
            return;
        }

        $item = $items[0];
        $error = $item['error'] ?? null;

        if (! is_string($error)) {
            return;
        }

        $isAccessError = isset($item['trial_actor_id'])
            || (bool) preg_match('/paying users|upgrade your plan|rent(al)?|free (users?|plan)|only available for/i', $error);

        if ($isAccessError) {
            throw new ProviderCallException(
                $sourceId,
                ErrorCategory::Authentication,
                "Apify actor [{$actorId}] is not accessible on this account "
                .'(paid/rental actor — the run returned an access/upgrade error).',
                $httpStatus,
            );
        }
    }

    private function retryAfterSeconds(Response $response): ?int
    {
        $header = $response->header('Retry-After');

        return is_numeric($header) ? max(1, (int) $header) : null;
    }

    /** @return array{remaining?: int|null, retry_after?: int|null} */
    private function rateLimitState(Response $response): array
    {
        $remaining = $response->header('X-RateLimit-Remaining');

        return array_filter([
            'remaining' => is_numeric($remaining) ? (int) $remaining : null,
            'retry_after' => $this->retryAfterSeconds($response),
        ], fn (mixed $v): bool => $v !== null);
    }
}
