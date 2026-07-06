<?php

namespace App\Platform\Export;

use App\Modules\CRM\Models\Brand;
use App\Modules\CRM\Models\Creator;
use App\Platform\Analytics\RollupReader;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * The validated, server-side reporting filter set shared by dashboards and
 * SVC-Export (REQ-M1-012: exports use the SAME validated filters as the
 * dashboards they mirror).
 *
 * Only dimensions the approved rollup grains support are accepted here
 * (grain, period, brand, creator). Entity-list filters (platform, content
 * type, mention type, sentiment, verification status) apply to the
 * dashboard entity lists served by SVC-Monitoring; the canonical rollup
 * grains do not carry them, so they are rejected for rollup reports
 * instead of being silently ignored.
 */
final readonly class ReportFilters
{
    private function __construct(
        public string $grain,
        public ?Carbon $from,
        public ?Carbon $to,
        public ?int $brandId,
        public ?int $creatorId,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     */
    public static function validate(array $input): self
    {
        $known = ['grain', 'from', 'to', 'brand_id', 'creator_id'];

        foreach (array_keys($input) as $key) {
            if (! in_array($key, $known, true)) {
                throw ValidationException::withMessages([
                    $key => "Filter [{$key}] is not supported by the approved rollup grains.",
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

        if ($brandId !== null && ! Brand::query()->whereKey($brandId)->exists()) {
            throw ValidationException::withMessages(['brand_id' => 'Unknown brand.']);
        }

        if ($creatorId !== null && ! Creator::query()->whereKey($creatorId)->exists()) {
            throw ValidationException::withMessages(['creator_id' => 'Unknown creator.']);
        }

        return new self($grain, $from, $to, $brandId, $creatorId);
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
}
