<?php

namespace App\Modules\CRM\Livewire\Results;

use App\Modules\CRM\Models\Campaign;
use App\Platform\Analytics\RollupReader;
use App\Platform\Enrichment\Emv\EmvCalculator;
use App\Shared\Enums\MetricTier;
use App\Shared\ValueObjects\MetricValue;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * Campaign results panel (REQ-M3-009, AC-M3-014/015) — the read-only
 * results block on the campaign detail page. Every aggregate comes from
 * RollupReader ONLY (ADR-0010): mention-side totals from
 * rollup_mention_by_campaign (Step-4 D3), per child seeding run from
 * ROLLUP-SeedingByCreatorCampaign. CPE/CPM are DERIVED at display time
 * from the agency-entered CONFIRMED spend (D1/D4) — never stored, never
 * summed, and "unavailable" (never zero, never infinity) on a missing
 * spend or a NULL/zero divisor. Estimated reach renders ESTIMATED per
 * ADR-0022 when an active reach configuration exists (else
 * "unavailable"); CONFIRMED unique reach still renders "unavailable"
 * citing DEF-003; the EMV figure is always ESTIMATED with the active
 * model disclosed (AC-M1-011 convention).
 */
class CampaignResultsPanel extends Component
{
    public Campaign $campaign;

    public function mount(Campaign $campaign): void
    {
        $this->authorize('view', $campaign);

        $this->campaign = $campaign;
    }

    /**
     * A display-time cost ratio (AC-M3-015): CONFIRMED spend ÷ a PUBLIC/
     * DERIVED aggregate ⇒ DERIVED by the weakest-input rule
     * (00-data-model :587). NULL when spend is missing or the divisor is
     * NULL/zero (D4) — the blade renders the unavailable reason instead.
     */
    private function costMetric(null|string|int|float $divisor, string $metric): ?MetricValue
    {
        $spend = $this->campaign->spend;

        if ($spend === null || $divisor === null || (float) $divisor <= 0.0) {
            return null;
        }

        return new MetricValue($spend->amount / (float) $divisor, MetricTier::Derived, $metric);
    }

    /** Why a NULL cost ratio is unavailable — never rendered as zero or ∞. */
    private function costReason(null|string|int|float $divisor, string $noDivisorReason): string
    {
        return $this->campaign->spend === null
            ? 'No spend entered for this campaign yet — add it when editing the campaign.'
            : $noDivisorReason;
    }

    public function render(RollupReader $rollups, EmvCalculator $emv): View
    {
        $totals = $rollups->campaignMentionTotals($this->campaign->id);

        // One totals row per child seeding run — names come from the OLTP
        // relation, every number from the reader (ADR-0010).
        $runTotals = $this->campaign->seedingCampaigns
            ->mapWithKeys(fn ($run) => [$run->id => $rollups->seedingCampaignTotals($run->id)]);

        return view('livewire.crm.campaign-results', [
            'totals' => $totals,
            'runTotals' => $runTotals,
            'cpe' => $this->costMetric($totals->total_engagement, 'cpe'),
            'cpeReason' => $this->costReason($totals->total_engagement, 'No engagement observed yet — a zero divisor never renders as zero cost or infinity.'),
            'cpm' => $this->costMetric($totals->total_views === null ? null : (float) $totals->total_views / 1000, 'cpm'),
            'cpmReason' => $this->costReason($totals->total_views, 'No observed views yet — a zero divisor never renders as zero cost or infinity.'),
            // Deep-review M4: disclose the models that PRODUCED the figures
            // (latest emv_results), never the merely-active configuration.
            'emvConfigurations' => $emv->producingConfigurations(),
            'rollupsRefreshedAt' => $rollups->lastRefreshedAt(),
        ]);
    }
}
