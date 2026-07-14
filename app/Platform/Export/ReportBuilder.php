<?php

namespace App\Platform\Export;

use App\Modules\Monitoring\Models\EmvConfiguration;
use App\Platform\Enrichment\Support\EmvConfigurationStatus;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Builds the rollup-backed report documents SVC-Export renders
 * (REQ-M1-012 monitoring summary; REQ-M3-013/AC-M3-019 seeding results).
 * Reads ROLLUP-* (plus dimension name lookups) ONLY — never raw facts,
 * never OLTP aggregation (ADR-0010).
 *
 * Fidelity rules baked into every document (AC-M1-011/012):
 *  - every metric column names its ENUM-MetricTier;
 *  - the EMV disclosure block carries the active model + rate card, or the
 *    literal Unavailable state when no configuration is active;
 *  - deferred / unmeasured values render the literal "Unavailable" —
 *    estimated reach (ESTIMATED per ADR-0022; CONFIRMED unique reach
 *    remains DEF-003), posting frequency (no canonical formula), comment
 *    analysis (DEF-005), open-web listening (DEF-006);
 *  - no personal data beyond the public persona display name (DP-005);
 *    contact/audience fields never enter a default export.
 */
class ReportBuilder
{
    public const MONITORING_SUMMARY = 'monitoring-summary';

    /** Module 3 seeding results (REQ-M3-013 / AC-M3-019), Step-4 spec §2.5. */
    public const SEEDING_RESULTS = 'seeding-results';

    /**
     * Per-section row cap. A capped section discloses the truncation —
     * silent truncation would read as "covered everything" when it didn't.
     */
    public const ROW_CAP = 5000;

    /** @return list<string> report kinds this builder can produce */
    public static function reports(): array
    {
        return [self::MONITORING_SUMMARY, self::SEEDING_RESULTS];
    }

    /**
     * ADR-0019: rollup_* / dim_* rows carry tenant_id. Every read here
     * filters by the active tenant WHEN a context is set (exports run under
     * the requesting job's tenant — GenerateExportJob guarantees it); a null
     * context (platform-level tooling) reads unfiltered on purpose.
     */
    private function tenantId(): ?int
    {
        return app(TenantContext::class)->id();
    }

