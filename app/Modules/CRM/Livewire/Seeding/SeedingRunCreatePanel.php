<?php

namespace App\Modules\CRM\Livewire\Seeding;

use App\Modules\CRM\Livewire\Concerns\WithInlineCreate;
use App\Modules\CRM\Models\Campaign;
use App\Modules\CRM\Models\Product;
use App\Modules\CRM\Models\SeedingCampaign;
use App\Shared\Audit\AuditLogger;
use App\Shared\Enums\SeedingCampaignStatus;
use App\Shared\Enums\SeedingType;
use App\Shared\Tenancy\TenantRule;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

/**
 * "+ New seeding run" on the campaign detail page (CRM UX Stage C, F11).
 * Brand and campaign ids come from the mounted $campaign — never user
 * input — so the run created here is coherent with its campaign's brand
 * by construction.
 */
class SeedingRunCreatePanel extends Component
{
    use AuthorizesRequests;
    use WithInlineCreate;

    public Campaign $campaign;

    public bool $showForm = false;

    public string $run_name = '';

    public string $run_type = '';

    public string $run_product_id = '';

    public function mount(Campaign $campaign): void
    {
        $this->authorize('view', $campaign);
        $this->campaign = $campaign;
    }

    public function create(): void
    {
        $this->authorize('create', SeedingCampaign::class);
        $this->reset('run_name', 'run_type', 'run_product_id');
        $this->resetValidation();
        $this->showForm = true;
    }

    public function cancelForm(): void
    {
        if ($this->inlineCreate !== null) {
            $this->cancelInlineCreate();

            return;
        }

        $this->showForm = false;
    }

    public function save(AuditLogger $audit): void
    {
        $this->authorize('create', SeedingCampaign::class);

        $validated = $this->validate([
            'run_name' => ['required', 'string', 'max:255'],
            'run_type' => ['required', Rule::in(array_column(SeedingType::cases(), 'value'))],
            'run_product_id' => ['nullable', 'integer', TenantRule::exists('products', 'id')],
        ]);

        if ($validated['run_product_id'] !== null && $validated['run_product_id'] !== '') {
            $product = Product::query()->findOrFail((int) $validated['run_product_id']);

            if ($product->brand_id !== $this->campaign->brand_id) {
                throw ValidationException::withMessages([
                    'run_product_id' => 'This product belongs to another brand.',
                ]);
            }
        }

        $run = SeedingCampaign::create([
            'name' => $validated['run_name'],
            'seeding_type' => $validated['run_type'],
            'brand_id' => $this->campaign->brand_id,   // server-side — brand coherence by construction
            'campaign_id' => $this->campaign->id,       // server-side
            'product_id' => $validated['run_product_id'] !== '' && $validated['run_product_id'] !== null
                ? (int) $validated['run_product_id'] : null,
            'status' => SeedingCampaignStatus::Draft,
        ]);

        $audit->record('seeding_campaign.created', $run, ['name' => $run->name, 'campaign_id' => $this->campaign->id]);

        // Stay on the campaign (seeding tab) rather than jumping to the brand-new,
        // empty run page — the campaign's context and counts stay visible and the
        // new run shows up in the list (with a link to open it).
        $this->redirect(route('crm.campaigns.show', $this->campaign).'#seeding');
    }

    protected function inlineCreateTypes(): array
    {
        return ['product'];
    }

    protected function inlineBrandContextId(): ?int
    {
        return $this->campaign->brand_id;
    }

    protected function inlineCreated(string $type, int $id): void
    {
        if ($type === 'product') {
            $this->run_product_id = (string) $id;
        }
    }

    /** @return array<string, string> */
    protected function validationAttributes(): array
    {
        return [
            'run_name' => 'name',
            'run_type' => 'seeding type',
            'run_product_id' => 'product',
            ...$this->inlineValidationAttributes(),
        ];
    }

    public function render(): View
    {
        return view('livewire.crm.seeding-run-create', [
            'products' => $this->campaign->brand->products()->orderBy('name')->get(),
            'typeDescriptions' => collect(SeedingType::cases())
                ->mapWithKeys(fn ($t) => [$t->value => $t->description()])->all(),
        ]);
    }
}
