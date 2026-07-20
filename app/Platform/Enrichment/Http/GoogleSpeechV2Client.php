<?php

namespace App\Platform\Enrichment\Http;

use App\Platform\Enrichment\Support\AiPayloadGuard;
use App\Platform\Enrichment\VisualMatch\Http\GoogleServiceAccountTokenProvider;
use App\Platform\Ingestion\Exceptions\ProviderCallException;
use App\Platform\Ingestion\SourceRegistry;
use App\Platform\Ingestion\Support\ErrorCategory;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * SRC-google-speech-to-text over Speech-to-Text v2 (sub-project D,
 * ADR-0030): chirp_3 with languageCodes ["auto"] (dominant-language
 * detection, per-result language codes back) and inline adaptation
 * phrase hints, on the EU regional endpoint
 * {location}-speech.googleapis.com with the implicit recognizer `_`
 * (residency: locations/eu — the speech part of DEF-020 closes here).
 *
 * Auth is Bearer-ONLY via the generalized service-account token provider
 * (v2 documents no API-key support): the container's contextual binding
 * hands this client an instance keyed to services.google_speech_v2 with
 * cache key qds:google-speech-v2-token. Audio travels INLINE base64,
 * always mono FLAC (v2 bills per channel), never a URL (DP-005). The
 * textual config passes the AiPayloadGuard BEFORE a token is fetched or
 * a byte leaves; the base64 audio is excluded from the guard by design
 * (§5 doctrine — its alphabet cannot trip the guard's patterns, and
 * regex-scanning megabytes of it would be pure waste).
 *
 * Telemetry division (the GeminiMultimodalEmbeddingProvider pattern):
 * ProviderCallRecorder wrapping (operation `speech.recognize`, name
 * unchanged for v2) and the ProviderCircuitBreaker consult live in the
 * CALLERS — this class supplies the classified ProviderCallException
 * that recordFailure() consumes. The token never appears in URLs, logs,
 * or exceptions. The legacy v1 GoogleSpeechClient is untouched — it is
 * the rollback path while qds.enrichment.speech.v2_enabled is off.
 */
final class GoogleSpeechV2Client
{
    public function __construct(
        private readonly GoogleServiceAccountTokenProvider $tokens,
    ) {}

    public function isConfigured(): bool
    {
        return $this->tokens->isConfigured();
    }

    /**
     * Transcribe one ≤60 s mono FLAC chunk. $phrases are adaptation
     * hints (brand/product names) — trimmed, deduped, capped here as the
     * hard-limit backstop (callers pre-assemble per spec §9).
     *
     * @param  list<string>  $phrases
     *
     * @throws ProviderCallException transport/HTTP/shape errors, classified
     */
    public function recognize(string $flacBytes, array $phrases): SpeechV2Result
    {
        if (! $this->isConfigured()) {
            throw new ProviderCallException(
                SourceRegistry::GOOGLE_SPEECH_TO_TEXT,
                ErrorCategory::Authentication,
                SourceRegistry::GOOGLE_SPEECH_TO_TEXT.' v2 is not configured.',
            );
        }

        $config = [
            'model' => (string) config('qds.enrichment.speech.model'),
            'languageCodes' => array_values((array) config('qds.enrichment.speech.language_codes')),
            // MUST serialize as {} — an empty PHP array would encode as [].
            'autoDecodingConfig' => (object) [],
            'features' => ['enableAutomaticPunctuation' => true],
        ];

        // Adaptation is gated: chirp_3 rejects the inline_phrase_set shape with a
        // 404 (go-live smoke, 2026-07-21). Default OFF so recognize works; re-enable
        // via qds.enrichment.speech.adaptation_enabled once the shape is verified.
        $hints = config('qds.enrichment.speech.adaptation_enabled')
            ? $this->preparePhrases($phrases)
            : [];

        if ($hints !== []) {
            $boost = min(20.0, max(0.0, (float) config('qds.enrichment.speech.boost')));

            $config['adaptation'] = [
                'phraseSets' => [
                    ['inlinePhraseSet' => ['phrases' => array_map(
                        fn (string $phrase): array => ['value' => $phrase, 'boost' => $boost],
                        $hints,
                    )]],
                ],
            ];
        }

        // DP-005 gate FIRST — on the textual view only, before a token is
        // fetched or a byte leaves (base64 audio excluded by design, §5).
        AiPayloadGuard::assertSafe($config);

        $token = $this->tokens->token();

        try {
            $response = Http::withToken($token)
                ->acceptJson()
                ->timeout((int) config('services.google_speech_v2.timeout'))
                ->connectTimeout(10)
                ->post($this->endpoint(), [
                    'config' => $config,
                    'content' => base64_encode($flacBytes),
                ]);
        } catch (ConnectionException $e) {
            $timedOut = str_contains(strtolower($e->getMessage()), 'time');

            throw new ProviderCallException(
                SourceRegistry::GOOGLE_SPEECH_TO_TEXT,
                $timedOut ? ErrorCategory::Timeout : ErrorCategory::Network,
                $timedOut
                    ? SourceRegistry::GOOGLE_SPEECH_TO_TEXT.' v2 request timed out.'
                    : SourceRegistry::GOOGLE_SPEECH_TO_TEXT.' v2 was unreachable (network error).',
            );
        }

        $this->assertSuccessful($response);

        $body = $response->json();

        if (! is_array($body)) {
            throw new ProviderCallException(
                SourceRegistry::GOOGLE_SPEECH_TO_TEXT,
                ErrorCategory::MalformedResponse,
                SourceRegistry::GOOGLE_SPEECH_TO_TEXT.' returned a non-JSON body.',
                $response->status(),
            );
        }

        $results = $body['results'] ?? [];

        if (! is_array($results) || ! array_is_list($results)) {
            throw new ProviderCallException(
                SourceRegistry::GOOGLE_SPEECH_TO_TEXT,
                ErrorCategory::SchemaDrift,
                SourceRegistry::GOOGLE_SPEECH_TO_TEXT.' returned a non-list results field.',
                $response->status(),
            );
        }

        $parsed = [];

        foreach ($results as $result) {
            if (! is_array($result)) {
                continue;
            }

            $alternative = $result['alternatives'][0] ?? null;
            $transcript = is_array($alternative) ? ($alternative['transcript'] ?? null) : null;

            if (! is_string($transcript) || trim($transcript) === '') {
                continue; // no usable alternative — skipped, never fabricated
            }

            $confidence = $alternative['confidence'] ?? null;
            $languageCode = $result['languageCode'] ?? null;

            $parsed[] = [
                'transcript' => $transcript,
                'confidence' => is_numeric($confidence) ? (float) $confidence : null,
                'languageCode' => is_string($languageCode) && $languageCode !== '' ? $languageCode : null,
            ];
        }

        return new SpeechV2Result($parsed, $this->billedSeconds($body));
    }

