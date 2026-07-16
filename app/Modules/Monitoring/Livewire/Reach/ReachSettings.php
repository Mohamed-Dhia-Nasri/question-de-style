<?php

namespace App\Modules\Monitoring\Livewire\Reach;

use App\Models\User;
use App\Modules\Monitoring\Models\ReachConfiguration;
use App\Platform\Enrichment\Reach\ReachConfigurationService;
use App\Platform\Enrichment\Support\ReachConfigurationStatus;
use App\Shared\Enums\Platform;
use Illuminate\Contracts\View\View;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Livewire\Component;

/**
 * Reach settings (REQ-M1-006, ADR-0022): a single always-editable setting
 * for non-technical operators — percentage fields per platform instead of
 * raw weights and JSON overrides. Saving authors a NEW immutable version
 * and activates it atomically, so the versioned/reproducible model
 * (one ACTIVE per tenant, history preserved) is unchanged — the UI simply
 * no longer exposes the version ledger.
 *
 * Page needs settings.view; saving re-authorizes on reach.manage (ADMIN)
 * via ReachConfigurationPolicy inside the service.
 */
class ReachSettings extends Component
{
    private const METHOD = 'qds-estimated-reach';

    /** Sample post used for the live "worked example" lines. */
    public const EXAMPLE_VIEWS = 10_000;

    public const EXAMPLE_FOLLOWERS = 20_000;

    /** @var array<string, string> */
    public const PLATFORM_LABELS = [
        'INSTAGRAM' => 'Instagram',
        'TIKTOK' => 'TikTok',
        'YOUTUBE' => 'YouTube',
    ];

    public string $name = 'Estimated reach';

    /** Whether an ACTIVE configuration exists (reach is being calculated). */
    public bool $live = false;

    public bool $perPlatform = false;

    /**
     * Whether the per-platform rows hold values worth preserving (hydrated
     * overrides or user edits). Guards against an accidental toggle
     * off-and-on wiping them by reseeding from the shared row.
     */
    public bool $platformsCustomized = false;

    /** @var array{views: string, followers: string} Percentages as typed. */
    public array $all = ['views' => '70', 'followers' => '10'];

    /** @var array<string, array{views: string, followers: string}> */
    public array $platforms = [];

    public ?string $formError = null;

    public function mount(): void
    {
        $this->authorize('viewAny', ReachConfiguration::class);

        $active = ReachConfiguration::query()
            ->where('status', ReachConfigurationStatus::Active)
            ->first();

        $this->live = $active !== null;

        $params = $active->params ?? [];
        $overrides = is_array($params['platforms'] ?? null) ? $params['platforms'] : [];

        if ($active !== null) {
            $this->name = $active->name;
            $this->all = [
                'views' => $this->percent($params['view_weight'] ?? null, '70'),
                'followers' => $this->percent($params['follower_weight'] ?? null, '10'),
            ];
            $this->perPlatform = $overrides !== [];
            $this->platformsCustomized = $overrides !== [];
        }

        foreach (Platform::cases() as $platform) {
            $this->platforms[$platform->value] = [
                'views' => $this->percent($overrides[$platform->value]['view_weight'] ?? $params['view_weight'] ?? null, $this->all['views']),
                'followers' => $this->percent($overrides[$platform->value]['follower_weight'] ?? $params['follower_weight'] ?? null, $this->all['followers']),
            ];
        }
    }

    public function updated(string $property): void
    {
        if (str_starts_with($property, 'platforms.')) {
            $this->platformsCustomized = true;
        }
    }

    /**
     * First switch to per-platform starts from the current shared values;
     * later toggles keep whatever the rows already hold.
     */
    public function updatedPerPlatform(bool $value): void
    {
        if (! $value || $this->platformsCustomized) {
            return;
        }

        foreach (Platform::cases() as $platform) {
            $this->platforms[$platform->value] = $this->all;
        }
    }

