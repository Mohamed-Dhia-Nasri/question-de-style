<?php

namespace App\Modules\CRM\Livewire\Campaigns;

use App\Modules\CRM\Models\Brand;
use App\Modules\CRM\Models\Campaign;
use App\Modules\CRM\Models\Client;
use App\Modules\CRM\Models\Creator;
use App\Modules\CRM\Models\Product;
use App\Modules\CRM\Models\SeedingCampaign;
use App\Modules\CRM\Services\BrandRestrictionGuard;
use App\Modules\CRM\Services\CampaignWriter;
use App\Modules\CRM\Services\CreatorWriter;
use App\Shared\Audit\AuditLogger;
use App\Shared\Enums\CampaignStatus;
use App\Shared\Enums\Country;
use App\Shared\Enums\SeedingCampaignStatus;
use App\Shared\Enums\SeedingType;
use App\Shared\Tenancy\TenantRule;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

/**
 * Optional guided campaign wizard at /crm/campaigns/new (CRM UX Stage C,
 * F01/F02). One full-page-embedded component walks a new campaign from its
 * client and brand through an optional seeding run and a first creator
 * roster. Nothing is written until the final commit: every step is skippable
 * (`createNow()` finishes with the campaign only), all writes land in ONE
 * DB::transaction, the campaign is forced to Draft, and the flow ends on an
 * in-wizard Done screen (never a redirect) so skipped restricted-creator
 * names survive to be reported.
 *
 * The whole surface is create-only: mount authorizes `create, Campaign` so a
 * crm.view-only user is refused the page outright.
 */
class CampaignWizard extends Component
{
    use AuthorizesRequests;

    public int $step = 1;

    public bool $finished = false;

    // --- Step 1: client & brand ---
    public string $client_mode = 'existing';

    public string $wizard_client_id = '';

    public string $new_client_name = '';

    public string $new_client_country = '';

    public string $brand_mode = 'existing';

    public string $wizard_brand_id = '';

    public string $new_brand_name = '';

    // --- Step 2: campaign ---
    public string $campaign_name = '';

    public string $campaign_start_at = '';

    public string $campaign_end_at = '';

    // --- Step 3: seeding run ---
    public bool $with_seeding = false;

    public string $run_name = '';

    public string $run_type = '';

    public string $run_product_id = '';

    // --- Step 4: creators ---
    public string $creator_search = '';

    /** @var list<string> */
    public array $selected_creator_ids = [];

    public bool $showNewCreatorForm = false;

    public string $new_creator_name = '';

    public string $new_creator_language = '';

    // --- Results ---
    public ?int $createdCampaignId = null;

    public ?int $createdRunId = null;

    /** @var list<string> */
    public array $skippedCreators = [];

    public function mount(): void
    {
        $this->authorize('create', Campaign::class);

        // A fresh tenant with no clients starts on the "new client" path.
        $this->client_mode = Client::query()->exists() ? 'existing' : 'new';
        $this->brand_mode = $this->defaultBrandMode();
    }

    /** A new client has no brands; an existing one defaults to its brands if any. */
    private function defaultBrandMode(): string
    {
        if ($this->client_mode === 'new') {
            return 'new';
        }

        $clientId = $this->wizard_client_id !== '' ? (int) $this->wizard_client_id : null;

        if ($clientId === null) {
            return 'new';
        }

        return Brand::query()->where('client_id', $clientId)->exists() ? 'existing' : 'new';
    }

    /** Choosing a brand-new client forces a brand-new brand (it has none yet). */
    public function updatedClientMode(): void
    {
        if ($this->client_mode === 'new') {
            $this->brand_mode = 'new';
            $this->wizard_brand_id = '';

            return;
        }

        $this->brand_mode = $this->defaultBrandMode();
    }

    /** Switching the chosen existing client re-picks a coherent brand default. */
    public function updatedWizardClientId(): void
    {
        $this->wizard_brand_id = '';

        if ($this->client_mode === 'existing') {
            $this->brand_mode = $this->defaultBrandMode();
        }
    }

