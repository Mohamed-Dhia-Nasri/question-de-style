<?php

namespace App\Platform\Enrichment\VisualMatch\Http;

use App\Platform\Enrichment\Support\AiPayloadGuard;
use App\Platform\Enrichment\VisualMatch\Contracts\EmbeddingProvider;
use App\Platform\Ingestion\Exceptions\ProviderCallException;
use App\Platform\Ingestion\SourceRegistry;
use App\Platform\Ingestion\Support\ErrorCategory;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * SRC-google-gemini-embeddings (ADR-0029): Gemini Embedding 2 via the
 * verified :embedContent method on the EU multi-region endpoint (locked
 * residency decision — ML processing stays within EU member states; the
 * global endpoint offers NO guarantee and must not be used; the legacy
 * :predict/instances shape belongs to multimodalembedding@001 only).
 *
 * Verified call shape (spec §18, 2026-07-19): one inlineData part per
 * call → ONE vector at embedding.values; output width pinned via
 * embedContentConfig.outputDimensionality; Bearer-token auth ONLY (API
 * keys cannot call :embedContent). Image bytes travel INLINE base64 —
 * no URL ever reaches the provider (DP-005) — and every payload passes
 * the AiPayloadGuard BEFORE a token is fetched or a byte is sent.
 * Errors map onto the house ErrorCategory taxonomy exactly like
 * GoogleApiClient; the token never appears in URLs, logs, or exceptions.
 *
 * Telemetry division: ProviderCallRecorder wrapping (operation
 * `embedding.embed`) lives in the CALLERS (ReferencePhotoEmbedder /
 * KeyframeEmbedder) — they own the correlation id this interface
 * deliberately does not carry; this class supplies the classified
 * ProviderCallException that recordFailure() consumes. The circuit-
 * breaker consult happens in VisualProductMatcher BEFORE spending.
 */
final class GeminiMultimodalEmbeddingProvider implements EmbeddingProvider
{
    public function __construct(
        private readonly GoogleServiceAccountTokenProvider $tokens,
    ) {}

    public function isConfigured(): bool
    {
        return $this->tokens->isConfigured();
    }

    public function modelVersion(): string
    {
        return (string) config('qds.enrichment.visual_match.model_version');
    }

    /** @return list<float> */
    public function embedImage(string $bytes, string $mimeType): array
    {
        if (! $this->isConfigured()) {
            throw new ProviderCallException(
                SourceRegistry::GOOGLE_GEMINI_EMBEDDINGS,
                ErrorCategory::Authentication,
                SourceRegistry::GOOGLE_GEMINI_EMBEDDINGS.' is not configured.',
            );
        }

        $dimensions = (int) config('qds.enrichment.visual_match.dimensions');

        $payload = [
            'content' => [
                'parts' => [
                    ['inlineData' => ['mimeType' => $mimeType, 'data' => base64_encode($bytes)]],
                ],
            ],
            'embedContentConfig' => ['outputDimensionality' => $dimensions],
        ];

        // DP-005 gate FIRST — before a token is fetched or a byte leaves.
        AiPayloadGuard::assertSafe($payload);

        $token = $this->tokens->token();

        try {
            $response = Http::withToken($token)
                ->acceptJson()
                ->timeout((int) config('services.google_embeddings.timeout'))
                ->connectTimeout(10)
                ->post($this->endpoint(), $payload);
        } catch (ConnectionException $e) {
            $timedOut = str_contains(strtolower($e->getMessage()), 'time');

            throw new ProviderCallException(
                SourceRegistry::GOOGLE_GEMINI_EMBEDDINGS,
                $timedOut ? ErrorCategory::Timeout : ErrorCategory::Network,
                $timedOut
                    ? SourceRegistry::GOOGLE_GEMINI_EMBEDDINGS.' request timed out.'
                    : SourceRegistry::GOOGLE_GEMINI_EMBEDDINGS.' was unreachable (network error).',
            );
        }

        $this->assertSuccessful($response);

        $body = $response->json();

        if (! is_array($body)) {
            throw new ProviderCallException(
                SourceRegistry::GOOGLE_GEMINI_EMBEDDINGS,
                ErrorCategory::MalformedResponse,
                SourceRegistry::GOOGLE_GEMINI_EMBEDDINGS.' returned a non-JSON body.',
                $response->status(),
            );
        }

        // Verified response shape: the vector lives at embedding.values —
        // and its width must match the pinned outputDimensionality (a
        // mismatch is provider drift, never silently stored).
        $values = $body['embedding']['values'] ?? null;

        if (! is_array($values) || ! array_is_list($values) || count($values) !== $dimensions) {
            throw new ProviderCallException(
                SourceRegistry::GOOGLE_GEMINI_EMBEDDINGS,
                ErrorCategory::SchemaDrift,
                sprintf(
                    '%s returned %s vector values (expected %d).',
                    SourceRegistry::GOOGLE_GEMINI_EMBEDDINGS,
                    is_array($values) ? (string) count($values) : 'no',
                    $dimensions,
                ),
                $response->status(),
            );
        }

        $vector = [];

        foreach ($values as $value) {
            if (! is_numeric($value)) {
                throw new ProviderCallException(
                    SourceRegistry::GOOGLE_GEMINI_EMBEDDINGS,
                    ErrorCategory::SchemaDrift,
                    SourceRegistry::GOOGLE_GEMINI_EMBEDDINGS.' returned a non-numeric vector component.',
                    $response->status(),
                );
            }

            $vector[] = (float) $value;
        }

        return $vector;
    }

