<?php

namespace App\Platform\Ingestion\Providers\YouTube;

use App\Platform\Ingestion\Contracts\ProfileProvider;
use App\Platform\Ingestion\DTO\NormalizedBatch;
use App\Platform\Ingestion\DTO\ProfileData;
use App\Platform\Ingestion\DTO\ProviderResponse;
use App\Platform\Ingestion\Http\YouTubeClient;
use App\Platform\Ingestion\Normalization\Extract;
use App\Platform\Ingestion\Normalization\NormalizesItems;
use App\Platform\Ingestion\Normalization\RecordRejected;
use App\Platform\Ingestion\SourceRegistry;
use App\Platform\Ingestion\Support\ErrorCategory;
use App\Shared\Enums\Platform;
use App\Shared\ValueObjects\Provenance;
use Carbon\CarbonImmutable;

/**
 * SRC-youtube-data-api-v3 — channel metadata + public subscriber count
 * (docs/40-integrations/00-data-source-matrix.md §3). Public stats only;
 * authorized-creator analytics are deferred (DEF-004).
 */
class YouTubeProfileAdapter implements ProfileProvider
{
    use NormalizesItems;

    /** Provenance sourceVersion for the official API. */
    public const SOURCE_VERSION = 'youtube-data-api-v3';

    public function __construct(private readonly YouTubeClient $client) {}

    public function source(): string
    {
        return SourceRegistry::YOUTUBE_DATA_API_V3;
    }

    public function platform(): Platform
    {
        return Platform::YouTube;
    }

    public function fetchProfile(string $handle): NormalizedBatch
    {
        $raw = $this->client->get('channels', [
            'part' => 'snippet,statistics',
            ...(str_starts_with($handle, 'UC') && strlen($handle) === 24
                ? ['id' => $handle]
                : ['forHandle' => $handle]),
            'maxResults' => 1,
        ]);

        $items = $raw->data['items'] ?? [];

        if (! is_array($items)) {
            $items = [];
        }

        $response = new ProviderResponse(
            items: array_values($items),
            httpStatus: $raw->httpStatus,
            responseBytes: $raw->responseBytes,
            requestMs: $raw->requestMs,
            sourceVersion: self::SOURCE_VERSION,
        );

        return $this->normalizeBatch($response, function (array $item) use ($handle, $response): ProfileData {
            $snippet = is_array($item['snippet'] ?? null) ? $item['snippet'] : [];
            $statistics = is_array($item['statistics'] ?? null) ? $item['statistics'] : [];

            if ($snippet === []) {
                throw new RecordRejected(
                    ErrorCategory::MissingRequiredFields,
                    'YouTube channel item has no snippet object.',
                    Extract::hint($item),
                );
            }

            // Subscriber counts may be hidden by the channel — absent then,
            // never fabricated as zero.
            $subscribers = ($statistics['hiddenSubscriberCount'] ?? false) === true
                ? null
                : Extract::int($statistics, 'subscriberCount');

            return new ProfileData(
                platform: Platform::YouTube,
                handle: Extract::string($snippet, 'customUrl') ?? $handle,
                bio: Extract::string($snippet, 'description'),
                externalLinks: [],
                followerCount: Extract::publicMetric('subscribers', $subscribers),
                provenance: new Provenance($this->source(), CarbonImmutable::now(), $response->sourceVersion),
            );
        });
    }
}
