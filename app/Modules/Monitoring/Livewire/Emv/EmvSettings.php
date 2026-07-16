<?php

namespace App\Modules\Monitoring\Livewire\Emv;

use App\Models\User;
use App\Modules\Monitoring\Models\EmvConfiguration;
use App\Platform\Enrichment\Emv\EmvConfigurationService;
use App\Platform\Enrichment\Emv\EmvConfigurationValidator;
use App\Platform\Enrichment\Support\EmvConfigurationStatus;
use App\Shared\Enums\ContentType;
use App\Shared\Enums\Platform;
use Illuminate\Contracts\View\View;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Livewire\Component;

/**
 * EMV settings (REQ-M1-011, MET-EMV): a single always-editable setting for
 * non-technical operators — pick which interactions earn value (chips),
 * type what each one is worth, and optionally fine-tune per platform or
 * content format in plain number grids instead of a raw JSON rate card.
 *
 * Saving authors a NEW immutable version of the canonical Σ (metric × rate)
 * model and activates it atomically — versioning, reproducibility
 * (AC-M1-011) and audit logging are unchanged; the UI simply no longer
 * exposes the version ledger.
 *
 * Page needs settings.view; saving re-authorizes on emv.manage (ADMIN)
 * via EmvConfigurationPolicy inside the service.
 */
class EmvSettings extends Component
{
    /** @var array<string, string> Metric key => chip label, in canonical order. */
    public const METRIC_LABELS = [
        'views' => 'Views',
        'plays' => 'Plays',
        'likes' => 'Likes',
        'comments' => 'Comments',
        'shares' => 'Shares',
        'saves' => 'Saves',
    ];

    /** @var array<string, string> Metric key => singular unit for rate labels. */
    public const UNIT_LABELS = [
        'views' => 'One view',
        'plays' => 'One play',
        'likes' => 'One like',
        'comments' => 'One comment',
        'shares' => 'One share',
        'saves' => 'One save',
    ];

    /** @var array<string, string> Suggested starting rates, shown as placeholders only. */
    public const RATE_HINTS = [
        'views' => '0.01',
        'plays' => '0.01',
        'likes' => '0.05',
        'comments' => '0.20',
        'shares' => '0.15',
        'saves' => '0.10',
    ];

    /** @var array<string, string> */
    public const CURRENCIES = ['EUR' => '€', 'USD' => '$', 'GBP' => '£'];

    /** @var array<string, string> */
    public const PLATFORM_LABELS = [
        'INSTAGRAM' => 'Instagram',
        'TIKTOK' => 'TikTok',
        'YOUTUBE' => 'YouTube',
    ];

    /** @var array<string, string> */
    public const FORMAT_LABELS = [
        'IMAGE_POST' => 'Image post',
        'CAROUSEL' => 'Carousel',
        'REEL' => 'Reel',
        'VIDEO' => 'Video',
        'SHORT' => 'Short',
        'LIVE' => 'Live',
    ];

    /** Sample post used for the live "worked example" line. */
    public const EXAMPLE_COUNTS = [
        'views' => 10_000,
        'plays' => 8_000,
        'likes' => 500,
        'comments' => 40,
        'shares' => 25,
        'saves' => 60,
    ];

    public string $name = 'Earned media value';

    public string $currency = 'EUR';

    /** Whether an ACTIVE configuration exists (EMV is being calculated). */
    public bool $live = false;

    /** @var array<string, bool> Which interactions earn value. */
    public array $enabled = [];

    /** @var array<string, string> Base rate per metric, as typed. */
    public array $rates = [];

    public bool $byPlatform = false;

    /** @var array<string, array<string, string>> platform => metric => rate ('' = base). */
    public array $platformRates = [];

    public bool $byFormat = false;

    /** @var array<string, array<string, string>> content type => metric => rate ('' = base). */
    public array $formatRates = [];

    public ?string $formError = null;

    public function mount(): void
    {
        $this->authorize('viewAny', EmvConfiguration::class);

        foreach (array_keys(self::METRIC_LABELS) as $metric) {
            $this->enabled[$metric] = in_array($metric, ['views', 'likes', 'comments'], true);
            $this->rates[$metric] = '';
        }

        foreach (Platform::cases() as $platform) {
            $this->platformRates[$platform->value] = array_fill_keys(array_keys(self::METRIC_LABELS), '');
        }

        foreach (ContentType::cases() as $type) {
            $this->formatRates[$type->value] = array_fill_keys(array_keys(self::METRIC_LABELS), '');
        }

        $this->hydrateFromActive();
    }