    // --- Navigation --------------------------------------------------------

    public function next(): void
    {
        $this->validateStep($this->step);

        if ($this->step < 5) {
            $this->step++;
        }
    }

    public function back(): void
    {
        if ($this->step > 1) {
            $this->step--;
        }
    }

    private function validateStep(int $step): void
    {
        match ($step) {
            1 => $this->validateStep1(),
            2 => $this->validate($this->step2Rules()),
            3 => $this->validateStep3(),
            4 => $this->validate($this->step4Rules()),
            default => null,
        };
    }

    private function validateStep1(): void
    {
        if ($this->client_mode === 'new') {
            // Case-insensitive on entry (the select sends uppercase; direct
            // input normalizes before the closed-set check) — ClientsIndex precedent.
            $this->new_client_country = strtoupper(trim($this->new_client_country));
        }

        $this->validate($this->step1Rules());

        // An existing brand must belong to the chosen (existing) client.
        if ($this->brand_mode === 'existing') {
            $belongs = Brand::query()
                ->whereKey((int) $this->wizard_brand_id)
                ->where('client_id', (int) $this->wizard_client_id)
                ->exists();

            if (! $belongs) {
                throw ValidationException::withMessages([
                    'wizard_brand_id' => 'This brand belongs to another client.',
                ]);
            }
        }
    }

    /** @return array<string, mixed> */
    private function step1Rules(): array
    {
        $rules = ['client_mode' => ['required', Rule::in(['existing', 'new'])]];

        if ($this->client_mode === 'existing') {
            $rules['wizard_client_id'] = ['required', 'integer', TenantRule::exists('clients', 'id')];
        } else {
            $rules['new_client_name'] = ['required', 'string', 'max:255'];
            $rules['new_client_country'] = ['nullable', 'string', Rule::in(Country::values())];
        }

        $rules['brand_mode'] = ['required', Rule::in(['existing', 'new'])];

        if ($this->brand_mode === 'existing') {
            $rules['wizard_brand_id'] = ['required', 'integer', TenantRule::exists('brands', 'id')];
        } else {
            $rules['new_brand_name'] = ['required', 'string', 'max:255'];
        }

        return $rules;
    }

    /** @return array<string, mixed> */
    private function step2Rules(): array
    {
        return [
            'campaign_name' => ['required', 'string', 'max:255'],
            'campaign_start_at' => ['nullable', 'date'],
            'campaign_end_at' => ['nullable', 'date', 'after_or_equal:campaign_start_at'],
        ];
    }

    private function validateStep3(): void
    {
        if (! $this->with_seeding) {
            return;
        }

        $this->validate([
            'run_name' => ['required', 'string', 'max:255'],
            'run_type' => ['required', Rule::in(array_column(SeedingType::cases(), 'value'))],
            'run_product_id' => ['nullable', 'integer', TenantRule::exists('products', 'id')],
        ]);

        // A product only exists under an existing brand and must be that brand's.
        if ($this->run_product_id !== '') {
            $ok = $this->brand_mode === 'existing'
                && Product::query()
                    ->whereKey((int) $this->run_product_id)
                    ->where('brand_id', (int) $this->wizard_brand_id)
                    ->exists();

            if (! $ok) {
                throw ValidationException::withMessages([
                    'run_product_id' => 'This product belongs to another brand.',
                ]);
            }
        }
    }

    /** @return array<string, mixed> */
    private function step4Rules(): array
    {
        return [
            'selected_creator_ids' => ['array'],
            'selected_creator_ids.*' => ['integer', TenantRule::exists('creators', 'id')],
        ];
    }

