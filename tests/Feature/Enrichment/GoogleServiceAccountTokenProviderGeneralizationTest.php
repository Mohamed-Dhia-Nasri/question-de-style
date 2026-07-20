<?php

namespace Tests\Feature\Enrichment;

use App\Platform\Enrichment\VisualMatch\Http\GoogleServiceAccountTokenProvider;
use App\Platform\Ingestion\Exceptions\ProviderCallException;
use App\Platform\Ingestion\SourceRegistry;
use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Sub-project D Task 5 (spec §5): the C-built token provider becomes
 * parameterized on (services.* config block, cache key, SRC-* source id)
 * so google_vlm and google_speech_v2 each get their own instance — while
 * default construction stays byte-identical to C's embeddings behaviour
 * (the untouched GoogleServiceAccountTokenProviderTest is the regression
 * pin). The GeminiVlmClient / GoogleSpeechV2Client consumers land in
 * Tasks 7/17; their contextual bindings are registered NOW and asserted
 * through the container's public contextual-binding map.
 */
class GoogleServiceAccountTokenProviderGeneralizationTest extends TestCase
{
    /** Task 7's consumer FQCN — the class itself does not exist yet. */
    private const VLM_CLIENT = 'App\\Platform\\Enrichment\\VlmVerification\\Http\\GeminiVlmClient';

    /** Task 17's consumer FQCN — the class itself does not exist yet. */
    private const SPEECH_CLIENT = 'App\\Platform\\Enrichment\\Http\\GoogleSpeechV2Client';

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
     * Throwaway Google-shaped service-account key file wired into the
     * given services.* config block (the GoogleServiceAccountTokenProviderTest
     * pattern, parameterized on the block).
     */
    private function provisionServiceAccount(string $configKey, string $projectId): string
    {
        $key = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        $this->assertNotFalse($key, 'openssl_pkey_new failed');

        $privatePem = '';
        $this->assertTrue(openssl_pkey_export($key, $privatePem));

        $path = tempnam(sys_get_temp_dir(), 'qds-test-sa-');
        $this->assertIsString($path);
        $this->cleanupPaths[] = $path;

        file_put_contents($path, (string) json_encode([
            'type' => 'service_account',
            'project_id' => $projectId,
            'private_key_id' => 'test-key-1',
            'private_key' => $privatePem,
            'client_email' => "qds@{$projectId}.iam.gserviceaccount.com",
            'token_uri' => 'https://oauth2.googleapis.com/token',
        ]));

        config([
            "services.{$configKey}.credentials_path" => $path,
            "services.{$configKey}.project_id" => $projectId,
        ]);

        return $path;
    }