    private function hydrateFromActive(): void
    {
        $active = EmvConfiguration::query()
            ->where('status', EmvConfigurationStatus::Active)
            ->first();

        $this->live = $active !== null;

        if ($active === null) {
            return;
        }

        $this->name = $active->name;
        // Keep whatever ISO code the active config carries — even one that
        // is not in the select list — so a rename-only save never silently
        // relabels the rate card's currency.
        $this->currency = $active->currency;

        $metrics = $active->formula['metrics'] ?? [];
        $default = $active->rates['default'] ?? [];

        foreach (array_keys(self::METRIC_LABELS) as $metric) {
            $this->enabled[$metric] = in_array($metric, $metrics, true);

            if (is_numeric($default[$metric] ?? null)) {
                $this->rates[$metric] = $this->rateString($default[$metric]);
            }
        }

        foreach (['platforms' => 'platformRates', 'content_types' => 'formatRates'] as $section => $property) {
            $overrides = $active->rates[$section] ?? [];

            if (! is_array($overrides) || $overrides === []) {
                continue;
            }

            $this->{$section === 'platforms' ? 'byPlatform' : 'byFormat'} = true;

            foreach ($overrides as $key => $metricRates) {
                foreach ((array) $metricRates as $metric => $rate) {
                    if (isset($this->{$property}[$key][$metric]) && is_numeric($rate)) {
                        $this->{$property}[$key][$metric] = $this->rateString($rate);
                    }
                }
            }
        }
    }

    public function toggleMetric(string $metric): void
    {
        if (array_key_exists($metric, $this->enabled)) {
            $this->enabled[$metric] = ! $this->enabled[$metric];
        }
    }

    public function save(EmvConfigurationService $service): void
    {
        $this->formError = $this->friendlyError();

        if ($this->formError !== null) {
            return;
        }

        try {
            // One transaction so the new version is created AND becomes the
            // active one together — the page never shows a dangling draft.
            DB::transaction(function () use ($service): void {
                $stamp = now()->format('Ymd-His').'-'.strtolower(Str::random(4));

                $configuration = $service->create([
                    'name' => trim($this->name),
                    'formula_version' => 'emv-'.$stamp,
                    'rate_card_version' => 'rates-'.$stamp,
                    'currency' => $this->currency,
                    'formula' => [
                        'model' => EmvConfigurationValidator::MODEL_RATE_CARD_SUM,
                        'metrics' => $this->selectedMetrics(),
                    ],
                    'rates' => $this->buildRates(),
                    'effective_from' => now()->toDateString(),
                ], $this->user());

                $service->activate($configuration, $this->user());
            });
        } catch (InvalidArgumentException $e) {
            $this->formError = $e->getMessage();

            return;
        } catch (QueryException $e) {
            // Two admins saving at the same moment can collide on either the
            // per-tenant version unique or the one-ACTIVE partial index.
            if (! str_contains($e->getMessage(), 'emv_configurations_tenant_versions_unique')
                && ! str_contains($e->getMessage(), 'emv_configurations_one_active_index')) {
                throw $e;
            }

            $this->formError = 'Could not save — another change was saved at the same moment. Please try again.';

            return;
        }

        $this->live = true;
        $this->dispatch('notify', type: 'success', message: 'EMV settings saved — new posts use them right away.');
    }

    /** @return list<string> */
    private function selectedMetrics(): array
    {
        return array_values(array_filter(
            array_keys(self::METRIC_LABELS),
            fn (string $metric): bool => $this->enabled[$metric],
        ));
    }

