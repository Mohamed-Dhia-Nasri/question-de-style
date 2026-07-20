<?php

namespace Tests\Feature\Enrichment;

use App\Platform\Enrichment\VisualMatch\Contracts\EmbeddingProvider;
use App\Platform\Enrichment\VlmVerification\Http\GeminiVlmClient;
use App\Platform\Enrichment\VlmVerification\Requests\VlmCandidate;
use App\Platform\Enrichment\VlmVerification\Requests\VlmFrame;
use App\Platform\Enrichment\VlmVerification\Requests\VlmRequest;
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
 * SRC-google-gemini-vlm (ADR-0030): generateContent on the EU
 * jurisdictional endpoint, Bearer-only auth via the VLM-scoped token
 * provider instance. Transport/HTTP failures THROW classified
 * ProviderCallExceptions; safety blocks RETURN (permanent, §5); MAX_TOKENS
 * and unparseable candidate text RETURN an empty json for the
 * VerdictValidator's bounded retry (§6). Every payload passes the
 * AiPayloadGuard (textual view) BEFORE any byte or token fetch leaves.
 */
class GeminiVlmClientTest extends TestCase
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
     * Configured client with a pre-warmed VLM-scoped bearer token: the
     * OAuth flow has its own tests (Task 5); verify() never touches the
     * token endpoint here. The credentials file is a stub — it only
     * satisfies isConfigured() while the token cache is warm.
     */
    private function configureClient(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'qds-test-sa-');
        $this->assertIsString($path);
        $this->cleanupPaths[] = $path;
        file_put_contents($path, '{"client_email":"qds-vlm@qds-vlm-test.iam.gserviceaccount.com"}');

        config([
            'services.google_vlm.credentials_path' => $path,
            'services.google_vlm.project_id' => 'qds-vlm-test',
        ]);

        Cache::put('qds:google-vlm-token', 'test-bearer-token', 3540);
    }

    private function request(string $caption = 'Unboxing my favorites'): VlmRequest
    {
        return new VlmRequest(
            frames: [new VlmFrame('FRAME_1', 2000, 'frame-one-bytes', 'image/jpeg')],
            candidates: [new VlmCandidate('P123', 123, 'Aurora Glow Serum', 'Lumen Skincare', 'BEAUTY', ['Glow Serum'], 'review', 0.61)],
            caption: $caption,
            transcript: '',
            prompt: 'PROMPT-TEXT '.$caption,
        );
    }

    private function verdictJson(): string
    {
        return (string) json_encode([
            'outcome' => 'PRODUCT_CONFIRMED',
            'verdicts' => [[
                'product_key' => 'P123', 'visible' => true, 'spoken' => false,
                'gifting_cue' => true, 'confidence' => 0.91,
                'frame_names' => ['FRAME_1'], 'rationale' => 'Serum bottle on the desk.',
            ]],
        ]);
    }

    /** @return array<string, mixed> */
    private function successBody(): array
    {
        return [
            'candidates' => [[
                'content' => ['parts' => [['text' => $this->verdictJson()]]],
                'finishReason' => 'STOP',
            ]],
            'usageMetadata' => ['promptTokenCount' => 9500, 'candidatesTokenCount' => 310, 'thoughtsTokenCount' => 120],
        ];
    }

    private function verifyExpectingFailure(?VlmRequest $request = null): ProviderCallException
    {
        try {
            app(GeminiVlmClient::class)->verify($request ?? $this->request());
        } catch (ProviderCallException $e) {
            return $e;
        }

        $this->fail('Expected a ProviderCallException.');
    }

    public function test_the_vlm_config_block_ships_dark_with_the_locked_defaults(): void
    {
        $this->assertFalse((bool) config('qds.enrichment.vlm.enabled'));
        $this->assertSame('gemini-3.5-flash', config('qds.enrichment.vlm.model_version'));
        $this->assertSame('enrichment', config('qds.enrichment.vlm.queue'));
        $this->assertSame(12, config('qds.enrichment.vlm.frame_budget'));
        // Both default EMPTY (omitted from the request): the live generateContent
        // API rejected media_resolution=MEDIA_RESOLUTION_MEDIUM and the thinking_level
        // field with HTTP 400 (go-live smoke, 2026-07-21). Re-enable only with a
        // value/shape verified against current official docs (spec §18).
        $this->assertSame('', config('qds.enrichment.vlm.media_resolution'));
        $this->assertSame('', config('qds.enrichment.vlm.thinking_level'));
        $this->assertSame(2048, config('qds.enrichment.vlm.max_output_tokens'));
        $this->assertSame(2000, config('qds.enrichment.vlm.caption_max_chars'));
        $this->assertSame(4000, config('qds.enrichment.vlm.transcript_max_chars'));
        $this->assertSame(['auto' => 0.85, 'review' => 0.60, 'margin' => 0.10], config('qds.enrichment.vlm.thresholds'));
        $this->assertSame(6, config('qds.enrichment.vlm.pending_stale_hours'));
    }

    public function test_the_contextual_binding_scopes_the_token_provider_to_the_vlm_config_block(): void
    {
        // Only google_vlm is configured — the embeddings provider stays
        // unconfigured, proving the client got its OWN token-provider
        // instance (config block google_vlm, cache key qds:google-vlm-token).
        config([
            'services.google_embeddings.credentials_path' => null,
            'services.google_embeddings.project_id' => null,
        ]);
        $this->configureClient();

        $this->assertTrue(app(GeminiVlmClient::class)->isConfigured());
        $this->assertFalse(app(EmbeddingProvider::class)->isConfigured());
        $this->assertSame('gemini-3.5-flash', app(GeminiVlmClient::class)->modelVersion());
    }

    public function test_verify_posts_the_request_payload_to_the_eu_endpoint(): void
    {
        $this->configureClient();
        Http::fake(['aiplatform.eu.rep.googleapis.com/*' => Http::response($this->successBody())]);
        $request = $this->request();

        $result = app(GeminiVlmClient::class)->verify($request);

        $this->assertSame(json_decode($this->verdictJson(), true), $result->json);
        $this->assertNull($result->blockReason);
        $this->assertSame('STOP', $result->finishReason);
        $this->assertSame(9500, $result->promptTokens);
        $this->assertSame(310, $result->outputTokens);
        $this->assertSame(120, $result->thinkingTokens);

        Http::assertSent(function (Request $sent) use ($request): bool {
            // EU jurisdictional host, v1 path, :generateContent — exact
            // match proves no query string; Bearer header only (no API key).
            $this->assertSame(
                'https://aiplatform.eu.rep.googleapis.com/v1/projects/qds-vlm-test/locations/eu/publishers/google/models/gemini-3.5-flash:generateContent',
                $sent->url(),
            );
            $this->assertSame('Bearer test-bearer-token', $sent->header('Authorization')[0] ?? null);
            $this->assertFalse($sent->hasHeader('X-Goog-Api-Key'));
            $this->assertSame($request->payload(), $sent->data());

            return true;
        });
    }

    public function test_verifying_while_unconfigured_fails_closed_without_a_network_call(): void
    {
        config([
            'services.google_vlm.credentials_path' => null,
            'services.google_vlm.project_id' => null,
        ]);
        Http::fake();

        $e = $this->verifyExpectingFailure();

        $this->assertSame(SourceRegistry::GOOGLE_GEMINI_VLM, $e->source);
        $this->assertSame(ErrorCategory::Authentication, $e->category);
        $this->assertFalse(app(GeminiVlmClient::class)->isConfigured());
        Http::assertNothingSent();
    }

    public function test_the_global_location_is_rejected_as_unconfigured(): void
    {
        // `global` carries NO residency guarantee (spec §2b.2) — fail
        // closed, never derive an endpoint for it.
        $this->configureClient();
        config(['services.google_vlm.location' => 'global']);
        Http::fake();

        $this->assertFalse(app(GeminiVlmClient::class)->isConfigured());
        $this->assertSame(ErrorCategory::Authentication, $this->verifyExpectingFailure()->category);
        Http::assertNothingSent();
    }

    public function test_every_payload_passes_the_ai_payload_guard_before_any_byte_leaves(): void
    {
        $this->configureClient();
        Http::fake();

        // An email address in the caption (echoed into the prompt) trips
        // the DP-005 pattern — proving the guard sits in FRONT of the HTTP
        // call AND the token fetch (spec §5 fail-closed skip).
        try {
            app(GeminiVlmClient::class)->verify($this->request('reach me at leak@example.com'));
            $this->fail('Expected the AiPayloadGuard to reject the payload.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('DP-005', $e->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_a_prompt_level_block_returns_a_blocked_result_without_throwing(): void
    {
        $this->configureClient();
        Http::fake([
            'aiplatform.eu.rep.googleapis.com/*' => Http::response([
                'promptFeedback' => ['blockReason' => 'PROHIBITED_CONTENT'],
                'usageMetadata' => ['promptTokenCount' => 9500],
            ]),
        ]);

        $result = app(GeminiVlmClient::class)->verify($this->request());

        $this->assertSame('PROHIBITED_CONTENT', $result->blockReason);
        $this->assertSame('BLOCKED', $result->finishReason);
        $this->assertSame([], $result->json);
        $this->assertSame(9500, $result->promptTokens);
    }

    public function test_a_safety_finish_reason_returns_a_blocked_result(): void
    {
        $this->configureClient();
        Http::fake([
            'aiplatform.eu.rep.googleapis.com/*' => Http::response([
                'candidates' => [['finishReason' => 'SAFETY']],
            ]),
        ]);

        $result = app(GeminiVlmClient::class)->verify($this->request());

        $this->assertSame('SAFETY', $result->blockReason);
        $this->assertSame('SAFETY', $result->finishReason);
        $this->assertSame([], $result->json);
    }

    public function test_max_tokens_truncation_returns_empty_json_for_the_validator_retry(): void
    {
        $this->configureClient();
        Http::fake([
            'aiplatform.eu.rep.googleapis.com/*' => Http::response([
                'candidates' => [[
                    'content' => ['parts' => [['text' => '{"outcome":"PRODUCT_CON']]],
                    'finishReason' => 'MAX_TOKENS',
                ]],
            ]),
        ]);

        $result = app(GeminiVlmClient::class)->verify($this->request());

        $this->assertNull($result->blockReason);
        $this->assertSame('MAX_TOKENS', $result->finishReason);
        $this->assertSame([], $result->json);
    }

    public function test_unparseable_candidate_text_returns_empty_json_not_a_throw(): void
    {
        $this->configureClient();
        Http::fake([
            'aiplatform.eu.rep.googleapis.com/*' => Http::response([
                'candidates' => [[
                    'content' => ['parts' => [['text' => 'verdicts-but-not-json']]],
                    'finishReason' => 'STOP',
                ]],
            ]),
        ]);

        $result = app(GeminiVlmClient::class)->verify($this->request());

        $this->assertNull($result->blockReason);
        $this->assertSame('STOP', $result->finishReason);
        $this->assertSame([], $result->json);
    }

    public function test_a_body_with_no_candidate_maps_to_malformed_response(): void
    {
        $this->configureClient();
        Http::fake(['aiplatform.eu.rep.googleapis.com/*' => Http::response('{}')]);

        $this->assertSame(ErrorCategory::MalformedResponse, $this->verifyExpectingFailure()->category);
    }

    public function test_a_non_json_body_maps_to_malformed_response(): void
    {
        $this->configureClient();
        Http::fake(['aiplatform.eu.rep.googleapis.com/*' => Http::response('verdict-but-not-json')]);

        $this->assertSame(ErrorCategory::MalformedResponse, $this->verifyExpectingFailure()->category);
    }

    public function test_rate_limiting_maps_to_rate_limited_with_retry_after(): void
    {
        $this->configureClient();
        Http::fake([
            'aiplatform.eu.rep.googleapis.com/*' => Http::response(
                ['error' => ['status' => 'RESOURCE_EXHAUSTED']],
                429,
                ['Retry-After' => '7'],
            ),
        ]);

        $e = $this->verifyExpectingFailure();

        $this->assertSame(SourceRegistry::GOOGLE_GEMINI_VLM, $e->source);
        $this->assertSame(ErrorCategory::RateLimited, $e->category);
        $this->assertSame(429, $e->httpStatus);
        $this->assertSame(7, $e->retryAfterSeconds);
    }

    public function test_denied_access_maps_to_authentication_and_never_leaks_the_token(): void
    {
        $this->configureClient();
        Http::fake([
            'aiplatform.eu.rep.googleapis.com/*' => Http::response(['error' => ['status' => 'PERMISSION_DENIED']], 403),
        ]);

        $e = $this->verifyExpectingFailure();

        $this->assertSame(ErrorCategory::Authentication, $e->category);
        $this->assertStringNotContainsString('test-bearer-token', $e->getMessage());
    }

    public function test_server_errors_map_to_upstream_error(): void
    {
        $this->configureClient();
        Http::fake(['aiplatform.eu.rep.googleapis.com/*' => Http::response('', 500)]);

        $this->assertSame(ErrorCategory::UpstreamError, $this->verifyExpectingFailure()->category);
    }

    public function test_a_connection_timeout_maps_to_timeout(): void
    {
        $this->configureClient();
        // The token is cached, so the ONLY outbound call is the verify —
        // this exception unambiguously exercises the verify error path.
        Http::fake([
            'aiplatform.eu.rep.googleapis.com/*' => fn () => throw new ConnectionException('cURL error 28: Operation timed out after 60001 ms'),
        ]);

        $this->assertSame(ErrorCategory::Timeout, $this->verifyExpectingFailure()->category);
    }

    public function test_an_explicit_base_url_overrides_the_derived_eu_endpoint(): void
    {
        $this->configureClient();
        config(['services.google_vlm.base_url' => 'https://aiplatform.proxy.internal/v1']);
        Http::fake(['aiplatform.proxy.internal/*' => Http::response($this->successBody())]);

        $result = app(GeminiVlmClient::class)->verify($this->request());

        $this->assertSame('STOP', $result->finishReason);
        Http::assertSent(fn (Request $sent): bool => str_starts_with(
            $sent->url(),
            'https://aiplatform.proxy.internal/v1/projects/qds-vlm-test/locations/eu/',
        ));
    }
}
