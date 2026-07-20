<?php

namespace Tests\Feature\Enrichment;

use App\Platform\Enrichment\Http\GoogleSpeechV2Client;
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
 * SRC-google-speech-to-text over the v2 :recognize shape (spec §9/§18,
 * 2026-07-20): chirp_3 + languageCodes ["auto"] (dominant-language
 * detection) + autoDecodingConfig + inline adaptation phrase hints, on
 * the EU regional endpoint with Bearer-ONLY auth (v2 documents no API
 * keys). The textual config passes the AiPayloadGuard BEFORE a token is
 * fetched; the base64 audio is excluded by design (§5 doctrine — its
 * alphabet cannot trip the guard's patterns).
 */
class GoogleSpeechV2ClientTest extends TestCase
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
     * Configured client with a pre-warmed bearer token: the OAuth flow is
     * covered by the Task 5 token-provider tests; recognize never touches
     * the token endpoint here. The credentials file is a stub — it only
     * satisfies isConfigured() while the token cache is warm.
     */
    private function configureClient(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'qds-test-sa-');
        $this->assertIsString($path);
        $this->cleanupPaths[] = $path;
        file_put_contents($path, '{"client_email":"qds-speech@qds-speech-test.iam.gserviceaccount.com"}');

        config([
            'services.google_speech_v2.credentials_path' => $path,
            'services.google_speech_v2.project_id' => 'qds-speech-test',
        ]);

        // Task 5's contextual binding hands this client its OWN token
        // provider instance with its own cache key.
        Cache::put('qds:google-speech-v2-token', 'test-bearer-token', 3540);
    }

    private function recognizeExpectingFailure(): ProviderCallException
    {
        try {
            app(GoogleSpeechV2Client::class)->recognize('fake-flac-bytes', ['Nexon Labs']);
        } catch (ProviderCallException $e) {
            return $e;
        }

        $this->fail('Expected a ProviderCallException.');
    }

    public function test_recognize_posts_the_verified_v2_body_and_parses_results(): void
    {
        $this->configureClient();
        // Adaptation is gated off by default (chirp_3 404'd on the inline_phrase_set
        // shape — go-live smoke 2026-07-21); enable it here to pin the shape it sends
        // when re-enabled.
        config(['qds.enrichment.speech.adaptation_enabled' => true]);
        Http::fake([
            'eu-speech.googleapis.com/*' => Http::response([
                'results' => [
                    ['alternatives' => [['transcript' => 'wir lieben das Nexon Labs Headset', 'confidence' => 0.92]], 'languageCode' => 'de-DE'],
                    ['alternatives' => [['transcript' => 'switching to English now', 'confidence' => 0.88]], 'languageCode' => 'en-US'],
                    ['alternatives' => []], // no usable alternative → skipped, never fabricated
                ],
                'metadata' => ['totalBilledDuration' => '15s'],
            ]),
        ]);

        $result = app(GoogleSpeechV2Client::class)
            ->recognize('fake-flac-bytes', ['Nexon Labs', 'Nexon Labs Headset', 'Nexon Labs']);

        // Detected language is captured PER RESULT (["auto"] semantics).
        $this->assertSame([
            ['transcript' => 'wir lieben das Nexon Labs Headset', 'confidence' => 0.92, 'languageCode' => 'de-DE'],
            ['transcript' => 'switching to English now', 'confidence' => 0.88, 'languageCode' => 'en-US'],
        ], $result->results);
        $this->assertSame(15, $result->billedSeconds);

        Http::assertSent(function (Request $request): bool {
            // v2 regional endpoint + implicit recognizer `_` (spec §9) —
            // exact match proves no query string; Bearer header only.
            $this->assertSame(
                'https://eu-speech.googleapis.com/v2/projects/qds-speech-test/locations/eu/recognizers/_:recognize',
                $request->url(),
            );
            $this->assertSame('Bearer test-bearer-token', $request->header('Authorization')[0] ?? null);
            $this->assertFalse($request->hasHeader('X-Goog-Api-Key'));

            // The exact §9 body: chirp_3, ["auto"], autoDecodingConfig,
            // punctuation feature, inline adaptation (the duplicate phrase
            // deduped), base64 FLAC content. Laravel 12's recorded Request
            // ->data() returns the raw `json` option verbatim (floats and
            // all — so `boost` stays 10.0), which means the empty-object
            // autoDecodingConfig surfaces here as the stdClass the client
            // passed; pin that field by shape, then strict-compare the rest.
            $data = $request->data();
            $this->assertEquals(new \stdClass(), $data['config']['autoDecodingConfig']);
            unset($data['config']['autoDecodingConfig']);

            $this->assertSame([
                'config' => [
                    'model' => 'chirp_3',
                    'languageCodes' => ['auto'],
                    'features' => ['enableAutomaticPunctuation' => true],
                    'adaptation' => ['phraseSets' => [['inlinePhraseSet' => ['phrases' => [
                        ['value' => 'Nexon Labs', 'boost' => 10.0],
                        ['value' => 'Nexon Labs Headset', 'boost' => 10.0],
                    ]]]]],
                ],
                'content' => base64_encode('fake-flac-bytes'),
            ], $data);

            // Wire format: autoDecodingConfig MUST serialize as {} — a PHP
            // empty array would encode as [] and the v2 API expects an
            // object at this field.
            $this->assertStringContainsString('"autoDecodingConfig":{}', $request->body());

            return true;
        });
    }

    public function test_adaptation_is_omitted_when_no_phrases_survive(): void
    {
        $this->configureClient();
        Http::fake(['eu-speech.googleapis.com/*' => Http::response(['results' => []])]);

        app(GoogleSpeechV2Client::class)->recognize('fake-flac-bytes', ['', '   ']);

        Http::assertSent(function (Request $request): bool {
            $this->assertArrayNotHasKey('adaptation', $request->data()['config']);

            return true;
        });
    }

    public function test_adaptation_is_omitted_when_disabled_even_with_phrases(): void
    {
        $this->configureClient();
        // Default posture (adaptation_enabled=false): the live chirp_3 API rejects
        // the inline_phrase_set adaptation shape with HTTP 404 "Requested entity was
        // not found" (go-live smoke, 2026-07-21), so no adaptation block is sent even
        // when brand/product phrases are supplied.
        Http::fake(['eu-speech.googleapis.com/*' => Http::response(['results' => []])]);

        app(GoogleSpeechV2Client::class)->recognize('fake-flac-bytes', ['Nexon Labs', 'Nexon Labs Headset']);

        Http::assertSent(function (Request $request): bool {
            $this->assertArrayNotHasKey('adaptation', $request->data()['config']);

            return true;
        });
    }

    public function test_phrases_are_trimmed_deduped_and_capped(): void
    {
        $this->configureClient();
        config(['qds.enrichment.speech.adaptation_enabled' => true]);
        config(['qds.enrichment.speech.phrase_cap' => 2]);
        Http::fake(['eu-speech.googleapis.com/*' => Http::response(['results' => []])]);

        app(GoogleSpeechV2Client::class)->recognize('fake-flac-bytes', [' Alpha ', 'Alpha', 'Beta', 'Gamma']);

        Http::assertSent(function (Request $request): bool {
            $phrases = $request->data()['config']['adaptation']['phraseSets'][0]['inlinePhraseSet']['phrases'];

            $this->assertSame(
                ['Alpha', 'Beta'],
                array_column($phrases, 'value'),
            );

            return true;
        });
    }

    public function test_boost_is_clamped_to_the_documented_range(): void
    {
        $this->configureClient();
        config(['qds.enrichment.speech.adaptation_enabled' => true]);
        config(['qds.enrichment.speech.boost' => 99.0]); // documented range is 0–20
        Http::fake(['eu-speech.googleapis.com/*' => Http::response(['results' => []])]);

        app(GoogleSpeechV2Client::class)->recognize('fake-flac-bytes', ['Nexon Labs']);

        Http::assertSent(function (Request $request): bool {
            $phrases = $request->data()['config']['adaptation']['phraseSets'][0]['inlinePhraseSet']['phrases'];

            $this->assertSame(20.0, $phrases[0]['boost']);

            return true;
        });
    }

    public function test_billed_seconds_rounds_partial_seconds_up_and_is_null_when_absent(): void
    {
        $this->configureClient();
        Http::fake(['eu-speech.googleapis.com/*' => Http::sequence()
            ->push(['results' => [], 'metadata' => ['totalBilledDuration' => '15.500s']])
            ->push('{}'),
        ]);

        $client = app(GoogleSpeechV2Client::class);

        // Billing rounds up per second (§2b.11).
        $this->assertSame(16, $client->recognize('fake-flac-bytes', [])->billedSeconds);

        $empty = $client->recognize('fake-flac-bytes', []);
        $this->assertSame([], $empty->results);
        $this->assertNull($empty->billedSeconds);
    }

    public function test_is_configured_reads_the_speech_v2_block_not_the_embeddings_default(): void
    {
        config([
            'services.google_speech_v2.credentials_path' => null,
            'services.google_speech_v2.project_id' => null,
            // A fully "configured" embeddings block must NOT leak in — this
            // proves the Task 5 contextual binding hands this client its own
            // google_speech_v2-keyed provider, not the embeddings default.
            'services.google_embeddings.credentials_path' => __FILE__,
            'services.google_embeddings.project_id' => 'qds-embeddings-test',
        ]);

        $this->assertFalse(app(GoogleSpeechV2Client::class)->isConfigured());

        $this->configureClient();
        $this->assertTrue(app(GoogleSpeechV2Client::class)->isConfigured());
    }

    public function test_recognizing_while_unconfigured_fails_closed_without_a_network_call(): void
    {
        config([
            'services.google_speech_v2.credentials_path' => null,
            'services.google_speech_v2.project_id' => null,
        ]);
        Http::fake();

        $e = $this->recognizeExpectingFailure();

        $this->assertSame(SourceRegistry::GOOGLE_SPEECH_TO_TEXT, $e->source);
        $this->assertSame(ErrorCategory::Authentication, $e->category);
        Http::assertNothingSent();
    }

    public function test_the_payload_guard_rejects_credential_bearing_phrases_before_any_byte_leaves(): void
    {
        $this->configureClient();
        Http::fake();

        // A signed-URL-style phrase trips the DP-005 credential pattern —
        // proving the guard sits in FRONT of the token fetch and HTTP call.
        try {
            app(GoogleSpeechV2Client::class)
                ->recognize('fake-flac-bytes', ['https://cdn.example/audio.flac?token=leaked']);
            $this->fail('Expected the AiPayloadGuard to reject the payload.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('DP-005', $e->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_rate_limiting_maps_to_rate_limited_with_retry_after(): void
    {
        $this->configureClient();
        Http::fake([
            'eu-speech.googleapis.com/*' => Http::response(
                ['error' => ['status' => 'RESOURCE_EXHAUSTED']],
                429,
                ['Retry-After' => '7'],
            ),
        ]);

        $e = $this->recognizeExpectingFailure();

        $this->assertSame(SourceRegistry::GOOGLE_SPEECH_TO_TEXT, $e->source);
        $this->assertSame(ErrorCategory::RateLimited, $e->category);
        $this->assertSame(429, $e->httpStatus);
        $this->assertSame(7, $e->retryAfterSeconds);
    }

    public function test_denied_access_maps_to_authentication_and_never_leaks_the_token(): void
    {
        $this->configureClient();
        Http::fake([
            'eu-speech.googleapis.com/*' => Http::response(['error' => ['status' => 'PERMISSION_DENIED']], 403),
        ]);

        $e = $this->recognizeExpectingFailure();

        $this->assertSame(ErrorCategory::Authentication, $e->category);
        $this->assertStringNotContainsString('test-bearer-token', $e->getMessage());
    }

    public function test_server_errors_map_to_upstream_error(): void
    {
        $this->configureClient();
        Http::fake(['eu-speech.googleapis.com/*' => Http::response('', 500)]);

        $this->assertSame(ErrorCategory::UpstreamError, $this->recognizeExpectingFailure()->category);
    }

    public function test_a_non_json_body_maps_to_malformed_response(): void
    {
        $this->configureClient();
        Http::fake(['eu-speech.googleapis.com/*' => Http::response('speech-but-not-json')]);

        $this->assertSame(ErrorCategory::MalformedResponse, $this->recognizeExpectingFailure()->category);
    }

    public function test_a_non_list_results_field_maps_to_schema_drift(): void
    {
        $this->configureClient();
        Http::fake(['eu-speech.googleapis.com/*' => Http::response(['results' => 'not-a-list'])]);

        $this->assertSame(ErrorCategory::SchemaDrift, $this->recognizeExpectingFailure()->category);
    }

    public function test_a_connection_timeout_maps_to_timeout(): void
    {
        $this->configureClient();
        // The token is cached, so the ONLY outbound call is the recognize —
        // this exception unambiguously exercises the recognize error path.
        Http::fake([
            'eu-speech.googleapis.com/*' => fn () => throw new ConnectionException('cURL error 28: Operation timed out after 60001 ms'),
        ]);

        $this->assertSame(ErrorCategory::Timeout, $this->recognizeExpectingFailure()->category);
    }

    public function test_an_explicit_base_url_overrides_the_derived_eu_endpoint(): void
    {
        $this->configureClient();
        config(['services.google_speech_v2.base_url' => 'https://speech.proxy.internal/v2']);
        Http::fake(['speech.proxy.internal/*' => Http::response(['results' => []])]);

        app(GoogleSpeechV2Client::class)->recognize('fake-flac-bytes', []);

        Http::assertSent(fn (Request $request): bool => str_starts_with(
            $request->url(),
            'https://speech.proxy.internal/v2/projects/qds-speech-test/locations/eu/',
        ));
    }
}