    /**
     * Inline "+ New creator" on the creators step (Stage C review polish):
     * every other prerequisite in the wizard — client, brand, seeding run —
     * is create-in-place, so a brand-new tenant should not have to abandon
     * the wizard just to make a first creator. A wizard-local parallel of
     * ManagesCreatorRoster::createAndAttachCreator; the wizard is not a
     * roster-picker host, so it does not pull in that trait or partial.
     *
     * A freshly created creator has no brand preferences yet, so no
     * restriction check applies here — the existing restricted-flag logic
     * in commit() still runs over every selected id at finish().
     */
    public function createCreator(CreatorWriter $writer, AuditLogger $audit): void
    {
        $this->authorize('create', Creator::class);

        $validated = $this->validate([
            'new_creator_name' => ['required', 'string', 'max:255'],
            'new_creator_language' => ['nullable', 'string', 'max:10'],
        ]);

        // Through the sanctioned write path: createCreator auto-enrolls the
        // new creator into monitoring in the same transaction.
        $creator = $writer->createCreator(
            $validated['new_creator_name'],
            ($validated['new_creator_language'] ?? '') !== '' ? $validated['new_creator_language'] : null,
        );

        $audit->record('creator.created', $creator, ['display_name' => $creator->display_name]);

        $this->selected_creator_ids[] = (string) $creator->id;

        $this->reset('showNewCreatorForm', 'new_creator_name', 'new_creator_language');
        $this->resetValidation();

        $this->dispatch('notify', type: 'success', message: 'Creator created and added.');
    }

    // --- Finishing ---------------------------------------------------------

    /** Skip the rest — create the campaign only (steps 1-2 validated). */
    public function createNow(AuditLogger $audit, BrandRestrictionGuard $guard): void
    {
        $this->validateStep1();
        $this->validate($this->step2Rules());

        $this->commit($audit, $guard, withExtras: false);
    }

    /** Full finish from the Review step — everything validated, everything written. */
    public function finish(AuditLogger $audit, BrandRestrictionGuard $guard): void
    {
        $this->validateStep1();
        $this->validate($this->step2Rules());
        $this->validateStep3();
        $this->validate($this->step4Rules());

        $this->commit($audit, $guard, withExtras: true);
    }

    private function commit(AuditLogger $audit, BrandRestrictionGuard $guard, bool $withExtras): void
    {
        $this->authorize('create', Campaign::class);

        if ($this->client_mode === 'new') {
            $this->authorize('create', Client::class);
        }

        if ($this->brand_mode === 'new') {
            $this->authorize('create', Brand::class);
        }

        if ($withExtras && $this->with_seeding) {
            $this->authorize('create', SeedingCampaign::class);
        }

        DB::transaction(function () use ($audit, $guard, $withExtras) {
            $client = $this->client_mode === 'new'
                ? tap(Client::create([
                    'name' => $this->new_client_name,
                    'country' => ($country = strtoupper(trim($this->new_client_country))) !== '' ? $country : null,
                ]), fn (Client $client) => $audit->record('client.created', $client, ['name' => $client->name]))
                : Client::query()->findOrFail((int) $this->wizard_client_id);

            $brand = $this->brand_mode === 'new'
                ? tap(
                    Brand::create(['client_id' => $client->id, 'name' => $this->new_brand_name]),
                    fn (Brand $brand) => $audit->record('brand.created', $brand, ['name' => $brand->name])
                )
                : Brand::query()->where('client_id', $client->id)->findOrFail((int) $this->wizard_brand_id);

            // Through the single sanctioned write path (CampaignWriter). It
            // opens no transaction of its own, so it composes inside this one;
            // the brand-coherence guard (F14) only fires on a brand *change*,
            // and a wizard-created campaign is coherent by construction.
            $campaign = app(CampaignWriter::class)->createCampaign([
                'brand_id' => $brand->id,
                'name' => $this->campaign_name,
                'status' => CampaignStatus::Draft,
                'start_at' => $this->campaign_start_at !== '' ? $this->campaign_start_at : null,
                'end_at' => $this->campaign_end_at !== '' ? $this->campaign_end_at : null,
            ], $audit);

            $run = null;
            if ($withExtras && $this->with_seeding) {
                $run = SeedingCampaign::create([
                    'name' => $this->run_name,
                    'seeding_type' => $this->run_type,
                    'brand_id' => $brand->id,
                    'campaign_id' => $campaign->id,
                    'product_id' => $this->run_product_id !== '' ? (int) $this->run_product_id : null,
                    'status' => SeedingCampaignStatus::Draft,
                ]);
                $audit->record('seeding_campaign.created', $run, ['name' => $run->name, 'campaign_id' => $campaign->id]);
            }

            if ($withExtras && $this->selected_creator_ids !== []) {
                $ids = array_values(array_unique(array_map('intval', $this->selected_creator_ids)));
                // Brand exists by now, so the Brand overload is safe here.
                $restricted = $guard->restrictedCreatorIds($ids, $brand);
                $allowed = array_values(array_diff($ids, $restricted));
                $this->skippedCreators = Creator::query()->whereIn('id', $restricted)->pluck('display_name')->all();

                if ($allowed !== []) {
                    $attached = $campaign->creators()->syncWithoutDetaching($allowed);
                    foreach ($attached['attached'] as $id) {
                        $audit->record('campaign_creator.attached', $campaign, ['creator_id' => $id]);
                    }

                    if ($run !== null) {
                        $runAttached = $run->creators()->syncWithoutDetaching($allowed);
                        foreach ($runAttached['attached'] as $id) {
                            $audit->record('seeding_campaign_creator.attached', $run, ['creator_id' => $id]);
                        }
                    }
                }
            }

            $this->createdCampaignId = $campaign->id;
            $this->createdRunId = $run?->id;
        });

        $this->finished = true;
    }