    /** @return array<string, mixed> */
    private function buildRates(): array
    {
        $selected = $this->selectedMetrics();

        $rates = ['default' => []];

        foreach ($selected as $metric) {
            $rates['default'][$metric] = round($this->num($this->rates[$metric]) ?? 0.0, 6);
        }

        foreach ([['platforms', $this->byPlatform, $this->platformRates], ['content_types', $this->byFormat, $this->formatRates]] as [$section, $on, $grid]) {
            if (! $on) {
                continue;
            }

            $overrides = [];

            foreach ($grid as $key => $metricRates) {
                $cells = [];

                foreach ($selected as $metric) {
                    if (trim($metricRates[$metric] ?? '') !== '') {
                        $cells[$metric] = round($this->num($metricRates[$metric]) ?? 0.0, 6);
                    }
                }

                if ($cells !== []) {
                    $overrides[$key] = $cells;
                }
            }

            if ($overrides !== []) {
                $rates[$section] = $overrides;
            }
        }

        return $rates;
    }

    /** Anything above this is a typo, not a rate (also guards float INF). */
    private const MAX_RATE = 1_000_000;

    private function friendlyError(): ?string
    {
        if (trim($this->name) === '') {
            return 'Give these settings a name — it is shown next to EMV figures in reports.';
        }

        if (mb_strlen(trim($this->name)) > 255) {
            return 'Keep the name under 255 characters.';
        }

        $selected = $this->selectedMetrics();

        if ($selected === []) {
            return 'Choose at least one interaction to count — EMV needs something to add up.';
        }

        foreach ($selected as $metric) {
            $rate = $this->num($this->rates[$metric]);

            if ($rate === null || $rate < 0 || $rate > self::MAX_RATE) {
                return 'Enter what "'.self::UNIT_LABELS[$metric].'" is worth in '.$this->currency.' — a number between 0 and 1,000,000.';
            }
        }

        foreach ([['platform', $this->byPlatform, $this->platformRates], ['content format', $this->byFormat, $this->formatRates]] as [$label, $on, $grid]) {
            if (! $on) {
                continue;
            }

            foreach ($grid as $metricRates) {
                foreach ($selected as $metric) {
                    $cell = trim($metricRates[$metric] ?? '');

                    if ($cell === '') {
                        continue;
                    }

                    $value = $this->num($cell);

                    if ($value === null || $value < 0 || $value > self::MAX_RATE) {
                        return "Values in the {$label} table must be numbers between 0 and 1,000,000 — leave a box empty to use the base value.";
                    }
                }
            }
        }

        return null;
    }

    public function currencySymbol(): string
    {
        return self::CURRENCIES[$this->currency] ?? $this->currency;
    }

    /** Live formula line built from the current chips + rates, or null while incomplete. */
    public function formulaPreview(): ?string
    {
        $parts = [];

        foreach ($this->selectedMetrics() as $metric) {
            $rate = $this->num($this->rates[$metric]);

            if ($rate !== null && is_finite($rate)) {
                $parts[] = strtolower(self::METRIC_LABELS[$metric]).' × '.$this->currencySymbol().$this->rateString($rate);
            }
        }

        return $parts === [] ? null : 'EMV per post = '.implode(' + ', $parts);
    }

    /** Live worked example for a sample post, or null while incomplete. */
    public function examplePost(): ?string
    {
        $counts = [];
        $total = 0.0;

        foreach ($this->selectedMetrics() as $metric) {
            $rate = $this->num($this->rates[$metric]);

            if ($rate === null || ! is_finite($rate)) {
                continue;
            }

            $counts[] = number_format(self::EXAMPLE_COUNTS[$metric]).' '.strtolower(self::METRIC_LABELS[$metric]);
            $total += self::EXAMPLE_COUNTS[$metric] * $rate;
        }

        if ($counts === []) {
            return null;
        }

        return 'A post with '.implode(', ', $counts).' → ≈ '.$this->currencySymbol().number_format($total, 2);
    }

    /**
     * Parse a typed number, accepting a comma as the decimal separator
     * (the pages target non-technical, often French-locale users).
     */
    private function num(string $value): ?float
    {
        $normalized = str_replace(',', '.', trim($value));

        return is_numeric($normalized) ? (float) $normalized : null;
    }

    private function rateString(mixed $rate): string
    {
        // 6 decimal places mirrors buildRates()'s round(..., 6), so
        // hydrate-then-save round-trips exactly.
        return rtrim(rtrim(number_format((float) $rate, 6, '.', ''), '0'), '.');
    }

    private function user(): User
    {
        /** @var User */
        return Auth::user();
    }

    public function render(): View
    {
        return view('livewire.monitoring.emv-settings', [
            'canManage' => $this->user()->can('create', EmvConfiguration::class),
        ]);
    }
}
