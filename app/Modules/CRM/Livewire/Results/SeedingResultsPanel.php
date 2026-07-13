<?php

namespace App\Modules\CRM\Livewire\Results;

use App\Modules\CRM\Models\Creator;
use App\Modules\CRM\Models\Product;
use App\Modules\CRM\Models\SeedingCampaign;
use App\Platform\Analytics\RollupReader;
use App\Platform\Enrichment\Emv\EmvCalculator;
use App\Shared\Enums\MetricTier;
use App\Shared\ValueObjects\MetricValue;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Component;

/**
 * Seeding-run results panel (REQ-M3-009, AC-M3-014/015/018) — the
 * read-only results block on the seeding detail page. Every aggregate
 * comes from RollupReader ONLY (ADR-0010): run totals from
 * ROLLUP-SeedingByCreatorCampaign, per-shipment rows from
 * ROLLUP-SeedingByShipment (AC-M3-018: what was sent, did they post,
 * when, how did it perform). Per-creator rows are a display-time
 * regrouping of the shipment rollup rows (PUBLIC components are additive;
 * unobserved values stay NULL — never coalesced to zero, DP-001).
 *
 * CPE/CPM are DERIVED at display time from the run's agency-entered
 * CONFIRMED spend (D1/D4) — "unavailable" on a missing spend or NULL/zero
 * divisor, never zero, never infinity. Estimated reach and CONFIRMED
 * unique reach render "unavailable" citing DEF-003; EMV is always
 * ESTIMATED with the active model disclosed.
 */
class SeedingResultsPanel extends Component
{
    public SeedingCampaign $seedingCampaign;

    public function mount(SeedingCampaign $seedingCampaign): void
    {
        $this->authorize('view', $seedingCampaign);

        $this->seedingCampaign = $seedingCampaign;
    }

    /**
     * A display-time cost ratio (AC-M3-015): CONFIRMED spend ÷ a PUBLIC/
     * DERIVED aggregate ⇒ DERIVED by the weakest-input rule
     * (00-data-model :587). NULL when spend is missing or the divisor is
     * NULL/zero (D4) — the blade renders the unavailable reason instead.
     */
    private function costMetric(null|string|int|float $divisor, string $metric): ?MetricValue
    {
        $spend = $this->seedingCampaign->spend;

        if ($spend === null || $divisor === null || (float) $divisor <= 0.0) {
            return null;
        }

        return new MetricValue($spend->amount / (float) $divisor, MetricTier::Derived, $metric);
    }

    /** Why a NULL cost ratio is unavailable — never rendered as zero or ∞. */
    private function costReason(string $noDivisorReason): string
    {
        return $this->seedingCampaign->spend === null
            ? 'Requires agency-entered spend (AC-M3-015) — no spend is recorded for this seeding run.'
            : $noDivisorReason;
    }

    /**
     * Engagement components summed per shipment rollup row — NULL when no
     * component was ever observed (DP-001: a story-only post without a
     * breakdown must not fabricate a zero).
     */
    private static function engagement(object $row): ?float
    {
        $observed = array_filter(
            [$row->likes, $row->comments, $row->shares, $row->saves],
            fn ($value) => $value !== null,
        );

        return $observed === [] ? null : array_sum(array_map(fn ($value) => (float) $value, $observed));
    }

    /**
     * Sum a rollup column across rows, keeping NULL when nothing was observed.
     *
     * @param  Collection<int, \stdClass>  $rows
     */
    private static function nullableSum(Collection $rows, callable $value): ?float
    {
        $observed = $rows->map($value)->reject(fn ($v) => $v === null);

        return $observed->isEmpty() ? null : (float) $observed->sum();
    }

    public function render(RollupReader $rollups, EmvCalculator $emv): View
    {
        $totals = $rollups->seedingCampaignTotals($this->seedingCampaign->id);
        $shipmentRows = $rollups->seedingByShipment($this->seedingCampaign->id);

        // Per-creator rows: regrouped from the shipment rollup rows (still
        // rollup-only; no per-creator reader exists for a single run).
        $creatorRows = $shipmentRows
            ->groupBy('creator_id')
            ->map(fn (Collection $rows) => (object) [
                'creator_id' => (int) $rows->first()->creator_id,
                'shipments' => $rows->count(),
                'posted' => (int) $rows->sum('posted'),
                'content_count' => (int) $rows->sum('content_count'),
                'views' => self::nullableSum($rows, fn (object $row) => $row->views),
                'engagement' => self::nullableSum($rows, fn (object $row) => self::engagement($row)),
                'emv' => self::nullableSum($rows, fn (object $row) => $row->emv),
            ])
            ->values();

        // Entity names come from OLTP (never numbers — ADR-0010).
        $creatorNames = Creator::query()
            ->whereIn('id', $shipmentRows->pluck('creator_id')->unique())
            ->pluck('display_name', 'id');
        $productNames = Product::query()
            ->whereIn('id', $shipmentRows->pluck('product_id')->unique()->filter())
            ->pluck('name', 'id');

        return view('livewire.crm.seeding-results', [
            'totals' => $totals,
            'shipmentRows' => $shipmentRows,
            'creatorRows' => $creatorRows,
            'creatorNames' => $creatorNames,
            'productNames' => $productNames,
            'shipmentEngagements' => $shipmentRows->mapWithKeys(
                fn (object $row) => [(int) $row->shipment_id => self::engagement($row)],
            ),
            'cpe' => $this->costMetric($totals->total_engagement, 'cpe'),
            'cpeReason' => $this->costReason('No engagement observed yet — a zero divisor never renders as zero cost or infinity.'),
            'cpm' => $this->costMetric($totals->total_views === null ? null : (float) $totals->total_views / 1000, 'cpm'),
            'cpmReason' => $this->costReason('No observed views yet — a zero divisor never renders as zero cost or infinity.'),
            // Deep-review M4: disclose the models that PRODUCED the figures
            // (latest emv_results), never the merely-active configuration.
            'emvConfigurations' => $emv->producingConfigurations(),
            'rollupsRefreshedAt' => $rollups->lastRefreshedAt(),
        ]);
    }
}
