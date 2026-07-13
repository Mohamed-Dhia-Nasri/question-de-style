<?php

namespace App\Platform\Enrichment\Reach;

use App\Models\User;
use App\Modules\Monitoring\Models\ReachConfiguration;
use App\Platform\Enrichment\Support\ReachConfigurationStatus;
use App\Shared\Audit\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

/**
 * Lifecycle service for versioned reach configurations (REQ-M1-006,
 * ADR-0022 pending). Mirrors EmvConfigurationService: only reach.manage
 * holders may mutate; only DRAFT versions are editable (activated versions
 * are immutable so past estimated reach stays reproducible); at most one
 * ACTIVE per tenant (atomic swap); every change is audit-logged.
 */
class ReachConfigurationService
{
    /** Fields the validator re-checks on create/update/activate. */
    private const VALIDATED_FIELDS = ['name', 'method', 'formula_version', 'params', 'effective_from'];

    public function __construct(
        private readonly ReachConfigurationValidator $validator,
        private readonly AuditLogger $audit,
    ) {}

    /** @param array<string, mixed> $attributes */
    public function create(array $attributes, User $user): ReachConfiguration
    {
        Gate::forUser($user)->authorize('create', ReachConfiguration::class);

        $this->assertValid($attributes);

        $configuration = ReachConfiguration::query()->create([
            ...$attributes,
            'status' => ReachConfigurationStatus::Draft,
            'created_by' => $user->id,
        ]);

        $this->audit->record('reach.configuration.created', $configuration, [
            'formula_version' => $configuration->formula_version,
        ]);

        return $configuration;
    }

    /** @param array<string, mixed> $attributes */
    public function update(ReachConfiguration $configuration, array $attributes, User $user): ReachConfiguration
    {
        Gate::forUser($user)->authorize('update', $configuration);

        if ($configuration->status !== ReachConfigurationStatus::Draft) {
            throw new InvalidArgumentException(
                'Only DRAFT reach configurations are editable — activated versions are immutable so past estimated reach stays reproducible. Author a new version instead.'
            );
        }

        $this->assertValid([...$configuration->only(self::VALIDATED_FIELDS), ...$attributes]);

        $configuration->update($attributes);

        $this->audit->record('reach.configuration.updated', $configuration, [
            'formula_version' => $configuration->formula_version,
        ]);

        return $configuration;
    }

    public function activate(ReachConfiguration $configuration, User $user): ReachConfiguration
    {
        Gate::forUser($user)->authorize('update', $configuration);

        if ($configuration->status === ReachConfigurationStatus::Archived) {
            throw new InvalidArgumentException('An archived reach configuration cannot be activated.');
        }

        $this->assertValid($configuration->only(self::VALIDATED_FIELDS));

        DB::transaction(function () use ($configuration, $user): void {
            ReachConfiguration::query()
                ->where('status', ReachConfigurationStatus::Active)
                ->whereKeyNot($configuration->id)
                ->update(['status' => ReachConfigurationStatus::Inactive]);

            $configuration->update([
                'status' => ReachConfigurationStatus::Active,
                'activated_at' => CarbonImmutable::now(),
                'activated_by' => $user->id,
            ]);
        });

        $this->audit->record('reach.configuration.activated', $configuration, [
            'formula_version' => $configuration->formula_version,
        ]);

        return $configuration;
    }

    public function deactivate(ReachConfiguration $configuration, User $user): ReachConfiguration
    {
        Gate::forUser($user)->authorize('update', $configuration);

        if ($configuration->status !== ReachConfigurationStatus::Active) {
            throw new InvalidArgumentException('Only the active reach configuration can be deactivated.');
        }

        $configuration->update(['status' => ReachConfigurationStatus::Inactive]);

        $this->audit->record('reach.configuration.deactivated', $configuration, []);

        return $configuration;
    }

    public function archive(ReachConfiguration $configuration, User $user): ReachConfiguration
    {
        Gate::forUser($user)->authorize('update', $configuration);

        if ($configuration->status === ReachConfigurationStatus::Active) {
            throw new InvalidArgumentException('Deactivate the active reach configuration before archiving it.');
        }

        $configuration->update(['status' => ReachConfigurationStatus::Archived]);

        $this->audit->record('reach.configuration.archived', $configuration, []);

        return $configuration;
    }

    /** @param array<string, mixed> $attributes */
    private function assertValid(array $attributes): void
    {
        $errors = $this->validator->validate($attributes);

        if ($errors !== []) {
            throw new InvalidArgumentException('Invalid reach configuration: '.implode(' ', $errors));
        }
    }
}
