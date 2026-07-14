<?php

namespace App\Modules\Monitoring\Livewire\Reach;

use App\Models\User;
use App\Modules\Monitoring\Models\ReachConfiguration;
use App\Platform\Enrichment\Reach\ReachConfigurationService;
use Illuminate\Contracts\View\View;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use JsonException;
use Livewire\Component;

/**
 * Reach formula management (REQ-M1-006, ADR-0022 pending): authorized users
 * author versioned reach configurations
 * (estimated_reach = round(view_weight*views + follower_weight*followers)),
 * activate exactly one, and preserve history so past estimated reach stays
 * reproducible. Page needs settings.view; mutations re-authorize on
 * reach.manage (ADMIN) via ReachConfigurationPolicy inside the service.
 */
class ReachFormulaIndex extends Component
{
    public bool $showForm = false;

    public string $name = '';

    public string $method = 'qds-estimated-reach';

    public string $formulaVersion = '';

    public string $effectiveFrom = '';

    public string $viewWeight = '0.7';

    public string $followerWeight = '0.1';

    /** Optional per-platform overrides as JSON, e.g. {"INSTAGRAM": {"view_weight": 0.8}}. */
    public string $platformsJson = '';

    public string $notes = '';

    public ?string $formError = null;

    public function mount(): void
    {
        $this->authorize('viewAny', ReachConfiguration::class);
        $this->effectiveFrom = now()->toDateString();
    }

    public function create(): void
    {
        $this->authorize('create', ReachConfiguration::class);
        $this->reset(['name', 'formulaVersion', 'notes', 'formError', 'platformsJson']);
        $this->method = 'qds-estimated-reach';
        $this->viewWeight = '0.7';
        $this->followerWeight = '0.1';
        $this->effectiveFrom = now()->toDateString();
        $this->showForm = true;
    }

    public function save(ReachConfigurationService $service): void
    {
        $this->formError = null;

        $params = [
            'view_weight' => (float) $this->viewWeight,
            'follower_weight' => (float) $this->followerWeight,
        ];

        if (trim($this->platformsJson) !== '') {
            try {
                $platforms = json_decode($this->platformsJson, true, 8, JSON_THROW_ON_ERROR);
            } catch (JsonException $e) {
                $this->formError = 'Platform overrides must be valid JSON: '.$e->getMessage();

                return;
            }

            if (is_array($platforms)) {
                $params['platforms'] = $platforms;
            }
        }

        try {
            // Savepoint so a duplicate formula_version's unique-constraint
            // violation leaves the connection usable (mirrors ClientsIndex).
            DB::transaction(fn () => $service->create([
                'name' => $this->name,
                'method' => trim($this->method),
                'formula_version' => $this->formulaVersion,
                'params' => $params,
                'effective_from' => $this->effectiveFrom,
                'notes' => trim($this->notes) !== '' ? trim($this->notes) : null,
            ], $this->user()));
        } catch (InvalidArgumentException $e) {
            $this->formError = $e->getMessage();

            return;
        } catch (QueryException $e) {
            if (! str_contains($e->getMessage(), 'reach_configurations_tenant_version_unique')) {
                throw $e;
            }

            $this->formError = 'Could not save — a configuration with this formula version already exists for your account.';

            return;
        }

        $this->showForm = false;
        $this->dispatch('notify', type: 'success', message: 'Reach configuration created as DRAFT.');
    }

    public function activate(int $id, ReachConfigurationService $service): void
    {
        $this->lifecycle(fn () => $service->activate($this->find($id), $this->user()), 'activated');
    }

    public function deactivate(int $id, ReachConfigurationService $service): void
    {
        $this->lifecycle(fn () => $service->deactivate($this->find($id), $this->user()), 'deactivated');
    }

    public function archive(int $id, ReachConfigurationService $service): void
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

        $this->dispatch('notify', type: 'success', message: "Reach configuration {$verb}.");
    }

    private function find(int $id): ReachConfiguration
    {
        return ReachConfiguration::query()->findOrFail($id);
    }

    private function user(): User
    {
        /** @var User */
        return Auth::user();
    }

    public function render(): View
    {
        return view('livewire.monitoring.reach-formula-index', [
            'configurations' => ReachConfiguration::query()->orderByDesc('id')->get(),
        ]);
    }
}
