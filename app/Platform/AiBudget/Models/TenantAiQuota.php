<?php

namespace App\Platform\AiBudget\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Optional per-tenant AI quota override (spec §4.6): a NULL column falls
 * back to the capability's config default. Managed via qds:ai-quota in
 * v1 — billing-plan self-serve purchase is a noted billing-module
 * follow-up. Platform table (explicit tenant_id, not TenantScoped): read
 * by the guard under any context, written by a tenant-less operator
 * command.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $capability
 * @property int|null $daily_units
 * @property int|null $monthly_units
 */
class TenantAiQuota extends Model
{
    protected $fillable = ['tenant_id', 'capability', 'daily_units', 'monthly_units'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'daily_units' => 'integer',
            'monthly_units' => 'integer',
        ];
    }
}
