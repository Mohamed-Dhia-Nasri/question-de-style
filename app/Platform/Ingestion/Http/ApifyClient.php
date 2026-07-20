<?php

namespace App\Platform\Ingestion\Http;

use App\Platform\Ingestion\DTO\ProviderResponse;
use App\Platform\Ingestion\Exceptions\ProviderCallException;
use App\Platform\Ingestion\Support\ErrorCategory;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

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

        $response = $this->request($sourceId, $actorId, fn () => Http::withToken($token)
            ->acceptJson()
            ->timeout((int) config('services.apify.timeout'))
            ->connectTimeout(10)
            ->post($url, $input));

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

        $items = $this->resolveControlEnvelope($sourceId, $actorId, $items, $response->status());

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

    /**
     * Run an actor via the ASYNC endpoint (start run → poll → read dataset).
     * Required for batched inputs (whole-roster story polls, direct-URL
     * refresh): Apify's synchronous endpoint 408s at its 300s wall, and a
     * client-side abort of a still-running actor is billed anyway — the
     * async path waits out long runs without double-billing (cost plan
     * recs 3/8). Same security posture as runActor: token only in the
     * Authorization header, sanitized classified errors only.
     *
     * @param  array<string, mixed>  $input  actor input document
     */
    public function runActorAsync(string $sourceId, string $actorId, array $input): ProviderResponse
    {
        $token = (string) config('services.apify.token');

        if ($token === '') {
            throw new ProviderCallException(
                $sourceId,
                ErrorCategory::Authentication,
                'Apify token is not configured (set APIFY_TOKEN).',
            );
        }

        $base = rtrim((string) config('services.apify.base_url'), '/');
        $deadline = microtime(true) + max(60, (int) config('services.apify.async_timeout'));
        $startedAt = microtime(true);

        $start = $this->request($sourceId, $actorId, fn () => Http::withToken($token)
            ->acceptJson()
            ->timeout(90)
            ->connectTimeout(10)
            ->post("{$base}/acts/{$actorId}/runs?waitForFinish=60", $input));

        $this->assertSuccessful($sourceId, $actorId, $start);

        $run = $start->json('data');

        if (! is_array($run) || ! is_string($run['id'] ?? null)) {
            throw new ProviderCallException(
                $sourceId,
                ErrorCategory::MalformedResponse,
                "Apify actor [{$actorId}] run-start returned no run id.",
                $start->status(),
            );
        }

        $runId = $run['id'];
        $status = (string) ($run['status'] ?? 'READY');

        while (! in_array($status, ['SUCCEEDED', 'FAILED', 'TIMED-OUT', 'ABORTED'], true)) {
            if (microtime(true) >= $deadline) {
                throw new ProviderCallException(
                    $sourceId,
                    ErrorCategory::Timeout,
                    "Apify actor [{$actorId}] async run did not finish within the configured deadline.",
                );
            }

            $poll = $this->request($sourceId, $actorId, fn () => Http::withToken($token)
                ->acceptJson()
                ->timeout(90)
                ->connectTimeout(10)
                ->get("{$base}/actor-runs/{$runId}?waitForFinish=60"));

            $this->assertSuccessful($sourceId, $actorId, $poll);

            $run = is_array($poll->json('data')) ? $poll->json('data') : [];
            $status = (string) ($run['status'] ?? 'RUNNING');
        }

        if ($status !== 'SUCCEEDED') {
            throw new ProviderCallException(
                $sourceId,
                $status === 'TIMED-OUT' ? ErrorCategory::Timeout : ErrorCategory::UpstreamError,
                "Apify actor [{$actorId}] async run ended {$status}.",
            );
        }

        $datasetId = $run['defaultDatasetId'] ?? null;

        if (! is_string($datasetId) || $datasetId === '') {
            throw new ProviderCallException(
                $sourceId,
                ErrorCategory::MalformedResponse,
                "Apify actor [{$actorId}] run succeeded but exposed no dataset id.",
            );
        }

        $itemsResponse = $this->request($sourceId, $actorId, fn () => Http::withToken($token)
            ->acceptJson()
            ->timeout(120)
            ->connectTimeout(10)
            ->get("{$base}/datasets/{$datasetId}/items?clean=true&format=json"));

        $this->assertSuccessful($sourceId, $actorId, $itemsResponse);

        $items = $itemsResponse->json();

        if (! is_array($items) || ($items !== [] && ! array_is_list($items))) {
            throw new ProviderCallException(
                $sourceId,
                is_array($items) ? ErrorCategory::SchemaDrift : ErrorCategory::MalformedResponse,
                "Apify actor [{$actorId}] dataset returned a non-list body.",
                $itemsResponse->status(),
            );
        }

        $items = $this->resolveControlEnvelope($sourceId, $actorId, $items, $itemsResponse->status());

        /** @var list<mixed> $items */
        return new ProviderResponse(
            items: $items,
            httpStatus: $itemsResponse->status(),
            responseBytes: strlen($itemsResponse->body()),
            requestMs: (microtime(true) - $startedAt) * 1000,
            sourceVersion: $actorId,
            rateLimit: $this->rateLimitState($itemsResponse),
        );
    }

    /**
     * Run one HTTP call with the shared connection-failure classification.
     *
     * @param  callable(): Response  $call
     */
    private function request(string $sourceId, string $actorId, callable $call): Response
    {
        try {
            return $call();
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
     * Apify actors signal a CONTROL condition — not real data — as a SINGLE
     * dataset item carrying a top-level string `error` (a genuine content
     * record keys on id/shortCode, never on a bare `error`). Three cases,
     * each classified for the health view and alerts instead of being
     * silently quarantined as a vague "missing id" record. Returns the item
     * list to normalize (unchanged, or [] when the run had no content).
     *
     *  - Paid/rental access error — e.g. `{ "error": "…only available for
     *    paying users…", "trial_actor_id": "…" }`. A free account cannot use
     *    the actor; surface a call-level AUTHENTICATION failure so operators
     *    rent it or override the configured actor id.
     *  - No content — `{ "error": "no_items", "errorDescription": "Empty or
     *    private data …" }`. The account has no posts in the refresh window
     *    (or is private): a LEGITIMATE zero-result run, not a failure. Resolve
     *    to an empty list so the batch is a clean success — nothing to
     *    quarantine, and no false SCHEMA_DRIFT ("probable schema change")
     *    alert. Verified live: creator fouuu_x, zero reels in 14 days.
     *  - Any other actor-reported error — an UPSTREAM_ERROR (retryable), not
     *    malformed content masquerading as a missing-id record.
     *
     * Sanitized: raw provider identifiers never leave this method.
     *
     * @param  list<mixed>  $items
     * @return list<mixed>
     */
    private function resolveControlEnvelope(string $sourceId, string $actorId, array $items, int $httpStatus): array
    {
        if (count($items) !== 1 || ! is_array($items[0])) {
            return $items;
        }

        $item = $items[0];
        $error = $item['error'] ?? null;

        if (! is_string($error)) {
            return $items;
        }

        $description = is_string($item['errorDescription'] ?? null) ? $item['errorDescription'] : '';

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

        $isNoContent = $error === 'no_items'
            || (bool) preg_match('/empty or private|no (posts|reels|results|items|stories)|zero (public )?(posts|reels)/i', $error.' '.$description);

        if ($isNoContent) {
            Log::info('Apify actor returned no content — treating as an empty result.', [
                'source' => $sourceId,
                'actor' => $actorId,
                'error' => $error,
            ]);

            return [];
        }

        throw new ProviderCallException(
            $sourceId,
            ErrorCategory::UpstreamError,
            "Apify actor [{$actorId}] reported a run error (not content).",
            $httpStatus,
        );
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