    /**
     * Trim, drop empties, dedupe (order-preserving), cap at phrase_cap —
     * the backstop under chirp_3's 1,000-phrase dictionary hard limit.
     *
     * @param  list<string>  $phrases
     * @return list<string>
     */
    private function preparePhrases(array $phrases): array
    {
        $cap = max(0, (int) config('qds.enrichment.speech.phrase_cap'));
        $clean = [];

        foreach ($phrases as $phrase) {
            if (! is_string($phrase)) {
                continue;
            }

            $trimmed = trim($phrase);

            if ($trimmed === '' || in_array($trimmed, $clean, true)) {
                continue;
            }

            $clean[] = $trimmed;

            if (count($clean) >= $cap) {
                break;
            }
        }

        return $clean;
    }

    /**
     * metadata.totalBilledDuration is a duration string ("15s", "15.500s");
     * billing rounds up per second. REST casing re-verified by the §18
     * smoke task before go-live. Absent/unparseable → null, never 0.
     *
     * @param  array<string, mixed>  $body
     */
    private function billedSeconds(array $body): ?int
    {
        $duration = $body['metadata']['totalBilledDuration'] ?? null;

        if (! is_string($duration) || preg_match('/^(\d+(?:\.\d+)?)s$/', $duration, $matches) !== 1) {
            return null;
        }

        return (int) ceil((float) $matches[1]);
    }

    /**
     * {base}/projects/{project}/locations/{location}/recognizers/_:recognize
     * — the implicit recognizer `_` is official; no recognizer resource
     * management needed (spec §2b.11).
     */
    private function endpoint(): string
    {
        $project = (string) config('services.google_speech_v2.project_id');
        $location = (string) config('services.google_speech_v2.location');

        return sprintf(
            '%s/projects/%s/locations/%s/recognizers/_:recognize',
            $this->baseUrl($location),
            $project,
            $location,
        );
    }

    /**
     * Regional hosts carry the location subdomain ({location}-speech —
     * the residency posture); only the guarantee-free global endpoint
     * does not. Derived here so ops can override the host via env.
     */
    private function baseUrl(string $location): string
    {
        $configured = config('services.google_speech_v2.base_url');

        if (is_string($configured) && $configured !== '') {
            return rtrim($configured, '/');
        }

        return $location === 'global'
            ? 'https://speech.googleapis.com/v2'
            : "https://{$location}-speech.googleapis.com/v2";
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
            SourceRegistry::GOOGLE_SPEECH_TO_TEXT,
            $category,
            SourceRegistry::GOOGLE_SPEECH_TO_TEXT." v2 request failed (HTTP {$status}".($reason !== null ? ", {$reason}" : '').').',
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
