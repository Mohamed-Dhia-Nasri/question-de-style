<?php

namespace App\Platform\Enrichment\VlmVerification\Http;

use App\Platform\Enrichment\Support\AiPayloadGuard;
use App\Platform\Enrichment\VisualMatch\Http\GoogleServiceAccountTokenProvider;
use App\Platform\Enrichment\VlmVerification\Requests\VlmRequest;
use App\Platform\Ingestion\Exceptions\ProviderCallException;
use App\Platform\Ingestion\SourceRegistry;
use App\Platform\Ingestion\Support\ErrorCategory;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * SRC-google-gemini-vlm (ADR-0030): gemini-3.5-flash generateContent on
 * the EU jurisdictional endpoint (aiplatform.eu.rep.googleapis.com — ML
 * processing stays within EU member states, spec §2b.2). The `global`
 * location carries NO residency guarantee and is treated as UNCONFIGURED,
 * never derived into an endpoint. Bearer-token auth only, via a
 * VLM-scoped GoogleServiceAccountTokenProvider instance (config block
 * `services.google_vlm`, cache key `qds:google-vlm-token`); the token
 * never appears in URLs, logs, or exception messages.
 *
 * Outcome division (frozen contract): transport/HTTP failures THROW a
 * classified ProviderCallException; safety blocks RETURN (blockReason
 * non-null — permanent, the billed attempt is already in the ledger);
 * MAX_TOKENS / unparseable candidate text RETURN an empty json (the
 * VerdictValidator classifies it malformed and drives the bounded
 * corrective retry, spec §6). AiPayloadGuard::assertSafe runs on the
 * TEXTUAL request view BEFORE the token fetch — base64 frame bytes are
 * never regex-scanned and never leave unguarded (spec §5).
 *
 * Telemetry division (C's precedent, GeminiMultimodalEmbeddingProvider):
 * ProviderCallRecorder wrapping (operation `vlm.verify`) and the
 * ProviderCircuitBreaker::shouldSkip(SRC-google-gemini-vlm) consult live
 * in the CALLER (VlmVerificationJob) — it owns the correlation id and the
 * crash-safe attempts ledger; this class supplies the classified
 * exceptions that recordFailure() consumes.
 */
final class GeminiVlmClient
{
    /** Response-level finish reasons that are PERMANENT safety blocks (spec §5). */
    private const BLOCK_FINISH_REASONS = ['SAFETY', 'RECITATION', 'BLOCKLIST', 'PROHIBITED_CONTENT', 'SPII'];

    public function __construct(
        private readonly GoogleServiceAccountTokenProvider $tokens,
    ) {}

    public function isConfigured(): bool
    {
        return $this->tokens->isConfigured()
            && (string) config('services.google_vlm.location') !== 'global';
    }

    public function modelVersion(): string
    {
        return (string) config('qds.enrichment.vlm.model_version');
    }

    /**
     * @throws ProviderCallException transport/HTTP errors only; safety
     *                               blocks RETURN a blocked result
     */
    public function verify(VlmRequest $request): VlmProviderResult
    {
        if (! $this->isConfigured()) {
            throw new ProviderCallException(
                SourceRegistry::GOOGLE_GEMINI_VLM,
                ErrorCategory::Authentication,
                SourceRegistry::GOOGLE_GEMINI_VLM.' is not configured.',
            );
        }

        // DP-005 gate FIRST — on the textual view, before a token is
        // fetched or a byte leaves (spec §5).
        AiPayloadGuard::assertSafe($request->textualPayload());

        $token = $this->tokens->token();

        try {
            $response = Http::withToken($token)
                ->acceptJson()
                ->timeout((int) config('services.google_vlm.timeout'))
                ->connectTimeout(10)
                ->post($this->endpoint(), $request->payload());
        } catch (ConnectionException $e) {
            $timedOut = str_contains(strtolower($e->getMessage()), 'time');

            throw new ProviderCallException(
                SourceRegistry::GOOGLE_GEMINI_VLM,
                $timedOut ? ErrorCategory::Timeout : ErrorCategory::Network,
                $timedOut
                    ? SourceRegistry::GOOGLE_GEMINI_VLM.' request timed out.'
                    : SourceRegistry::GOOGLE_GEMINI_VLM.' was unreachable (network error).',
            );
        }

        $this->assertSuccessful($response);

        $body = $response->json();

        if (! is_array($body)) {
            throw new ProviderCallException(
                SourceRegistry::GOOGLE_GEMINI_VLM,
                ErrorCategory::MalformedResponse,
                SourceRegistry::GOOGLE_GEMINI_VLM.' returned a non-JSON body.',
                $response->status(),
            );
        }

        return $this->interpret($body, $response->status());
    }

    /** @param array<string, mixed> $body */
    private function interpret(array $body, int $httpStatus): VlmProviderResult
    {
        [$promptTokens, $outputTokens, $thinkingTokens] = $this->usage($body);

        $candidate = $body['candidates'][0] ?? null;
        $finishReason = is_array($candidate)
            && is_string($candidate['finishReason'] ?? null)
            && $candidate['finishReason'] !== ''
                ? $candidate['finishReason']
                : null;

        // Prompt-level block: permanent, usually no candidate at all (§5).
        $blockReason = $body['promptFeedback']['blockReason'] ?? null;

        if (is_string($blockReason) && $blockReason !== '') {
            return new VlmProviderResult([], $blockReason, $finishReason ?? 'BLOCKED', $promptTokens, $outputTokens, $thinkingTokens);
        }

        if ($finishReason === null) {
            throw new ProviderCallException(
                SourceRegistry::GOOGLE_GEMINI_VLM,
                ErrorCategory::MalformedResponse,
                SourceRegistry::GOOGLE_GEMINI_VLM.' response carried no candidate.',
                $httpStatus,
            );
        }

        if (in_array($finishReason, self::BLOCK_FINISH_REASONS, true)) {
            return new VlmProviderResult([], $finishReason, $finishReason, $promptTokens, $outputTokens, $thinkingTokens);
        }

        $text = $candidate['content']['parts'][0]['text'] ?? null;
        $decoded = is_string($text) ? json_decode($text, true) : null;

        // MAX_TOKENS truncation / missing / undecodable text → empty json:
        // the VerdictValidator classifies it malformed and the job drives
        // the bounded corrective retry (§6) — never a transport throw.
        return new VlmProviderResult(
            is_array($decoded) ? $decoded : [],
            null,
            $finishReason,
            $promptTokens,
            $outputTokens,
            $thinkingTokens,
        );
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array{0: ?int, 1: ?int, 2: ?int}
     */
    private function usage(array $body): array
    {
        $usage = $body['usageMetadata'] ?? null;
        $usage = is_array($usage) ? $usage : [];

        $count = fn (string $key): ?int => is_numeric($usage[$key] ?? null) ? (int) $usage[$key] : null;

        return [$count('promptTokenCount'), $count('candidatesTokenCount'), $count('thoughtsTokenCount')];
    }

    /**
     * {base}/projects/{project}/locations/{location}/publishers/google/
     * models/{model}:generateContent — C's derivation rule (spec §5).
     */
    private function endpoint(): string
    {
        $project = (string) config('services.google_vlm.project_id');
        $location = (string) config('services.google_vlm.location');

        return sprintf(
            '%s/projects/%s/locations/%s/publishers/google/models/%s:generateContent',
            $this->baseUrl($location),
            $project,
            $location,
            $this->modelVersion(),
        );
    }

    /**
     * Regionalized hosts carry the location subdomain (`eu` — the
     * residency guarantee). No `global` branch exists here on purpose:
     * isConfigured() rejects it before any endpoint is derived. Ops can
     * still override the host via env.
     */
    private function baseUrl(string $location): string
    {
        $configured = config('services.google_vlm.base_url');

        if (is_string($configured) && $configured !== '') {
            return rtrim($configured, '/');
        }

        return "https://aiplatform.{$location}.rep.googleapis.com/v1";
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
            SourceRegistry::GOOGLE_GEMINI_VLM,
            $category,
            SourceRegistry::GOOGLE_GEMINI_VLM." request failed (HTTP {$status}".($reason !== null ? ", {$reason}" : '').').',
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