    // --- Render ------------------------------------------------------------

    /** The brand name restriction flags key off: an existing brand's name or the new-brand name. */
    private function currentBrandName(): string
    {
        if ($this->brand_mode === 'existing' && $this->wizard_brand_id !== '') {
            return (string) (Brand::query()->whereKey((int) $this->wizard_brand_id)->value('name') ?? '');
        }

        return trim($this->new_brand_name);
    }

    public function render(BrandRestrictionGuard $guard): View
    {
        $clients = Client::query()->orderBy('name')->get();

        $selectedClientId = $this->client_mode === 'existing' && $this->wizard_client_id !== ''
            ? (int) $this->wizard_client_id
            : null;

        $brands = $selectedClientId !== null
            ? Brand::query()->where('client_id', $selectedClientId)->orderBy('name')->get()
            : new Collection;

        $selectedBrandId = $this->brand_mode === 'existing' && $this->wizard_brand_id !== ''
            ? (int) $this->wizard_brand_id
            : null;

        $products = $selectedBrandId !== null
            ? Product::query()->where('brand_id', $selectedBrandId)->orderBy('name')->get()
            : new Collection;

        // The candidate list (and its restriction batch) only matters on the
        // creators step — never run the queries on the other steps.
        $candidates = new Collection;
        $restrictedIds = [];

        if ($this->step === 4) {
            $candidates = Creator::query()
                ->with('platformAccounts')
                ->when($this->creator_search !== '', function (Builder $query) {
                    $query->where(function (Builder $query) {
                        $query->where('display_name', 'ilike', '%'.$this->creator_search.'%')
                            ->orWhereHas('platformAccounts', function (Builder $query) {
                                $query->where('handle', 'ilike', '%'.$this->creator_search.'%');
                            });
                    });
                })
                ->orderBy('display_name')
                ->limit(51)
                ->get();

            $brandName = $this->currentBrandName();
            $restrictedIds = $brandName !== ''
                ? $guard->restrictedCreatorIdsForName(
                    $candidates->pluck('id')->map(fn ($id) => (int) $id)->all(),
                    $brandName
                )
                : [];
        }

        return view('livewire.crm.campaign-wizard', [
            'clients' => $clients,
            'brands' => $brands,
            'products' => $products,
            'candidates' => $candidates,
            'restrictedIds' => $restrictedIds,
            'countries' => Country::cases(),
            'seedingTypes' => SeedingType::cases(),
            'typeDescriptions' => collect(SeedingType::cases())
                ->mapWithKeys(fn (SeedingType $type) => [$type->value => $type->description()])
                ->all(),
            'currentBrandName' => $this->currentBrandName(),
        ]);
    }
}