    public function save(ReachConfigurationService $service): void
    {
        $this->formError = $this->friendlyError();

        if ($this->formError !== null) {
            return;
        }

        try {
            // One transaction so the new version is created AND becomes the
            // active one together — the page never shows a dangling draft.
            DB::transaction(function () use ($service): void {
                $configuration = $service->create([
                    'name' => trim($this->name),
                    'method' => self::METHOD,
                    'formula_version' => 'reach-'.now()->format('Ymd-His').'-'.strtolower(Str::random(4)),
                    'params' => $this->buildParams(),
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
            if (! str_contains($e->getMessage(), 'reach_configurations_tenant_version_unique')
                && ! str_contains($e->getMessage(), 'reach_configurations_one_active_index')) {
                throw $e;
            }

            $this->formError = 'Could not save — another change was saved at the same moment. Please try again.';

            return;
        }

        $this->live = true;
        $this->dispatch('notify', type: 'success', message: 'Reach settings saved — new posts use them right away.');
    }

    /** @return array<string, mixed> */
    private function buildParams(): array
    {
        if (! $this->perPlatform) {
            return [
                'view_weight' => $this->weight($this->all['views']),
                'follower_weight' => $this->weight($this->all['followers']),
            ];
        }

        $overrides = [];

        foreach (Platform::cases() as $platform) {
            $overrides[$platform->value] = [
                'view_weight' => $this->weight($this->platforms[$platform->value]['views']),
                'follower_weight' => $this->weight($this->platforms[$platform->value]['followers']),
            ];
        }

        // Default weights fall back to the first platform so any future
        // platform without an override still gets valid weights.
        $first = $overrides[Platform::cases()[0]->value];

        return [
            'view_weight' => $first['view_weight'],
            'follower_weight' => $first['follower_weight'],
            'platforms' => $overrides,
        ];
    }

    private function friendlyError(): ?string
    {
        if (trim($this->name) === '') {
            return 'Give these settings a name — it is shown next to reach figures in reports.';
        }

        if (mb_strlen(trim($this->name)) > 255) {
            return 'Keep the name under 255 characters.';
        }

        $rows = $this->perPlatform
            ? array_map(
                fn (Platform $platform): array => [self::PLATFORM_LABELS[$platform->value], $this->platforms[$platform->value]],
                Platform::cases(),
            )
            : [['every platform', $this->all]];

        foreach ($rows as [$label, $row]) {
            $views = $this->num($row['views']);
            $followers = $this->num($row['followers']);

            if ($views === null || $views < 0 || $views > 100) {
                return "\"% of views counted\" for {$label} must be a number between 0 and 100.";
            }

            // The floor matches weight()'s 6-decimal precision, so no value
            // that passes here can round down to a zero follower weight.
            if ($followers === null || $followers < 0.0001 || $followers > 100) {
                return "\"% of followers counted\" for {$label} must be at least 0.0001 (up to 100) — the follower share is what keeps reach an audience estimate rather than a raw view count.";
            }
        }

        return null;
    }

    /** Live worked-example line for a weights row, or null while typing. */
    public function example(string $viewsPct, string $followersPct): ?string
    {
        $views = $this->num($viewsPct);
        $followers = $this->num($followersPct);

        if ($views === null || $followers === null) {
            return null;
        }

        $reach = (int) round(
            ($views / 100) * self::EXAMPLE_VIEWS
            + ($followers / 100) * self::EXAMPLE_FOLLOWERS
        );

        return number_format(self::EXAMPLE_VIEWS).' views + '.number_format(self::EXAMPLE_FOLLOWERS).' followers → ≈ '.number_format($reach).' people';
    }

    private function weight(string $percent): float
    {
        return round(($this->num($percent) ?? 0.0) / 100, 6);
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

    private function percent(mixed $weight, string $fallback): string
    {
        if (! is_numeric($weight)) {
            return $fallback;
        }

        // 4 decimal places of percent = weight()'s 6-decimal weight
        // precision, so hydrate-then-save round-trips exactly.
        return rtrim(rtrim(number_format((float) $weight * 100, 4, '.', ''), '0'), '.');
    }

    private function user(): User
    {
        /** @var User */
        return Auth::user();
    }

    public function render(): View
    {
        return view('livewire.monitoring.reach-settings', [
            'canManage' => $this->user()->can('create', ReachConfiguration::class),
        ]);
    }
}
