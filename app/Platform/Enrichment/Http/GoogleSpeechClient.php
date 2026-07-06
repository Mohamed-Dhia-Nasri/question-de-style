<?php

namespace App\Platform\Enrichment\Http;

use App\Platform\Ingestion\DTO\ProviderResponse;
use App\Platform\Ingestion\Exceptions\ProviderCallException;
use App\Platform\Ingestion\SourceRegistry;
use App\Platform\Ingestion\Support\ErrorCategory;

/**
 * SRC-google-speech-to-text — spoken-brand detection input (SPOKEN_BRAND)
 * per the data-source matrix §2.2, German models enabled (DACH focus).
 * Audio is sent INLINE (base64 content), never as a URL (DP-005).
 */
class GoogleSpeechClient extends GoogleApiClient
{
    protected function sourceId(): string
    {
        return SourceRegistry::GOOGLE_SPEECH_TO_TEXT;
    }

    protected function configKey(): string
    {
        return 'google_speech';
    }

    /**
     * Transcribe one short audio payload; returns the raw recognition
     * results (alternatives with transcript + confidence).
     */
    public function recognize(string $audioBytes): ProviderResponse
    {
        $startedAt = microtime(true);

        $body = $this->post('speech:recognize', [
            'config' => [
                'languageCode' => (string) config('services.google_speech.language_code'),
                'enableAutomaticPunctuation' => true,
            ],
            'audio' => ['content' => base64_encode($audioBytes)],
        ]);

        $requestMs = (microtime(true) - $startedAt) * 1000;

        $results = $body['results'] ?? [];

        if (! is_array($results) || ! array_is_list($results)) {
            throw new ProviderCallException(
                $this->sourceId(),
                ErrorCategory::SchemaDrift,
                $this->sourceId().' returned a non-list results field.',
            );
        }

        return new ProviderResponse(
            items: $results,
            httpStatus: 200,
            responseBytes: strlen((string) json_encode($body)),
            requestMs: $requestMs,
            sourceVersion: 'google-speech-to-text-v1',
        );
    }
}
