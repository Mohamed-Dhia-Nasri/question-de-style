<?php

namespace App\Platform\Ingestion\Providers\Instagram;

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
 * SRC-apify-instagram-profile-scraper — profile metrics, bio, and links
 * (docs/40-integrations/00-data-source-matrix.md §3). Does NOT return
 * email/phone; contact details stay manual-entry only (DEF-002) — this
 * adapter deliberately maps no contact fields.
 */
class InstagramProfileAdapter implements ProfileProvider
{
    use NormalizesItems;

    public function __construct(private readonly ApifyClient $client) {}

    public function source(): string
    {
        return SourceRegistry::APIFY_INSTAGRAM_PROFILE_SCRAPER;
    }

    public function platform(): Platform
    {
        return Platform::Instagram;
    }

    public function fetchProfile(string $handle): NormalizedBatch
    {
        $response = $this->client->runActor(
            $this->source(),
            (string) config('services.apify.actors.instagram_profile'),
            ['usernames' => [$handle]],
        );

        return $this->normalizeBatch($response, function (array $item) use ($response): ProfileData {
            if (isset($item['error'])) {
                throw new RecordRejected(
                    ErrorCategory::MissingRequiredFields,
                    'Profile scraper reported an error item for this handle (account unavailable or private).',
                    Extract::hint($item),
                );
            }

            $username = Extract::requireString($item, 'Instagram profile', 'username');

            return new ProfileData(
                platform: Platform::Instagram,
                handle: $username,
                bio: Extract::string($item, 'biography'),
                externalLinks: $this->links($item),
                followerCount: Extract::publicMetric('followers', Extract::int($item, 'followersCount')),
                provenance: new Provenance($this->source(), CarbonImmutable::now(), $response->sourceVersion),
            );
        });
    }

    /**
     * @param  array<array-key, mixed>  $item
     * @return list<string>
     */
    private function links(array $item): array
    {
        $links = [];

        $external = $item['externalUrls'] ?? null;

        if (is_array($external)) {
            foreach ($external as $entry) {
                if (is_string($entry) && $entry !== '') {
                    $links[] = $entry;
                } elseif (is_array($entry) && is_string($entry['url'] ?? null) && $entry['url'] !== '') {
                    $links[] = $entry['url'];
                }
            }
        }

        $single = $item['externalUrl'] ?? null;

        if (is_string($single) && $single !== '') {
            $links[] = $single;
        }

        return array_values(array_unique($links));
    }
}
