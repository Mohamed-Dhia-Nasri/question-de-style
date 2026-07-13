<?php

namespace App\Modules\CRM\Livewire\Creators;

use App\Modules\CRM\Exceptions\PlatformAccountConflict;
use App\Modules\CRM\Models\Creator;
use App\Modules\CRM\Models\PlatformAccount;
use App\Modules\CRM\Services\CreatorWriter;
use App\Shared\Audit\AuditLogger;
use App\Shared\Enums\Platform;
use Illuminate\Contracts\View\View;
use Illuminate\Database\QueryException;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

/**
 * Platform accounts panel — the operator's identity-authority surface
 * (spec §2.4, ADR-0014): the human asserts which accounts are this one
 * person by adding/editing/removing them directly. All writes route through
 * CreatorWriter, which enforces one account per ENUM-Platform per creator
 * (the Step-1 latent gap, closed here) and global (platform, handle)
 * uniqueness; conflicts surface as caught validation errors, never a
 * silently created second account.
 *
 * Removal DELETES the account — there is no move-to-another-creator path in
 * v1 (ADR-0014 known limitation). If M1 monitoring history anchors to the
 * account, the DB's restrict FKs refuse and the operator is told why.
 * There is NO auto-detection of accounts/handles (deferred with the
 * automatic path of REQ-M3-001 by ADR-0014) and no merge control.
 */
class PlatformAccountsPanel extends Component
{
    public Creator $creator;

    // --- add/edit form state ---
    public bool $showForm = false;

    public ?int $editingAccountId = null;

    public string $account_platform = '';

    public string $account_handle = '';

    public string $account_bio = '';

    /** One URL per line (ENT-PlatformAccount.externalLinks — list of url). */
    public string $account_links = '';

    // --- remove confirmation state ---
    public ?int $confirmingRemoveId = null;

    public function mount(Creator $creator): void
    {
        $this->authorize('view', $creator);

        $this->creator = $creator;
    }

    public function add(): void
    {
        $this->authorize('create', PlatformAccount::class);

        $this->resetForm();
        $this->showForm = true;
    }

    public function edit(int $accountId): void
    {
        $account = $this->creator->platformAccounts()->findOrFail($accountId);

        $this->authorize('update', $account);

        $this->resetForm();
        $this->editingAccountId = $account->id;
        $this->account_platform = $account->platform->value;
        $this->account_handle = $account->handle;
        $this->account_bio = $account->bio ?? '';
        $this->account_links = implode("\n", $account->external_links ?? []);
        $this->showForm = true;
    }

    public function save(CreatorWriter $writer, AuditLogger $audit): void
    {
        $editing = $this->editingAccountId !== null;
        $account = $editing ? $this->creator->platformAccounts()->findOrFail($this->editingAccountId) : null;

        $this->authorize($editing ? 'update' : 'create', $account ?? PlatformAccount::class);

        $validated = $this->validate([
            'account_platform' => ['required', Rule::in(array_column(Platform::cases(), 'value'))],
            'account_handle' => ['required', 'string', 'max:255'],
            'account_bio' => ['nullable', 'string', 'max:5000'],
            'account_links' => ['nullable', 'string'],
        ]);

        $platform = Platform::from($validated['account_platform']);
        $bio = ($validated['account_bio'] ?? '') !== '' ? $validated['account_bio'] : null;
        $links = $this->parseLinks($validated['account_links'] ?? '');

        try {
            if ($editing) {
                $writer->updatePlatformAccount($account, $platform, $validated['account_handle'], $bio, $links);
                $audit->record('platform_account.updated', $account, [
                    'platform' => $platform->value,
                    'handle' => $validated['account_handle'],
                ]);
            } else {
                $account = $writer->addManualPlatformAccount(
                    $this->creator,
                    $platform,
                    $validated['account_handle'],
                    $bio,
                    $links,
                );
                $audit->record('platform_account.added', $account, [
                    'platform' => $platform->value,
                    'handle' => $account->handle,
                ]);
            }
        } catch (PlatformAccountConflict $conflict) {
            throw ValidationException::withMessages(['account_handle' => $conflict->getMessage()]);
        }

        $this->creator->refresh();
        $this->showForm = false;
        $this->resetForm();
        $this->dispatch('notify', type: 'success', message: $editing ? 'Platform account updated.' : 'Platform account added.');
    }

    public function cancelForm(): void
    {
        $this->showForm = false;
        $this->resetForm();
    }

    // --- remove --------------------------------------------------------------

    public function confirmRemove(int $accountId): void
    {
        $account = $this->creator->platformAccounts()->findOrFail($accountId);

        $this->authorize('delete', $account);

        $this->confirmingRemoveId = $accountId;
    }

    public function remove(CreatorWriter $writer, AuditLogger $audit): void
    {
        if ($this->confirmingRemoveId === null) {
            return;
        }

        $account = $this->creator->platformAccounts()->findOrFail($this->confirmingRemoveId);

        $this->authorize('delete', $account);

        try {
            $writer->removePlatformAccount($account);
        } catch (QueryException) {
            // M1's restrict FKs (content_items / stories / metric_snapshots)
            // protect monitoring history — never cascade another module's data.
            $this->confirmingRemoveId = null;
            $this->dispatch('notify', type: 'error', message: 'Cannot remove: this account has monitoring history (content, stories, or snapshots).');

            return;
        }

        $audit->record('platform_account.removed', $account, [
            'platform' => $account->platform->value,
            'handle' => $account->handle,
        ]);

        $this->creator->refresh();
        $this->confirmingRemoveId = null;
        $this->dispatch('notify', type: 'success', message: 'Platform account removed.');
    }

    public function cancelRemove(): void
    {
        $this->confirmingRemoveId = null;
    }

    // -------------------------------------------------------------------------

    /**
     * @return list<string>
     *
     * @throws ValidationException
     */
    protected function parseLinks(string $raw): array
    {
        $links = array_values(array_filter(array_map('trim', preg_split('/\R/', $raw) ?: [])));

        foreach ($links as $link) {
            if (filter_var($link, FILTER_VALIDATE_URL) === false) {
                throw ValidationException::withMessages([
                    'account_links' => "[{$link}] is not a valid URL — one link per line.",
                ]);
            }
        }

        return $links;
    }

    protected function resetForm(): void
    {
        $this->resetValidation();
        $this->editingAccountId = null;
        $this->account_platform = '';
        $this->account_handle = '';
        $this->account_bio = '';
        $this->account_links = '';
    }

    public function render(): View
    {
        return view('livewire.crm.creator-platform-accounts', [
            'accounts' => $this->creator->platformAccounts()->orderBy('platform')->get(),
            'platforms' => Platform::cases(),
        ]);
    }
}