    public function build(string $report, ReportFilters $filters): ReportDocument
    {
        return match ($report) {
            self::MONITORING_SUMMARY => $this->monitoringSummary($filters),
            self::SEEDING_RESULTS => $this->seedingResults($filters),
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
        $tenantId = $this->tenantId();

        $brand = $filters->brandId === null
            ? 'All brands'
            : (string) DB::table('dim_brand')
                ->when($tenantId !== null, fn ($q) => $q->where('tenant_id', $tenantId))
                ->where('brand_id', $filters->brandId)->value('name');

        $creator = $filters->creatorId === null
            ? 'All monitored creators'
            : (string) DB::table('dim_creator')
                ->when($tenantId !== null, fn ($q) => $q->where('tenant_id', $tenantId))
                ->where('creator_id', $filters->creatorId)->value('display_name');

        $product = $filters->productId === null
            ? 'All products'
            : (string) DB::table('dim_product')
                ->when($tenantId !== null, fn ($q) => $q->where('tenant_id', $tenantId))
                ->where('product_id', $filters->productId)->value('name');

        return [
            'Grain' => $filters->grain,
            'From' => $filters->from?->toDateString() ?? 'Open start',
            'To' => $filters->to?->toDateString() ?? 'Open end',
            'Brand' => $brand,
            'Creator' => $creator,
            'Product' => $product,
            'Platform' => $filters->platform ?? 'All platforms',
            'Content type' => $filters->contentType ?? 'All content types',
            'Creator country' => $filters->country ?? 'All countries',
            'Creator city' => $filters->city ?? 'All cities',
        ];
    }

    /** @return list<string> */
    private function disclosures(): array
    {
        $lines = [
            'Metric tiers: PUBLIC = directly observed; DERIVED = deterministically computed from PUBLIC values; ESTIMATED = modelled, never a fact; CONFIRMED = authorized/manual input (DP-001).',
            'Engagement rate model: (likes + comments + shares + saves) / '.config('qds.enrichment.metrics.engagement_base').' — MET-EngagementRate, base disclosed per configuration.',
            'True unique reach: Unavailable — CONFIRMED unique reach/impressions requires authorized private analytics (DEF-003, ADR-0006). Estimated reach is shown where computed (ESTIMATED).',
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
            ->when($this->tenantId() !== null, fn ($q) => $q->where('r.tenant_id', $this->tenantId()))
            ->where('r.grain', $filters->grain)
            ->when($filters->from, fn ($q) => $q->where('r.bucket_start', '>=', $filters->from->toDateString()))
            ->when($filters->to, fn ($q) => $q->where('r.bucket_start', '<=', $filters->to->toDateString()))
            ->when($filters->creatorId !== null, fn ($q) => $q->where('r.creator_id', $filters->creatorId))
            ->orderBy('c.display_name')
            ->orderBy('r.bucket_start')
            ->limit(self::ROW_CAP)
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
            ->when($this->tenantId() !== null, fn ($q) => $q->where('r.tenant_id', $this->tenantId()))
            ->where('r.grain', $filters->grain)
            ->when($filters->from, fn ($q) => $q->where('r.bucket_start', '>=', $filters->from->toDateString()))
            ->when($filters->to, fn ($q) => $q->where('r.bucket_start', '<=', $filters->to->toDateString()))
            ->when($filters->brandId !== null, fn ($q) => $q->where('r.brand_id', $filters->brandId))
            ->orderBy('b.name')
            ->orderBy('r.bucket_start')
            ->limit(self::ROW_CAP)
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

    private function seedingResults(ReportFilters $filters): ReportDocument
    {
        // Section order mirrors how the dashboard reads: cross-influencer
        // product totals first, the slice breakdown when a slice filter is
        // active, then the per-shipment operational detail.
        $sections = [$this->seedingProductSection($filters)];

        if ($filters->sliceActive()) {
            $sections[] = $this->seedingSliceSection($filters);
        }

        $sections[] = $this->seedingShipmentSection($filters);

        $disclosures = $this->seedingDisclosures();

        if ($filters->sliceActive()) {
            $disclosures[] = 'Slice filters (platform / content type / country / city) narrow the product-totals and slice sections only; the per-shipment detail is slice-agnostic — shipments carry no content dimension.';
        }

        foreach ($sections as $section) {
            if (count($section['rows']) === self::ROW_CAP) {
                $disclosures[] = sprintf(
                    'Section "%s" reached the %s-row export cap and may be incomplete — narrow the filters for a complete export.',
                    $section['title'],
                    number_format(self::ROW_CAP),
                );
            }
        }

        return new ReportDocument(
            title: 'QDS — Module 3 Seeding Results Report',
            generatedAt: now()->toIso8601String(),
            filters: $this->disclosedFilters($filters),
            disclosures: $disclosures,
            sections: $sections,
        );
    }

    /**
     * Human period label per grain — multi-year exports stay readable
     * ("2025-Q3" instead of a bare bucket date). The ISO bucket date rides
     * along in its own column for sorting and machine use.
     */
    private static function periodLabel(string $grain, string $bucketStart): string
    {
        $date = Carbon::parse($bucketStart);

        return match ($grain) {
            'year' => $date->format('Y'),
            'quarter' => $date->format('Y').'-Q'.$date->quarter,
            'month' => $date->format('Y-m'),
            'week' => $date->isoFormat('GGGG-[W]WW'),
            default => $bucketStart,
        };
    }

    /** @return list<string> */
    private function seedingDisclosures(): array
    {
        return [
            'Metric tiers: PUBLIC = directly observed; DERIVED = deterministically computed from PUBLIC values; ESTIMATED = modelled, never a fact; CONFIRMED = authorized/manual input (DP-001).',
            'Engagement sum: likes + comments + shares + saves over the latest snapshot per shipment × content item; unobserved components never count as zero.',
            'CPE = agency-entered spend (CONFIRMED) / total engagement; CPM = spend / (total views ÷ 1000) — DERIVED at display time (AC-M3-015), never stored or summed; Unavailable without spend or with a NULL/zero divisor — never zero, never infinity.',
            'True unique reach: Unavailable — CONFIRMED unique reach/impressions requires authorized private analytics (DEF-003, ADR-0006). Estimated reach is shown where computed (ESTIMATED).',
            'Geography (country/city) is a CREATOR attribute — the posting creator\'s operator-assigned location (ADR-0018), never a property of a brand or product; creators without an assignment render Unavailable.',
            $this->emvDisclosure(),
        ];
    }

    /** @return array{title: string, columns: list<string>, rows: list<list<string|int|float|null>>} */
    private function seedingProductSection(ReportFilters $filters): array
    {
        // The cross-influencer product totals (ROLLUP-SeedingByProduct,
        // AC-M3-019). The brand filter resolves through DIM-Product — the
        // canonical view carries no brand column.
        $rows = DB::table('rollup_seeding_by_product as r')
            ->join('dim_product as p', 'p.product_id', '=', 'r.product_id')
            ->when($this->tenantId() !== null, fn ($q) => $q->where('r.tenant_id', $this->tenantId()))
            ->where('r.grain', $filters->grain)
            ->when($filters->from, fn ($q) => $q->where('r.bucket_start', '>=', $filters->from->toDateString()))
            ->when($filters->to, fn ($q) => $q->where('r.bucket_start', '<=', $filters->to->toDateString()))
            ->when($filters->brandId !== null, fn ($q) => $q->where('p.brand_id', $filters->brandId))
            ->when($filters->productId !== null, fn ($q) => $q->where('r.product_id', $filters->productId))
            ->orderBy('p.name')
            ->orderBy('r.bucket_start')
            ->limit(self::ROW_CAP)
            ->get([
                'p.name', 'r.bucket_start', 'r.shipments', 'r.posted_count', 'r.post_rate',
                'r.creators_reached', 'r.content_count', 'r.total_views',
                'r.total_estimated_reach', 'r.total_engagement', 'r.total_emv',
            ]);

        return [
            'title' => 'Seeding results by product (ROLLUP-SeedingByProduct)',
            'columns' => [
                'Product', 'Period', 'Period start', 'Shipments [CONFIRMED]', 'Posted [CONFIRMED]',
                'Post rate [DERIVED]', 'Creators reached [CONFIRMED]', 'Content count [PUBLIC]',
                'Total views [PUBLIC]', 'Estimated reach [ESTIMATED]', 'Engagement [DERIVED]',
                'EMV [ESTIMATED]',
            ],
            'rows' => $rows->map(fn (object $row): array => [
                $row->name,
                self::periodLabel($filters->grain, (string) $row->bucket_start),
                (string) $row->bucket_start,
                self::number($row->shipments),
                self::number($row->posted_count),
                self::number($row->post_rate, 4),
                self::number($row->creators_reached),
                self::number($row->content_count),
                self::number($row->total_views),
                self::number($row->total_estimated_reach),
                self::number($row->total_engagement),
                self::number($row->total_emv, 2),
            ])->all(),
        ];
    }

    /** @return array{title: string, columns: list<string>, rows: list<list<string|int|float|null>>} */
    private function seedingSliceSection(ReportFilters $filters): array
    {
        // The dashboard's slice mode (Step-4 D5 + ADR-0018 geography):
        // content-side measures re-grouped by platform / content type /
        // country / city. Shipment-level counts stay on the unsliced
        // product section — shipments carry no content dimension.
        $rows = DB::table('rollup_seeding_by_product_slice as r')
            ->join('dim_product as p', 'p.product_id', '=', 'r.product_id')
            ->when($this->tenantId() !== null, fn ($q) => $q->where('r.tenant_id', $this->tenantId()))
            ->where('r.grain', $filters->grain)
            ->when($filters->from, fn ($q) => $q->where('r.bucket_start', '>=', $filters->from->toDateString()))
            ->when($filters->to, fn ($q) => $q->where('r.bucket_start', '<=', $filters->to->toDateString()))
            ->when($filters->brandId !== null, fn ($q) => $q->where('p.brand_id', $filters->brandId))
            ->when($filters->productId !== null, fn ($q) => $q->where('r.product_id', $filters->productId))
            ->when($filters->platform !== null, fn ($q) => $q->where('r.platform', $filters->platform))
            ->when($filters->contentType !== null, fn ($q) => $q->where('r.content_type', $filters->contentType))
            ->when($filters->country !== null, fn ($q) => $q->where('r.country', $filters->country))
            ->when($filters->city !== null, fn ($q) => $q->where('r.city', $filters->city))
            ->orderBy('p.name')
            ->orderBy('r.bucket_start')
            ->orderBy('r.platform')
            ->limit(self::ROW_CAP)
            ->get([
                'p.name', 'r.bucket_start', 'r.platform', 'r.content_type', 'r.country', 'r.city',
                'r.creators_reached', 'r.content_count', 'r.total_views',
                'r.total_engagement', 'r.total_estimated_reach', 'r.total_emv',
            ]);

        return [
            'title' => 'Slice breakdown (rollup_seeding_by_product_slice)',
            'columns' => [
                'Product', 'Period', 'Period start', 'Platform', 'Content type', 'Creator country', 'Creator city',
                'Creators posted [CONFIRMED]', 'Content count [PUBLIC]', 'Total views [PUBLIC]',
                'Engagement [DERIVED]', 'Estimated reach [ESTIMATED]', 'EMV [ESTIMATED]',
            ],
            'rows' => $rows->map(fn (object $row): array => [
                $row->name,
                self::periodLabel($filters->grain, (string) $row->bucket_start),
                (string) $row->bucket_start,
                $row->platform,
                $row->content_type,
                $row->country,
                $row->city,
                self::number($row->creators_reached),
                self::number($row->content_count),
                self::number($row->total_views),
                self::number($row->total_engagement),
                self::number($row->total_estimated_reach),
                self::number($row->total_emv, 2),
            ])->all(),
        ];
    }

    /** @return array{title: string, columns: list<string>, rows: list<list<string|int|float|null>>} */
    private function seedingShipmentSection(ReportFilters $filters): array
    {
        // Per-shipment detail (ROLLUP-SeedingByShipment, AC-M3-018): what
        // was sent, did they post, when, how did it perform. The rollup is
        // per shipment — the grain filter does not apply here; the creator
        // filter does (the product section has no creator dimension).
        $rows = DB::table('rollup_seeding_by_shipment as r')
            ->join('dim_creator as c', 'c.creator_id', '=', 'r.creator_id')
            ->join('dim_product as p', 'p.product_id', '=', 'r.product_id')
            // Geography is a CREATOR attribute (ADR-0018) — it rides along
            // per recipient so a per-creator export self-describes.
            ->leftJoin('dim_geo as g', 'g.creator_id', '=', 'r.creator_id')
            ->when($this->tenantId() !== null, fn ($q) => $q->where('r.tenant_id', $this->tenantId()))
            ->when($filters->from, fn ($q) => $q->where('r.shipped_date', '>=', $filters->from->toDateString()))
            ->when($filters->to, fn ($q) => $q->where('r.shipped_date', '<=', $filters->to->toDateString()))
            ->when($filters->brandId !== null, fn ($q) => $q->where('r.brand_id', $filters->brandId))
            ->when($filters->creatorId !== null, fn ($q) => $q->where('r.creator_id', $filters->creatorId))
            ->when($filters->productId !== null, fn ($q) => $q->where('r.product_id', $filters->productId))
            ->orderBy('r.shipped_date')
            ->orderBy('r.shipment_id')
            ->limit(self::ROW_CAP)
            ->get([
                'c.display_name', 'g.country_code', 'g.city', 'p.name', 'r.shipped_date', 'r.posted', 'r.days_to_post',
                'r.content_count', 'r.views', 'r.likes', 'r.comments',
                'r.estimated_reach', 'r.emv',
            ]);

        return [
            'title' => 'Seeding results by shipment (ROLLUP-SeedingByShipment)',
            'columns' => [
                'Creator', 'Creator country', 'Creator city', 'Product', 'Shipped date', 'Posted [CONFIRMED]',
                'Days to post [DERIVED]', 'Content count [PUBLIC]', 'Views [PUBLIC]',
                'Likes [PUBLIC]', 'Comments [PUBLIC]', 'Estimated reach [ESTIMATED]',
                'EMV [ESTIMATED]',
            ],
            'rows' => $rows->map(fn (object $row): array => [
                $row->display_name,
                $row->country_code,
                $row->city,
                $row->name,
                (string) $row->shipped_date,
                self::number($row->posted),
                self::number($row->days_to_post, 1),
                self::number($row->content_count),
                self::number($row->views),
                self::number($row->likes),
                self::number($row->comments),
                self::number($row->estimated_reach),
                self::number($row->emv, 2),
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
