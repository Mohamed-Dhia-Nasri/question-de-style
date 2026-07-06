<?php

namespace App\Modules\Monitoring\Policies;

use App\Models\User;
use App\Modules\Monitoring\Models\EmvResult;
use App\Shared\Authorization\PermissionsCatalog;

/**
 * EMV results are append-only calculation records written by the
 * EmvCalculator. Staff read them; no user surface ever mutates them —
 * past EMV values stay reproducible (AC-M1-011).
 */
class EmvResultPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(PermissionsCatalog::MONITORING_VIEW);
    }

    public function view(User $user, EmvResult $result): bool
    {
        return $user->can(PermissionsCatalog::MONITORING_VIEW);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, EmvResult $result): bool
    {
        return false;
    }

    public function delete(User $user, EmvResult $result): bool
    {
        return false;
    }
}
