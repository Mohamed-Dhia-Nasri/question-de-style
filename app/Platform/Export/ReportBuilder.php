<?php

namespace App\Platform\Export;

use App\Modules\Monitoring\Models\EmvConfiguration;
use App\Platform\Enrichment\Support\EmvConfigurationStatus;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Builds the rollup-backed report documents SVC-Export renders
 * (REQ-M1-012). Reads ROLLUP-* (plus dimension name lookups) ONLY — never
 * raw facts, never OLTP aggregation (ADR-0010).
 *
 * Fidelity rules baked into every document (AC-M1-011/012):
 *  - every metric column names its ENUM-MetricTier;
 *  - the EMV disclosure block carries the active model + rate card, or the
 *    literal Unavailable state when no configuration is active;
 *  - deferred / unmeasured values render the literal "Unavailable" —
 *    estimated reach (no canonical method yet; CONFIRMED reach is
 *    DEF-003), posting frequency (no canonical formula), comment analysis
 *    (DEF-005), open-web listening (DEF-006);
 *  - no personal data beyond the public persona display name (DP-005);
 *    contact/audience fields never enter a default export.
 */
class ReportBuilder
{
    public const MONITORING_SUMMARY = 'monitoring-summary';

    /** @return list<string> report kinds this builder can produce */
    public static function reports(): array
    {
        return [self::MONITORING_SUMMARY];
    }

    public function build(string $report, ReportFilters $filters): ReportDocument
    {
        return match ($report) {
            self::MONITORING_SUMMARY => $this->monitoringSummary($filters),
            default => throw new InvalidArgumentException("Unknown report [{$report}]."),
        };
    }

    private function monitoringSummary(ReportFilters $filters): ReportDocument
    {
        return new ReportDocument(
            title: 'QDS — Module 1 Monitoring Report',
            generatedAt: now()->toIso8601String(),
            filters: $this->disclosedFilters($filters),
            disclosures: $this->disclosures(),
            sections: [
                $this->creatorSection($filters),
                $this->brandMentionSection($filters),
            ],
        );
    }

    /** @return array<string, string> */
    private function disclosedFilters(ReportFilters $filters): array
    {
        $brand = $filters->brandId === null
            ? 'All brands'
            : (string) DB::table('dim_brand')->where('brand_id', $filters->brandId)->value('name');

        $creator = $filters->creatorId === null
            ? 'All monitored creators'
            : (string) DB::table('dim_creator')->where('creator_id', $filters->creatorId)->value('display_name');

        return [
            'Grain' => $filters->grain,
            'From' => $filters->from?->toDateString() ?? 'Open start',
            'To' => $filters->to?->toDateString() ?? 'Open end',
            'Brand' => $brand,
            'Creator' => $creator,
        ];
    }

    /** @return list<string> */
    private function disclosures(): array
    {
        $lines = [
            'Metric tiers: PUBLIC = directly observed; DERIVED = deterministically computed from PUBLIC values; ESTIMATED = modelled, never a fact; CONFIRMED = authorized/manual input (DP-001).',
            'Engagement rate model: (likes + comments + shares + saves) / '.config('qds.enrichment.metrics.engagement_base').' — MET-EngagementRate, base disclosed per configuration.',
            'Estimated reach: Unavailable — no estimation method is canonically documented; CONFIRMED unique reach is deferred (DEF-003).',
            'Comment & audience-reaction analysis: Unavailable — deferred (DEF-005). Open-web listening beyond the roster: Unavailable — deferred (DEF-006).',
            'Share of voice: brand mentions / all brand-attributed mentions in the same period bucket (GL-ShareOfVoice, DERIVED).',
            $this->emvDisclosure(),
        ];

        return $lines;
    }

    private function emvDisclosure(): string
    {
        $config = EmvConfiguration::query()
            ->where('status', EmvConfigurationStatus::Active->value)
            ->first();

        if ($config === null) {
            return 'EMV model: Unavailable — no active EMV configuration (REQ-M1-011: EMV requires a user-activated, transparent rate card).';
        }

        return sprintf(
            'EMV model: "%s" (formula %s, rate card %s, currency %s); rates: %s. EMV is a modelled monetary ESTIMATE, never a fact (MET-EMV).',
            $config->name,
            $config->formula_version,
            $config->rate_card_version,
            $config->currency,
            json_encode($config->rates),
        );
    }

