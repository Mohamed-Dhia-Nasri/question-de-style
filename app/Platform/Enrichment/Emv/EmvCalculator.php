<?php

namespace App\Platform\Enrichment\Emv;

use App\Modules\Monitoring\Models\ContentItem;
use App\Modules\Monitoring\Models\EmvConfiguration;
use App\Modules\Monitoring\Models\EmvResult;
use App\Platform\Enrichment\Support\EmvConfigurationStatus;
use App\Shared\Enums\MetricTier;
use App\Shared\Tenancy\TenantContext;
use App\Shared\ValueObjects\MetricValue;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * EMV calculation (REQ-M1-011, MET-EMV): Σ (metric_i × rate_i) over one
 * ContentItem's observed PUBLIC metrics, using the single ACTIVE
 * configuration's rate card.
 *
 * - No active valid configuration → NULL (EMV is UNAVAILABLE, never zero).
 * - A configured metric the content does not report contributes NOTHING
 *   (missing is never zero) and is disclosed as unavailable in `inputs`.
 * - No observed input at all → NULL (no fabricated result row).
 * - Every result row snapshots formula version, rate-card version,
 *   currency, inputs with their tiers, rates applied, assumptions, and the
 *   calculation timestamp — full AC-M1-011 disclosure and reproducibility.
 * - The value is a MetricValue at tier ESTIMATED (a modeled monetary
 *   estimate is never a fact — DP-001).
 *
 * Rate resolution per metric, most specific first (disclosed in inputs):
 * platforms[platform] → content_types[contentType] → default.
 */
class EmvCalculator
{
    public function activeConfiguration(): ?EmvConfiguration
    {
        // ADR-0019: "ACTIVE" is a PER-TENANT notion (one active rate card
        // per tenant). This lookup deliberately relies on the model's
        // TenantScope: callers must run under the content item's tenant
        // context (EnrichContentItemJob wraps the pipeline in runAs), so the
        // query resolves that tenant's active configuration — never another
        // tenant's rate card.
        return EmvConfiguration::query()
            ->where('status', EmvConfigurationStatus::Active)
            ->where('effective_from', '<=', CarbonImmutable::now()->toDateString())
            ->first();
    }

    /**
     * The configurations that PRODUCED the currently-reported EMV figures:
     * the distinct models behind the latest emv_results row per content
     * item — exactly the population the analytics fact loaders stamp EMV
     * from. Disclosure surfaces must cite these, not the merely-active
     * configuration (deep-review finding M4): activating a new rate card
     * never re-stamps existing results, so "active" can diverge from what
     * the on-screen money figure was computed with (GL-EMV / AC-M1-011 —
     * "every report must show the model + rates used").
     *
     * @return Collection<int, EmvConfiguration>
     */
    public function producingConfigurations(): Collection
    {
        // The raw emv_results subquery bypasses TenantScope, so it is
        // tenant-scoped here (defense in depth) rather than trusting the
        // outer EmvConfiguration::whereIn to launder foreign ids. Guarded so
        // platform context (no bound tenant) reads unfiltered, matching the
        // rest of the analytics/enrichment reads.
        $tenantId = app(TenantContext::class)->id();

        $ids = DB::query()
            ->fromSub(
                DB::table('emv_results')
                    ->when($tenantId !== null, fn ($q) => $q->where('tenant_id', $tenantId))
                    ->selectRaw(<<<'SQL'
                    emv_configuration_id,
                    row_number() OVER (
                        PARTITION BY content_item_id
                        ORDER BY calculated_at DESC, id DESC
                    ) AS rn
                SQL),
                'latest'
            )
            ->where('rn', 1)
            ->distinct()
            ->pluck('emv_configuration_id');

        return EmvConfiguration::query()
            ->whereIn('id', $ids)
            ->orderBy('id')
            ->get();
    }

    public function calculate(ContentItem $content): ?EmvResult
    {
        $configuration = $this->activeConfiguration();

        if ($configuration === null) {
            return null;
        }

        /** @var list<string> $metrics */
        $metrics = $configuration->formula['metrics'] ?? [];

        $inputs = [];
        $total = 0.0;
        $observed = 0;

        foreach ($metrics as $metric) {
            $value = $this->publicMetric($content, $metric);
            [$rate, $rateSource] = $this->resolveRate($configuration, $content, $metric);

            if ($value === null) {
                $inputs[] = [
                    'metric' => $metric,
                    'amount' => null,
                    'tier' => null,
                    'rate' => $rate,
                    'rate_source' => $rateSource,
                    'included' => false,
                    'note' => 'unavailable — metric not observed for this content (missing is never zero)',
                ];

                continue;
            }

            $total += $value->amount * $rate;
            $observed++;

            $inputs[] = [
                'metric' => $metric,
                'amount' => $value->amount,
                'tier' => $value->tier->value,
                'rate' => $rate,
                'rate_source' => $rateSource,
                'included' => true,
            ];
        }

        if ($observed === 0) {
            return null;
        }

        return EmvResult::query()->create([
            'content_item_id' => $content->id,
            'emv_configuration_id' => $configuration->id,
            'formula_version' => $configuration->formula_version,
            'rate_card_version' => $configuration->rate_card_version,
            'currency' => $configuration->currency,
            'value' => new MetricValue($total, MetricTier::Estimated, 'emv'),
            'inputs' => $inputs,
            'assumptions' => $configuration->assumptions,
            'calculated_at' => CarbonImmutable::now(),
        ]);
    }

    private function publicMetric(ContentItem $content, string $metric): ?MetricValue
    {
        foreach ($content->public_metrics ?? [] as $value) {
            if ($value->metric === $metric) {
                return $value;
            }
        }

        return null;
    }

    /** @return array{0: float, 1: string} [rate, disclosure of where it came from] */
    private function resolveRate(EmvConfiguration $configuration, ContentItem $content, string $metric): array
    {
        $rates = $configuration->rates;

        $platformRate = $rates['platforms'][$content->platform->value][$metric] ?? null;

        if (is_numeric($platformRate)) {
            return [(float) $platformRate, 'platforms.'.$content->platform->value];
        }

        $typeRate = $rates['content_types'][$content->content_type->value][$metric] ?? null;

        if (is_numeric($typeRate)) {
            return [(float) $typeRate, 'content_types.'.$content->content_type->value];
        }

        return [(float) ($rates['default'][$metric] ?? 0.0), 'default'];
    }
}
