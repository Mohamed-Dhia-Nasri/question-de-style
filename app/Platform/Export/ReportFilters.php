<?php

namespace App\Platform\Export;

use App\Modules\CRM\Models\Brand;
use App\Modules\CRM\Models\Creator;
use App\Modules\CRM\Models\Product;
use App\Platform\Analytics\RollupReader;
use App\Shared\Enums\ContentType;
use App\Shared\Enums\Country;
use App\Shared\Enums\Platform;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The validated, server-side reporting filter set shared by dashboards and
 * SVC-Export (REQ-M1-012: exports use the SAME validated filters as the
 * dashboards they mirror).
 *
 * Grain/period/brand/creator ride the canonical rollup grains; product and
 * the slice dimensions (platform, content type, country, city) ride
 * ROLLUP-SeedingByProduct and its slice companion (Step-4 D5 + ADR-0018
 * geography), mirroring the /crm/results dashboard exactly. Every value
 * validates against the same closed sets the dashboard uses — an unknown
 * key or out-of-set value is rejected, never silently ignored.
 */
final readonly class ReportFilters
{
    private function __construct(
        public string $grain,
        public ?Carbon $from,
        public ?Carbon $to,
        public ?int $brandId,
        public ?int $creatorId,
        public ?int $productId,
        public ?string $platform,
        public ?string $contentType,
        public ?string $country,
        public ?string $city,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     */
    public static function validate(array $input): self
    {
        $known = ['grain', 'from', 'to', 'brand_id', 'creator_id', 'product_id', 'platform', 'content_type', 'country', 'city'];

        foreach (array_keys($input) as $key) {
            if (! in_array($key, $known, true)) {
                throw ValidationException::withMessages([
                    $key => "Filter [{$key}] is not supported by the approved rollups.",
                ]);
            }
        }

        $grain = (string) ($input['grain'] ?? 'month');

        if (! in_array($grain, RollupReader::GRAINS, true)) {
            throw ValidationException::withMessages(['grain' => 'Unknown reporting grain.']);
        }

        $from = self::date($input['from'] ?? null, 'from');
        $to = self::date($input['to'] ?? null, 'to');

        if ($from !== null && $to !== null && $from->gt($to)) {
            throw ValidationException::withMessages(['from' => 'The date range start is after its end.']);
        }

        $brandId = self::id($input['brand_id'] ?? null);
        $creatorId = self::id($input['creator_id'] ?? null);
        $productId = self::id($input['product_id'] ?? null);

        if ($brandId !== null && ! Brand::query()->whereKey($brandId)->exists()) {
            throw ValidationException::withMessages(['brand_id' => 'Unknown brand.']);
        }

        if ($creatorId !== null && ! Creator::query()->whereKey($creatorId)->exists()) {
            throw ValidationException::withMessages(['creator_id' => 'Unknown creator.']);
        }

        if ($productId !== null && ! Product::query()->whereKey($productId)->exists()) {
            throw ValidationException::withMessages(['product_id' => 'Unknown product.']);
        }

        $platform = self::closedSet($input['platform'] ?? null, 'platform', array_column(Platform::cases(), 'value'));
        $contentType = self::closedSet($input['content_type'] ?? null, 'content_type', array_column(ContentType::cases(), 'value'));
        $country = self::closedSet(
            is_string($input['country'] ?? null) ? strtoupper(trim((string) $input['country'])) : ($input['country'] ?? null),
            'country',
            Country::values(),
        );

        // Only cities DIM-Geo actually knows are ever accepted — the same
        // rule the dashboard applies (an arbitrary value never reaches SQL).
        // The existence check is tenant-scoped so it is not an oracle for
        // cities that exist only in another tenant's operator-assigned geo.
        $city = null;
        if (($input['city'] ?? '') !== '' && $input['city'] !== null) {
            $city = trim((string) $input['city']);
            $tenantId = app(TenantContext::class)->id();

            if (! DB::table('dim_geo')
                ->when($tenantId !== null, fn ($q) => $q->where('tenant_id', $tenantId))
                ->where('city', $city)->exists()) {
                throw ValidationException::withMessages(['city' => 'Unknown city — geography is operator-assigned (ADR-0018).']);
            }
        }

        return new self($grain, $from, $to, $brandId, $creatorId, $productId, $platform, $contentType, $country, $city);
    }

    /** True when any content-slice dimension is active (mirrors the dashboard's slice mode). */
    public function sliceActive(): bool
    {
        return $this->platform !== null || $this->contentType !== null || $this->country !== null || $this->city !== null;
    }

    /** @return array<string, mixed> normalized persistable form */
    public function toArray(): array
    {
        return [
            'grain' => $this->grain,
            'from' => $this->from?->toDateString(),
            'to' => $this->to?->toDateString(),
            'brand_id' => $this->brandId,
            'creator_id' => $this->creatorId,
            'product_id' => $this->productId,
            'platform' => $this->platform,
            'content_type' => $this->contentType,
            'country' => $this->country,
            'city' => $this->city,
        ];
    }

    /** Stable content hash for duplicate-job prevention. */
    public function hash(): string
    {
        return hash('sha256', (string) json_encode($this->toArray()));
    }

    private static function date(mixed $value, string $key): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return Carbon::createFromFormat('Y-m-d', (string) $value)->startOfDay();
        } catch (\Throwable) {
            throw ValidationException::withMessages([$key => 'Dates must use the YYYY-MM-DD format.']);
        }
    }

    private static function id(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_numeric($value) || (int) $value <= 0) {
            throw ValidationException::withMessages(['id' => 'Identifiers must be positive integers.']);
        }

        return (int) $value;
    }

    /** @param list<string> $allowed */
    private static function closedSet(mixed $value, string $key, array $allowed): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $value = (string) $value;

        if (! in_array($value, $allowed, true)) {
            throw ValidationException::withMessages([$key => "Unknown {$key} value."]);
        }

        return $value;
    }
}