    /** @return array{title: string, columns: list<string>, rows: list<list<string|int|float|null>>} */
    private function creatorSection(ReportFilters $filters): array
    {
        $rows = DB::table('rollup_creator_by_period as r')
            ->join('dim_creator as c', 'c.creator_id', '=', 'r.creator_id')
            ->where('r.grain', $filters->grain)
            ->when($filters->from, fn ($q) => $q->where('r.bucket_start', '>=', $filters->from->toDateString()))
            ->when($filters->to, fn ($q) => $q->where('r.bucket_start', '<=', $filters->to->toDateString()))
            ->when($filters->creatorId !== null, fn ($q) => $q->where('r.creator_id', $filters->creatorId))
            ->orderBy('c.display_name')
            ->orderBy('r.bucket_start')
            ->limit(5000)
            ->get([
                'c.display_name', 'r.bucket_start', 'r.followers', 'r.follower_growth',
                'r.content_count', 'r.avg_views', 'r.engagement_rate', 'r.last_post_at',
            ]);

        return [
            'title' => 'Creator performance by period (ROLLUP-CreatorByPeriod)',
            'columns' => [
                'Creator', 'Period start', 'Followers [PUBLIC]', 'Follower growth [DERIVED]',
                'Content count [PUBLIC]', 'Avg views [DERIVED]', 'Engagement rate [DERIVED]',
                'Posting frequency [DERIVED]', 'Last post at',
            ],
            'rows' => $rows->map(fn (object $row): array => [
                $row->display_name,
                (string) $row->bucket_start,
                self::number($row->followers),
                self::number($row->follower_growth),
                self::number($row->content_count),
                self::number($row->avg_views, 2),
                self::number($row->engagement_rate, 4),
                null, // no canonical posting-frequency formula → Unavailable
                $row->last_post_at === null ? null : (string) $row->last_post_at,
            ])->all(),
        ];
    }

    /** @return array{title: string, columns: list<string>, rows: list<list<string|int|float|null>>} */
    private function brandMentionSection(ReportFilters $filters): array
    {
        $rows = DB::table('rollup_mention_by_brand as r')
            ->join('dim_brand as b', 'b.brand_id', '=', 'r.brand_id')
            ->where('r.grain', $filters->grain)
            ->when($filters->from, fn ($q) => $q->where('r.bucket_start', '>=', $filters->from->toDateString()))
            ->when($filters->to, fn ($q) => $q->where('r.bucket_start', '<=', $filters->to->toDateString()))
            ->when($filters->brandId !== null, fn ($q) => $q->where('r.brand_id', $filters->brandId))
            ->orderBy('b.name')
            ->orderBy('r.bucket_start')
            ->limit(5000)
            ->get([
                'b.name', 'r.bucket_start', 'r.mention_count', 'r.total_views',
                'r.total_estimated_reach', 'r.total_emv', 'r.share_of_voice',
            ]);

        return [
            'title' => 'Brand mentions by period (ROLLUP-MentionByBrand)',
            'columns' => [
                'Brand', 'Period start', 'Mentions [PUBLIC]', 'Total views [PUBLIC]',
                'Estimated reach [ESTIMATED]', 'EMV [ESTIMATED]', 'Share of voice [DERIVED]',
            ],
            'rows' => $rows->map(fn (object $row): array => [
                $row->name,
                (string) $row->bucket_start,
                self::number($row->mention_count),
                self::number($row->total_views),
                self::number($row->total_estimated_reach),
                self::number($row->total_emv, 2),
                self::number($row->share_of_voice, 4),
            ])->all(),
        ];
    }

    private static function number(mixed $value, int $decimals = 0): int|float|null
    {
        if ($value === null) {
            return null;
        }

        return $decimals === 0 ? (int) round((float) $value) : round((float) $value, $decimals);
    }
}
