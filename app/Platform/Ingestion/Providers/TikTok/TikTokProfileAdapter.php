<?php

namespace App\Platform\Ingestion\Providers\TikTok;

use App\Platform\Ingestion\Contracts\ProfileProvider;
use App\Platform\Ingestion\DTO\NormalizedBatch;
use App\Platform\Ingestion\DTO\ProfileData;
use App\Platform\Ingestion\Http\ApifyClient;
use App\Platform\Ingestion\Normalization\Extract;
use App\Platform\Ingestion\Normalization\NormalizesItems;
use App\Platform\Ingestion\Normalization\RecordRejected;
use App\Platform\Ingestion\SourceRegistry;
use App\Platform\Ingestion\Support\ErrorCategory;
use App\Shared\Enums\Platform;
use App\Shared\ValueObjects\Provenance;
use Carbon\CarbonImmutable;

/**
 * SRC-clockworks-tiktok-scraper — the ONLY TikTok provider (ADR-0002);
 * profile branch: authorMeta of the profile's items carries the public
 * fan/follower counts and bio (docs/40-integrations/00-data-source-matrix.md §3).
 */
class TikTokProfileAdapter implements ProfileProvider
{
    use NormalizesItems;

    public function __construct(private readonly ApifyClient $client) {}

    public function source(): string
    {
        return SourceRegistry::CLOCKWORKS_TIKTOK_SCRAPER;
    }

    public function platform(): Platform
    {
        return Platform::TikTok;
    }

    public function fetchProfile(string $handle): NormalizedBatch
    {
        $response = $this->client->runActor(
            $this->source(),
            (string) config('services.apify.actors.tiktok'),
            [
                'profiles' => [$handle],
                'resultsPerPage' => 1,
            ],
        );

        $seen = [];

        return $this->normalizeBatch($response, function (array $item) use ($response, &$seen): ?ProfileData {
            $author = $item['authorMeta'] ?? null;

            if (! is_array($author)) {
                throw new RecordRejected(
                    ErrorCategory::MissingRequiredFields,
                    'TikTok item has no authorMeta object — cannot derive the profile.',
                    Extract::hint($item),
                );
            }

            $username = Extract::requireString($author, 'TikTok profile', 'name', 'uniqueId');

            // Several items share one author; emit the profile once.
            if (isset($seen[$username])) {
                return null;
            }

            $seen[$username] = true;

            return new ProfileData(
                platform: Platform::TikTok,
                handle: $username,
                bio: Extract::string($author, 'signature'),
                externalLinks: array_filter([Extract::string($author, 'bioLink')]),
                followerCount: Extract::publicMetric('followers', Extract::int($author, 'fans', 'followers')),
                provenance: new Provenance($this->source(), CarbonImmutable::now(), $response->sourceVersion),
            );
        });
    }
}
