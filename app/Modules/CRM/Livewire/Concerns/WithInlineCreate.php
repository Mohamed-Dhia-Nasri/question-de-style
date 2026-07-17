<?php

namespace App\Modules\CRM\Livewire\Concerns;

use App\Modules\CRM\Models\Brand;
use App\Modules\CRM\Models\Campaign;
use App\Modules\CRM\Models\Client;
use App\Modules\CRM\Models\Product;
use App\Shared\Audit\AuditLogger;
use App\Shared\Enums\CampaignStatus;
use App\Shared\Enums\Country;
use App\Shared\Tenancy\TenantRule;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Inline "+ create" for parent selects (CRM UX Stage C, F01a). A host that
 * has a parent picker (a brand select needs a client; a campaign select
 * needs a brand) can open a small second modal, create the missing record,
 * and have it auto-selected — without leaving the form it was filling in.
 *
 * Same-component state on purpose: no nested Livewire, no cross-component
 * events. The shared `x-crm.inline-create` blade renders AFTER the host's
 * own modal and binds to the `inline_*` props below (their names are part
 * of the contract — the component hardcodes them).
 *
 * A host opts in with `use WithInlineCreate;`, whitelists the parent types
 * it offers via `inlineCreateTypes()`, and assigns the freshly created id to
 * its own select prop in `inlineCreated()`. For product/campaign creation the
 * host also supplies the owning brand through `inlineBrandContextId()`.
 *
 * Escape delegation: two modals are on screen at once and both listen for
 * window Escape, so every host's `cancelForm()` must first close the inline
 * form when one is open (see the hosts' `cancelForm()`).
 */
trait WithInlineCreate
{
    /** Which inline form is open: 'client'|'brand'|'product'|'campaign' or null. */
    public ?string $inlineCreate = null;

    public string $inline_client_name = '';

    public string $inline_client_country = '';

    public string $inline_brand_name = '';

    public string $inline_brand_client_id = '';

    /** Brand form: toggles the client select into a new-client name input. */
    public bool $inline_new_client = false;

    public string $inline_product_name = '';

    public string $inline_campaign_name = '';

    /**
     * Parent types this host offers inline, e.g. ['client'] for a brand host.
     *
     * @return list<string>
     */
    abstract protected function inlineCreateTypes(): array;

    /** Assign the freshly created record to the host's own select prop. */
    abstract protected function inlineCreated(string $type, int $id): void;

    /** Owning brand for inline product/campaign creation (null when unknown). */
    protected function inlineBrandContextId(): ?int
    {
        return null;
    }

    public function openInlineCreate(string $type): void
    {
        if (! in_array($type, $this->inlineCreateTypes(), true)) {
            return;
        }

        $this->authorize('create', $this->inlineModelClass($type));

        if (in_array($type, ['product', 'campaign'], true) && $this->inlineBrandContextId() === null) {
            $this->dispatch('notify', type: 'error', message: 'Choose a brand first.');

            return;
        }

        $this->resetInlineCreate();
        $this->inlineCreate = $type;
    }

    public function cancelInlineCreate(): void
    {
        $this->resetInlineCreate();
    }

    public function saveInlineCreate(AuditLogger $audit): void
    {
        $type = $this->inlineCreate;

        if ($type === null || ! in_array($type, $this->inlineCreateTypes(), true)) {
            return;
        }

        if ($type === 'client') {
            $this->authorize('create', Client::class);

            // Case-insensitive on entry (ClientsIndex::save precedent).
            $this->inline_client_country = strtoupper(trim($this->inline_client_country));

            $validated = $this->validate([
                'inline_client_name' => ['required', 'string', 'max:255'],
                'inline_client_country' => ['nullable', 'string', Rule::in(Country::values())],
            ]);

            $client = Client::create([
                'name' => $validated['inline_client_name'],
                'country' => ($validated['inline_client_country'] ?? '') !== ''
                    ? strtoupper($validated['inline_client_country'])
                    : null,
            ]);
            $audit->record('client.created', $client, ['name' => $client->name]);

            $this->inlineCreated('client', $client->id);
            $this->resetInlineCreate();
            $this->dispatch('notify', type: 'success', message: 'Client created.');

            return;
        }

        if ($type === 'brand') {
            $this->authorize('create', Brand::class);

            // The component shows the new-client name input when the toggle is
            // on OR the tenant has no clients to pick — mirror that here so the
            // no-clients path validates the name, not an absent select.
            if ($this->inline_new_client || Client::query()->doesntExist()) {
                $this->authorize('create', Client::class);
                $validated = $this->validate([
                    'inline_client_name' => ['required', 'string', 'max:255'],
                    'inline_brand_name' => ['required', 'string', 'max:255'],
                ]);
                $brand = DB::transaction(function () use ($validated, $audit) {
                    $client = Client::create(['name' => $validated['inline_client_name']]);
                    $audit->record('client.created', $client, ['name' => $client->name]);
                    $brand = Brand::create(['client_id' => $client->id, 'name' => $validated['inline_brand_name']]);
                    $audit->record('brand.created', $brand, ['name' => $brand->name]);

                    return $brand;
                });
            } else {
                $validated = $this->validate([
                    'inline_brand_client_id' => ['required', 'integer', TenantRule::exists('clients', 'id')],
                    'inline_brand_name' => ['required', 'string', 'max:255'],
                ]);
                $brand = Brand::create(['client_id' => (int) $validated['inline_brand_client_id'], 'name' => $validated['inline_brand_name']]);
                $audit->record('brand.created', $brand, ['name' => $brand->name]);
            }

            $this->inlineCreated('brand', $brand->id);
            $this->resetInlineCreate();
            $this->dispatch('notify', type: 'success', message: 'Brand created.');

            return;
        }

        // product / campaign — the owning brand comes from the host context,
        // never from tamperable client state.
        $brandId = $this->inlineBrandContextId();

        if ($brandId === null) {
            $this->dispatch('notify', type: 'error', message: 'Choose a brand first.');

            return;
        }

        if ($type === 'product') {
            $this->authorize('create', Product::class);

            $validated = $this->validate([
                'inline_product_name' => ['required', 'string', 'max:255'],
            ]);

            $product = Product::create(['brand_id' => $brandId, 'name' => $validated['inline_product_name']]);
            $audit->record('product.created', $product, ['name' => $product->name]);

            $this->inlineCreated('product', $product->id);
            $this->resetInlineCreate();
            $this->dispatch('notify', type: 'success', message: 'Product created.');

            return;
        }

        if ($type === 'campaign') {
            $this->authorize('create', Campaign::class);

            $validated = $this->validate([
                'inline_campaign_name' => ['required', 'string', 'max:255'],
            ]);

            $campaign = Campaign::create([
                'brand_id' => $brandId,
                'name' => $validated['inline_campaign_name'],
                'status' => CampaignStatus::Draft,
            ]);
            $audit->record('campaign.created', $campaign, ['name' => $campaign->name]);

            $this->inlineCreated('campaign', $campaign->id);
            $this->resetInlineCreate();
            $this->dispatch('notify', type: 'success', message: 'Campaign created.');
        }
    }

    /** Friendly validation names for the inline fields (merge in each host). */
    protected function inlineValidationAttributes(): array
    {
        return [
            'inline_client_name' => 'client name',
            'inline_client_country' => 'country',
            'inline_brand_name' => 'brand name',
            'inline_brand_client_id' => 'client',
            'inline_product_name' => 'product name',
            'inline_campaign_name' => 'campaign name',
        ];
    }

    private function inlineModelClass(string $type): string
    {
        return match ($type) {
            'client' => Client::class,
            'brand' => Brand::class,
            'product' => Product::class,
            'campaign' => Campaign::class,
        };
    }

    private function resetInlineCreate(): void
    {
        $this->resetValidation();
        $this->inlineCreate = null;
        $this->inline_client_name = '';
        $this->inline_client_country = '';
        $this->inline_brand_name = '';
        $this->inline_brand_client_id = '';
        $this->inline_new_client = false;
        $this->inline_product_name = '';
        $this->inline_campaign_name = '';
    }
}
