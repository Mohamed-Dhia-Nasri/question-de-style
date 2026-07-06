<?php

namespace App\Platform\Enrichment\Http;

use App\Platform\Ingestion\DTO\ProviderResponse;
use App\Platform\Ingestion\Exceptions\ProviderCallException;
use App\Platform\Ingestion\SourceRegistry;
use App\Platform\Ingestion\Support\ErrorCategory;

/**
 * SRC-google-video-intelligence — OPTIONAL deep pass over full video
 * content (ON_SCREEN_TEXT, LOGO per the raw→domain mapping). The API is
 * asynchronous: videos:annotate returns a long-running operation which is
 * polled (bounded) until done. Video bytes are sent INLINE, never as a
 * URL (DP-005).
 */
class GoogleVideoIntelligenceClient extends GoogleApiClient
{
    private const POLL_INTERVAL_SECONDS = 5;

    protected function sourceId(): string
    {
        return SourceRegistry::GOOGLE_VIDEO_INTELLIGENCE;
    }

    protected function configKey(): string
    {
        return 'google_video_intelligence';
    }

    /**
     * Annotate one video with on-screen text + logo recognition and wait
     * (bounded by the configured timeout) for the operation to finish.
     */
    public function annotateVideo(string $videoBytes): ProviderResponse
    {
        $startedAt = microtime(true);

        $operation = $this->post('videos:annotate', [
            'inputContent' => base64_encode($videoBytes),
            'features' => ['TEXT_DETECTION', 'LOGO_RECOGNITION'],
        ]);

        $name = $operation['name'] ?? null;

        if (! is_string($name) || $name === '') {
            throw new ProviderCallException(
                $this->sourceId(),
                ErrorCategory::SchemaDrift,
                $this->sourceId().' returned no operation name.',
            );
        }

        $deadline = $startedAt + (int) config('services.google_video_intelligence.timeout');

        do {
            sleep(self::POLL_INTERVAL_SECONDS);

            $operation = $this->post('operations/'.$name.':wait', [
                'timeout' => self::POLL_INTERVAL_SECONDS.'s',
            ]);

            $done = (bool) ($operation['done'] ?? false);
        } while (! $done && microtime(true) < $deadline);

        if (! $done) {
            throw new ProviderCallException(
                $this->sourceId(),
                ErrorCategory::Timeout,
                $this->sourceId().' operation did not finish within the configured timeout.',
            );
        }

        $results = $operation['response']['annotationResults'] ?? null;

        if (! is_array($results) || ! array_is_list($results)) {
            throw new ProviderCallException(
                $this->sourceId(),
                ErrorCategory::SchemaDrift,
                $this->sourceId().' returned no annotationResults list.',
            );
        }

        return new ProviderResponse(
            items: $results,
            httpStatus: 200,
            responseBytes: strlen((string) json_encode($operation)),
            requestMs: (microtime(true) - $startedAt) * 1000,
            sourceVersion: 'google-video-intelligence-v1',
        );
    }
}
