<?php

namespace App\Modules\Monitoring\Livewire\Emv;

use App\Models\User;
use App\Modules\Monitoring\Models\EmvConfiguration;
use App\Platform\Enrichment\Emv\EmvConfigurationService;
use App\Platform\Enrichment\Emv\EmvConfigurationValidator;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;
use JsonException;
use Livewire\Component;

/**
 * EMV configuration management (REQ-M1-011): authorized users author
 * versioned rate-card configurations of the canonical MET-EMV structure
 * (Σ metric_i × rate_i), activate exactly one, and preserve every
 * historical version so past results stay reproducible (AC-M1-011).
 *
 * Authorization is server-side on every action: the page needs
 * monitoring.view; create/activate/deactivate/archive re-authorize
 * through EmvConfigurationPolicy (emv.manage — ADMIN) inside the service.
 */
class EmvConfigurationsIndex extends Component
{
    public bool $showForm = false;

    public string $name = '';

    public string $formulaVersion = '';

    public string $rateCardVersion = '';

    public string $currency = 'EUR';

    public string $effectiveFrom = '';

    /** Comma-separated metric labels for the Σ (metric × rate) formula. */
    public string $metrics = 'views, likes, comments';

    /** Rate card JSON: {"default": {...}, "platforms": {...}, "content_types": {...}} */
    public string $ratesJson = '';

    public string $notes = '';

    public ?string $formError = null;

    public function mount(): void
    {
        $this->authorize('viewAny', EmvConfiguration::class);
        $this->effectiveFrom = now()->toDateString();
    }

    public function create(): void
    {
        $this->authorize('create', EmvConfiguration::class);
        $this->reset(['name', 'formulaVersion', 'rateCardVersion', 'notes', 'formError']);
        $this->currency = 'EUR';
        $this->metrics = 'views, likes, comments';
        $this->ratesJson = '';
        $this->effectiveFrom = now()->toDateString();
        $this->showForm = true;
    }

    public function save(EmvConfigurationService $service): void
    {
        $this->formError = null;

        try {
            $rates = json_decode($this->ratesJson, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            $this->formError = 'Rates must be valid JSON: '.$e->getMessage();

            return;
        }

        $metrics = array_values(array_filter(array_map('trim', explode(',', $this->metrics))));

        try {
            $service->create([
                'name' => $this->name,
                'formula_version' => $this->formulaVersion,
                'rate_card_version' => $this->rateCardVersion,
                'currency' => strtoupper(trim($this->currency)),
                'formula' => [
                    'model' => EmvConfigurationValidator::MODEL_RATE_CARD_SUM,
                    'metrics' => $metrics,
                ],
                'rates' => is_array($rates) ? $rates : [],
                'effective_from' => $this->effectiveFrom,
                'notes' => trim($this->notes) !== '' ? trim($this->notes) : null,
            ], $this->user());
        } catch (InvalidArgumentException $e) {
            $this->formError = $e->getMessage();

            return;
        }

        $this->showForm = false;
        $this->dispatch('notify', type: 'success', message: 'EMV configuration created as DRAFT.');
    }

    public function activate(int $id, EmvConfigurationService $service): void
    {
        $this->lifecycle(fn () => $service->activate($this->find($id), $this->user()), 'activated');
    }

    public function deactivate(int $id, EmvConfigurationService $service): void
    {
        $this->lifecycle(fn () => $service->deactivate($this->find($id), $this->user()), 'deactivated');
    }

    public function archive(int $id, EmvConfigurationService $service): void
    {
        $this->lifecycle(fn () => $service->archive($this->find($id), $this->user()), 'archived');
    }

    /** @param callable(): mixed $action */
    private function lifecycle(callable $action, string $verb): void
    {
        try {
            $action();
        } catch (InvalidArgumentException $e) {
            $this->dispatch('notify', type: 'error', message: $e->getMessage());

            return;
        }

        $this->dispatch('notify', type: 'success', message: "EMV configuration {$verb}.");
    }

    private function find(int $id): EmvConfiguration
    {
        return EmvConfiguration::query()->findOrFail($id);
    }

    private function user(): User
    {
        /** @var User */
        return Auth::user();
    }

    public function render(): View
    {
        return view('livewire.monitoring.emv-configurations-index', [
            'configurations' => EmvConfiguration::query()
                ->orderByDesc('id')
                ->get(),
        ]);
    }
}