    public function test_default_construction_preserves_the_embeddings_behaviour(): void
    {
        $this->provisionServiceAccount('google_embeddings', 'qds-embeddings-test');
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'ya29.embeddings-token',
                'expires_in' => 3600,
                'token_type' => 'Bearer',
            ]),
        ]);

        $provider = app(GoogleServiceAccountTokenProvider::class);

        $this->assertTrue($provider->isConfigured());
        $this->assertSame('ya29.embeddings-token', $provider->token());

        // The default cache key is unchanged — C's test seam
        // (CACHE_KEY = 'qds:google-embeddings-token') keeps working.
        $this->assertSame(
            'ya29.embeddings-token',
            Cache::get(GoogleServiceAccountTokenProvider::CACHE_KEY),
        );
    }

    public function test_parameterized_instance_reads_only_its_own_config_block(): void
    {
        // Embeddings fully configured; google_vlm NOT — the vlm instance
        // must never borrow the embeddings credentials.
        $this->provisionServiceAccount('google_embeddings', 'qds-embeddings-test');
        config([
            'services.google_vlm.credentials_path' => null,
            'services.google_vlm.project_id' => null,
        ]);

        $vlm = new GoogleServiceAccountTokenProvider(
            'google_vlm', 'qds:google-vlm-token', SourceRegistry::GOOGLE_GEMINI_VLM,
        );

        $this->assertFalse($vlm->isConfigured());

        $this->provisionServiceAccount('google_vlm', 'qds-vlm-test');
        $this->assertTrue($vlm->isConfigured());
    }

    public function test_instances_cache_tokens_under_isolated_keys(): void
    {
        Http::fake();

        Cache::put('qds:google-embeddings-token', 'embeddings-cached', 3540);
        Cache::put('qds:google-vlm-token', 'vlm-cached', 3540);
        Cache::put('qds:google-speech-v2-token', 'speech-cached', 3540);

        $embeddings = new GoogleServiceAccountTokenProvider();
        $vlm = new GoogleServiceAccountTokenProvider(
            'google_vlm', 'qds:google-vlm-token', SourceRegistry::GOOGLE_GEMINI_VLM,
        );
        $speech = new GoogleServiceAccountTokenProvider(
            'google_speech_v2', 'qds:google-speech-v2-token', SourceRegistry::GOOGLE_SPEECH_TO_TEXT,
        );

        $this->assertSame('embeddings-cached', $embeddings->token());
        $this->assertSame('vlm-cached', $vlm->token());
        $this->assertSame('speech-cached', $speech->token());

        // All three served from cache — never the network.
        Http::assertNothingSent();
    }

    public function test_failures_carry_the_instance_source_id(): void
    {
        Http::fake();

        $path = tempnam(sys_get_temp_dir(), 'qds-test-sa-');
        $this->assertIsString($path);
        $this->cleanupPaths[] = $path;
        file_put_contents($path, 'not-json');

        config([
            'services.google_vlm.credentials_path' => $path,
            'services.google_vlm.project_id' => 'qds-vlm-test',
            'services.google_speech_v2.credentials_path' => $path,
            'services.google_speech_v2.project_id' => 'qds-speech-test',
        ]);

        $vlm = new GoogleServiceAccountTokenProvider(
            'google_vlm', 'qds:google-vlm-token', SourceRegistry::GOOGLE_GEMINI_VLM,
        );

        try {
            $vlm->token();
            $this->fail('Expected a ProviderCallException.');
        } catch (ProviderCallException $e) {
            $this->assertSame(SourceRegistry::GOOGLE_GEMINI_VLM, $e->source);
        }

        $speech = new GoogleServiceAccountTokenProvider(
            'google_speech_v2', 'qds:google-speech-v2-token', SourceRegistry::GOOGLE_SPEECH_TO_TEXT,
        );

        try {
            $speech->token();
            $this->fail('Expected a ProviderCallException.');
        } catch (ProviderCallException $e) {
            $this->assertSame(SourceRegistry::GOOGLE_SPEECH_TO_TEXT, $e->source);
        }

        Http::assertNothingSent();
    }

    public function test_contextual_bindings_give_parameterized_instances_to_the_d_clients(): void
    {
        // The concrete client classes do not exist until Tasks 7/17, so
        // the bindings are asserted through the container's PUBLIC
        // contextual map (Laravel stores give() closures verbatim there,
        // keyed [concrete][abstract]) and invoked directly.
        $vlmClosure = $this->app->contextual[self::VLM_CLIENT][GoogleServiceAccountTokenProvider::class] ?? null;
        $speechClosure = $this->app->contextual[self::SPEECH_CLIENT][GoogleServiceAccountTokenProvider::class] ?? null;

        $this->assertInstanceOf(Closure::class, $vlmClosure);
        $this->assertInstanceOf(Closure::class, $speechClosure);

        Http::fake();
        Cache::put('qds:google-vlm-token', 'vlm-cached', 3540);
        Cache::put('qds:google-speech-v2-token', 'speech-cached', 3540);

        $vlm = $vlmClosure($this->app);
        $speech = $speechClosure($this->app);

        $this->assertInstanceOf(GoogleServiceAccountTokenProvider::class, $vlm);
        $this->assertInstanceOf(GoogleServiceAccountTokenProvider::class, $speech);

        // Each binding is parameterized on ITS cache key…
        $this->assertSame('vlm-cached', $vlm->token());
        $this->assertSame('speech-cached', $speech->token());
        Http::assertNothingSent();

        // …and on ITS config block.
        config([
            'services.google_vlm.credentials_path' => null,
            'services.google_vlm.project_id' => null,
            'services.google_speech_v2.credentials_path' => null,
            'services.google_speech_v2.project_id' => null,
        ]);
        $this->assertFalse($vlm->isConfigured());
        $this->assertFalse($speech->isConfigured());

        $this->provisionServiceAccount('google_vlm', 'qds-vlm-test');
        $this->assertTrue($vlm->isConfigured());
        $this->assertFalse($speech->isConfigured());
    }
}
