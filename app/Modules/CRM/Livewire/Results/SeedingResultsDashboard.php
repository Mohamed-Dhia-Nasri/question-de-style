<?php

namespace App\Modules\CRM\Livewire\Results;

use App\Models\User;
use App\Modules\CRM\Models\Brand;
use App\Modules\CRM\Models\Product;
use App\Modules\CRM\Models\SeedingCampaign;
use App\Platform\Analytics\RollupReader;
use App\Platform\Enrichment\Emv\EmvCalculator;
use App\Platform\Export\ExportManager;
use App\Platform\Export\Models\ExportJob;
use App\Platform\Export\ReportBuilder;
use App\Shared\Enums\ContentType;
use App\Shared\Enums\Country;
use App\Shared\Enums\ExportFormat;
use App\Shared\Enums\Platform;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Product aggregation dashboard (REQ-M3-013, AC-M3-019) — the "product
 * seeded to N influencers → one cross-influencer total" view at
 * /crm/results. Every number comes from RollupReader ONLY (ADR-0010):
 * ROLLUP-SeedingByProduct unsliced, rollup_seeding_by_product_slice
 * (Step-4 D5) when a platform / content-type / country slice is active.
 *
 * Slices imply content — shipments carry no platform/content-type/country
 * dimension — so an active slice renders the content-side measures and
 * the shipment-level columns fall back to the unsliced view (caption in
 * the blade). All filters validate and execute server-side
 * (MonitoringOverview idiom); estimates stay tier-labelled and absent
 * values render "unavailable", never zero (DP-001). Country slices stay
 * unavailable until Module 2 ships geo attribution.
 */
class SeedingResultsDashboard extends Component
{
    #[Url(except: 'month')]
    public string $grain = 'month';

    #[Url(except: '')]
    public string $from = '';

    #[Url(except: '')]
    public string $to = '';

    #[Url(except: 0)]
    public int $brandId = 0;

    #[Url(except: 0)]
    public int $productId = 0;

    #[Url(except: '')]
    public string $platform = '';

    #[Url(except: '')]
    public string $contentType = '';

    #[Url(except: '')]
    public string $country = '';

    #[Url(except: '')]
    public string $city = '';

    /** Export format for the "Export current view" action. */
    public string $exportFormat = 'CSV';

    public function mount(): void
    {
        // Results are reads over seeding rollups — crm.view suffices.
        $this->authorize('viewAny', SeedingCampaign::class);
    }

    private function grainFilter(): string
    {
        return in_array($this->grain, RollupReader::GRAINS, true) ? $this->grain : 'month';
    }