    /**
     * {base}/projects/{project}/locations/{location}/publishers/google/
     * models/{model}:embedContent (verified v1 path).
     */
    private function endpoint(): string
    {
        $project = (string) config('services.google_embeddings.project_id');
        $location = (string) config('services.google_embeddings.location');

        return sprintf(
            '%s/projects/%s/locations/%s/publishers/google/models/%s:embedContent',
            $this->baseUrl($location),
            $project,
            $location,
            $this->modelVersion(),
        );
    }

    /**
     * Regionalized hosts carry the location subdomain (`eu` — the
     * residency guarantee); only the guarantee-free global endpoint does
     * not. Derived here so ops can still override the host via env.
     */
    private function baseUrl(string $location): string
    {
        $configured = config('services.google_embeddings.base_url');

        if (is_string($configured) && $configured !== '') {
            return rtrim($configured, '/');
        }

        return $location === 'global'
            ? 'https://aiplatform.googleapis.com/v1'
            : "https://aiplatform.{$location}.rep.googleapis.com/v1";
    }

    /**
     * GoogleApiClient's taxonomy, minus the API-key branches (Bearer-only
     * auth here): 429/RESOURCE_EXHAUSTED → RateLimited (+ Retry-After),
     * 401/403 → Authentication, 408 → Timeout, 5xx → UpstreamError.
     */
    private function assertSuccessful(Response $response): void
    {
        if ($response->successful()) {
            return;
        }

        $status = $response->status();
        $reason = $this->errorReason($response);

        $category = match (true) {
            $status === 429 => ErrorCategory::RateLimited,
            in_array($reason, ['rateLimitExceeded', 'userRateLimitExceeded', 'quotaExceeded', 'RESOURCE_EXHAUSTED'], true) => ErrorCategory::RateLimited,
            $status === 401, $status === 403 => ErrorCategory::Authentication,
            $status === 408 => ErrorCategory::Timeout,
            $status >= 500 => ErrorCategory::UpstreamError,
            default => ErrorCategory::Unknown,
        };

        $retryAfter = null;

        if ($category === ErrorCategory::RateLimited) {
            $header = $response->header('Retry-After');
            $retryAfter = is_numeric($header) ? (int) $header : null;
        }

        throw new ProviderCallException(
            SourceRegistry::GOOGLE_GEMINI_EMBEDDINGS,
            $category,
            SourceRegistry::GOOGLE_GEMINI_EMBEDDINGS." request failed (HTTP {$status}".($reason !== null ? ", {$reason}" : '').').',
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

        $errorStatus = $body['error']['status'] ?? null;

        if (is_string($errorStatus) && $errorStatus !== '') {
            return $errorStatus;
        }

        $reason = $body['error']['errors'][0]['reason'] ?? ($body['error']['details'][0]['reason'] ?? null);

        return is_string($reason) && $reason !== '' ? $reason : null;
    }
}
