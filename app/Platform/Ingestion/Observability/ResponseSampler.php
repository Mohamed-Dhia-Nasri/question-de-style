<?php

namespace App\Platform\Ingestion\Observability;

use App\Platform\Ingestion\DTO\ProviderResponse;
use App\Platform\Ingestion\Models\ProviderResponseSample;
use App\Platform\Ingestion\Support\PayloadRedactor;
use Carbon\CarbonImmutable;

/**
 * Limited response sampling for debugging (External API Monitoring):
 * configurable per provider, REDACTED before storage, truncated to a few
 * items, short retention, access restricted by ProviderResponseSamplePolicy.
 */
class ResponseSampler
{
    public function __construct(private readonly PayloadRedactor $redactor) {}

    public function maybeSample(string $source, string $operation, string $correlationId, ProviderResponse $response): ?ProviderResponseSample
    {
        $config = $this->configFor($source);

        if (! ($config['enabled'] ?? false)) {
            return null;
        }

        $rate = (float) ($config['rate'] ?? 0.0);

        if ($rate <= 0.0 || random_int(1, 1_000_000) > (int) round($rate * 1_000_000)) {
            return null;
        }

        $maxItems = (int) ($config['max_items'] ?? 3);
        $retentionDays = (int) ($config['retention_days'] ?? 7);

        $sampledItems = array_map(
            fn (array $item): array => $this->redactor->redact($item),
            array_slice($response->items, 0, max(1, $maxItems)),
        );

        return ProviderResponseSample::query()->create([
            'source' => $source,
            'operation' => $operation,
            'correlation_id' => $correlationId,
            'payload' => [
                'result_count' => count($response->items),
                'sampled_items' => $sampledItems,
            ],
            'sampled_at' => CarbonImmutable::now(),
            'expires_at' => CarbonImmutable::now()->addDays(max(1, $retentionDays)),
        ]);
    }

    /** @return array<string, mixed> per-provider config merged over defaults */
    private function configFor(string $source): array
    {
        $defaults = (array) config('qds.ingestion.sampling.defaults', []);
        $perProvider = (array) config("qds.ingestion.sampling.providers.{$source}", []);

        return [...$defaults, ...$perProvider];
    }
}