    private function dateFilter(string $value): ?Carbon
    {
        try {
            return $value === '' ? null : Carbon::createFromFormat('Y-m-d', $value)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }

    private function brandFilter(): ?int
    {
        return $this->brandId > 0 && Brand::query()->whereKey($this->brandId)->exists()
            ? $this->brandId
            : null;
    }

    private function productFilter(): ?int
    {
        return $this->productId > 0 && Product::query()->whereKey($this->productId)->exists()
            ? $this->productId
            : null;
    }

    private function platformFilter(): ?string
    {
        return Platform::tryFrom($this->platform)?->value;
    }

    private function contentTypeFilter(): ?string
    {
        return ContentType::tryFrom($this->contentType)?->value;
    }

    /** Operating countries only (DACH + France); anything else is ignored, never passed through. */
    private function countryFilter(): ?string
    {
        return Country::tryFrom(strtoupper(trim($this->country)))?->value;
    }

    /**
     * Only cities DIM-Geo actually knows are ever passed through — an
     * arbitrary URL value cannot reach the query (MonitoringOverview idiom).
     *
     * @param  Collection<int, string>  $cities
     */
    private function cityFilter(Collection $cities): ?string
    {
        $city = trim($this->city);

        return $city !== '' && $cities->contains($city) ? $city : null;
    }

    /**
     * Queue a seeding-results export carrying EXACTLY the filters this view
     * is showing (REQ-M1-012 parity: exports mirror their dashboard). The
     * artifact is produced asynchronously and picked up on the Exports page.
     */
    public function export(ExportManager $exports): void
    {
        $this->authorize('create', ExportJob::class);

        $format = ExportFormat::tryFrom($this->exportFormat) ?? ExportFormat::Csv;

        // City options are validated against DIM-Geo inside the filter set
        // itself; everything else re-validates the same way the view does.
        $filters = array_filter([
            'grain' => $this->grainFilter(),
            'from' => $this->dateFilter($this->from)?->toDateString(),
            'to' => $this->dateFilter($this->to)?->toDateString(),
            'brand_id' => $this->brandFilter(),
            'product_id' => $this->productFilter(),
            'platform' => $this->platformFilter(),
            'content_type' => $this->contentTypeFilter(),
            'country' => $this->countryFilter(),
            'city' => trim($this->city) !== '' ? trim($this->city) : null,
        ], static fn ($value) => $value !== null);

        try {
            $exports->request($this->user(), ReportBuilder::SEEDING_RESULTS, $format, $filters);
        } catch (ValidationException $e) {
            $this->dispatch('notify', type: 'error', message: collect($e->errors())->flatten()->first() ?? 'Export request rejected.');

            return;
        }

        $this->dispatch('notify', type: 'success', message: 'Export queued — download it from the Exports page once it finishes.');
    }

    private function user(): User
    {
        /** @var User */
        return Auth::user();
    }

    public function render(RollupReader $rollups, EmvCalculator $emv): View
    {
        $grain = $this->grainFilter();
        $from = $this->dateFilter($this->from);
        $to = $this->dateFilter($this->to);
        $brandId = $this->brandFilter();
        $productId = $this->productFilter();
        $platform = $this->platformFilter();
        $contentType = $this->contentTypeFilter();
        $country = $this->countryFilter();

        // City options mirror what the slices can actually match (DIM-Geo,
        // refreshed with the rollups) — options are labels, never numbers.
        // Scoped to the active tenant so the dropdown never enumerates
        // cities that exist only in another tenant's operator-assigned geo.
        $tenantId = app(TenantContext::class)->id();
        $cities = DB::table('dim_geo')
            ->when($tenantId !== null, fn ($q) => $q->where('tenant_id', $tenantId))
            ->whereNotNull('city')
            ->distinct()
            ->orderBy('city')
            ->pluck('city');
        $city = $this->cityFilter($cities);

        $sliceActive = $platform !== null || $contentType !== null || $country !== null || $city !== null;

        $rows = $sliceActive
            ? $rollups->seedingProductSlices($grain, $from, $to, $brandId, $productId, $platform, $contentType, $country, $city)
            : $rollups->seedingByProduct($grain, $from, $to, $brandId, $productId);

        // Product names come from OLTP (never numbers — ADR-0010).
        $productNames = Product::query()
            ->whereIn('id', $rows->pluck('product_id')->unique())
            ->pluck('name', 'id');

        return view('livewire.crm.seeding-results-dashboard', [
            'rows' => $rows,
            'sliceActive' => $sliceActive,
            'productNames' => $productNames,
            'grains' => RollupReader::GRAINS,
            'brands' => Brand::query()->orderBy('name')->get(['id', 'name']),
            // Product options follow the selected brand (same coherence rule
            // as the seeding form); the server-side filter re-validates anyway.
            'products' => Product::query()
                ->when($this->brandFilter() !== null, fn ($q) => $q->where('brand_id', $this->brandFilter()))
                ->orderBy('name')
                ->get(['id', 'name']),
            'platforms' => Platform::cases(),
            'contentTypes' => ContentType::cases(),
            'countries' => Country::cases(),
            'cities' => $cities,
            // Deep-review M4: disclose the models that PRODUCED the figures
            // (latest emv_results), never the merely-active configuration.
            'emvConfigurations' => $emv->producingConfigurations(),
            'rollupsRefreshedAt' => $rollups->lastRefreshedAt(),
        ]);
    }
}
