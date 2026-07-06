<?php

namespace App\Platform\Enrichment\Emv;

use App\Models\User;
use App\Modules\Monitoring\Models\EmvConfiguration;
use App\Platform\Enrichment\Support\EmvConfigurationStatus;
use App\Shared\Audit\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

/**
 * Lifecycle service for versioned EMV configurations (REQ-M1-011).
 *
 * - Only holders of emv.manage may create/edit/activate/deactivate/
 *   archive (enforced server-side via EmvConfigurationPolicy).
 * - Only DRAFT configurations are editable: once activated, a version is
 *   immutable so previously calculated EMV values stay reproducible
 *   (AC-M1-011). Changing rates means authoring a NEW version.
 * - At most one configuration is ACTIVE (DB partial unique index); the
 *   swap happens atomically.
 * - Every lifecycle change is audit-logged with user identity + timestamp.
 */
class EmvConfigurationService
{
    public function __construct(
        private readonly EmvConfigurationValidator $validator,
        private readonly AuditLogger $audit,
    ) {}

    /** @param array<string, mixed> $attributes */
    public function create(array $attributes, User $user): EmvConfiguration
    {
        Gate::forUser($user)->authorize('create', EmvConfiguration::class);

        $this->assertValid($attributes);

        $configuration = EmvConfiguration::query()->create([
            ...$attributes,
            'status' => EmvConfigurationStatus::Draft,
            'created_by' => $user->id,
        ]);

        $this->audit->record('emv.configuration.created', $configuration, [
            'formula_version' => $configuration->formula_version,
            'rate_card_version' => $configuration->rate_card_version,
        ]);

        return $configuration;
    }

    /** @param array<string, mixed> $attributes */
    public function update(EmvConfiguration $configuration, array $attributes, User $user): EmvConfiguration
    {
        Gate::forUser($user)->authorize('update', $configuration);

        if ($configuration->status !== EmvConfigurationStatus::Draft) {
            throw new InvalidArgumentException(
                'Only DRAFT EMV configurations are editable — activated versions are immutable so past results stay reproducible. Author a new version instead.'
            );
        }

        $this->assertValid([...$configuration->only([
            'name', 'formula_version', 'rate_card_version', 'currency', 'formula', 'rates', 'effective_from',
        ]), ...$attributes]);

        $configuration->update($attributes);

        $this->audit->record('emv.configuration.updated', $configuration, [
            'formula_version' => $configuration->formula_version,
            'rate_card_version' => $configuration->rate_card_version,
        ]);

        return $configuration;
    }

    public function activate(EmvConfiguration $configuration, User $user): EmvConfiguration
    {
        Gate::forUser($user)->authorize('update', $configuration);

        if ($configuration->status === EmvConfigurationStatus::Archived) {
            throw new InvalidArgumentException('An archived EMV configuration cannot be activated.');
        }

        // Re-validate at activation: only a currently-valid configuration
        // may become the active model (REQ-M1-011).
        $this->assertValid($configuration->only([
            'name', 'formula_version', 'rate_card_version', 'currency', 'formula', 'rates', 'effective_from',
        ]));

        DB::transaction(function () use ($configuration, $user): void {
            EmvConfiguration::query()
                ->where('status', EmvConfigurationStatus::Active)
                ->whereKeyNot($configuration->id)
                ->update(['status' => EmvConfigurationStatus::Inactive]);

            $configuration->update([
                'status' => EmvConfigurationStatus::Active,
                'activated_at' => CarbonImmutable::now(),
                'activated_by' => $user->id,
            ]);
        });

        $this->audit->record('emv.configuration.activated', $configuration, [
            'formula_version' => $configuration->formula_version,
            'rate_card_version' => $configuration->rate_card_version,
        ]);

        return $configuration;
    }

    public function deactivate(EmvConfiguration $configuration, User $user): EmvConfiguration
    {
        Gate::forUser($user)->authorize('update', $configuration);

        if ($configuration->status !== EmvConfigurationStatus::Active) {
            throw new InvalidArgumentException('Only the active EMV configuration can be deactivated.');
        }

        $configuration->update(['status' => EmvConfigurationStatus::Inactive]);

        $this->audit->record('emv.configuration.deactivated', $configuration, []);

        return $configuration;
    }

    public function archive(EmvConfiguration $configuration, User $user): EmvConfiguration
    {
        Gate::forUser($user)->authorize('update', $configuration);

        if ($configuration->status === EmvConfigurationStatus::Active) {
            throw new InvalidArgumentException('Deactivate the active EMV configuration before archiving it.');
        }

        $configuration->update(['status' => EmvConfigurationStatus::Archived]);

        $this->audit->record('emv.configuration.archived', $configuration, []);

        return $configuration;
    }

    /** @param array<string, mixed> $attributes */
    private function assertValid(array $attributes): void
    {
        $errors = $this->validator->validate($attributes);

        if ($errors !== []) {
            throw new InvalidArgumentException('Invalid EMV configuration: '.implode(' ', $errors));
        }
    }
}
