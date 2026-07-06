<?php

namespace App\Platform\Enrichment\Http;

use App\Platform\Ingestion\DTO\ProviderResponse;
use App\Platform\Ingestion\Exceptions\ProviderCallException;
use App\Platform\Ingestion\SourceRegistry;
use App\Platform\Ingestion\Support\ErrorCategory;

/**
 * SRC-google-cloud-vision — image OCR (TEXT_DETECTION → IMAGE_TEXT_OCR)
 * and logo detection (LOGO_DETECTION → LOGO) per the data-source matrix
 * §2.2. Media is sent INLINE (base64 content), never as a URL — no signed
 * private URL ever reaches the provider (DP-005).
 */
class GoogleVisionClient extends GoogleApiClient
{
    protected function sourceId(): string
    {
        return SourceRegistry::GOOGLE_CLOUD_VISION;
    }

    protected function configKey(): string
    {
        return 'google_vision';
    }

    /**
     * Annotate one image with OCR + logo detection.
     *
     * @param  string  $imageBytes  raw image bytes (encoded here)
     */
    public function annotateImage(string $imageBytes): ProviderResponse
    {
        $startedAt = microtime(true);

        $body = $this->post('images:annotate', [
            'requests' => [[
                'image' => ['content' => base64_encode($imageBytes)],
                'features' => [
                    ['type' => 'TEXT_DETECTION'],
                    ['type' => 'LOGO_DETECTION'],
                ],
            ]],
        ]);

        $requestMs = (microtime(true) - $startedAt) * 1000;

        $responses = $body['responses'] ?? null;

        if (! is_array($responses) || ! array_is_list($responses)) {
            throw new ProviderCallException(
                $this->sourceId(),
                ErrorCategory::SchemaDrift,
                $this->sourceId().' returned no annotation response list.',
            );
        }

        return new ProviderResponse(
            items: $responses,
            httpStatus: 200,
            responseBytes: strlen((string) json_encode($body)),
            requestMs: $requestMs,
            sourceVersion: 'google-cloud-vision-v1',
        );
    }
}
