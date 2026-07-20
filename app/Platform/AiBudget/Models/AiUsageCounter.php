<?php

namespace App\Platform\AiBudget\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * One day of AI usage for one (capability, tenant) pair — the ledger
 * AiBudgetGuard enforces against (spec §10). PLATFORM operational data
 * (ingestion_alerts precedent): tenant-attributed via explicit tenant_id
 * but NOT TenantScoped, because the global budget dimensions must SUM
 * across every tenant even from inside tenant-bound jobs. Increments go
 * through the guard's atomic INSERT … ON CONFLICT DO UPDATE, never this
 * model. No personal data (GDPR-exempt, spec §13).
 *
 * @property int $id
 * @property string $capability
 * @property int $tenant_id
 * @property CarbonImmutable $usage_date
 * @property int $units
 * @property int $estimated_cost_micro_usd
 * @property int $posts_processed
 * @property int $posts_skipped_budget
 * @property int $posts_skipped_no_candidates
 * @property CarbonImmutable $updated_at
 */
class AiUsageCounter extends Model
{
    public const CREATED_AT = null;

    protected $fillable = [
        'capability',
        'tenant_id',
        'usage_date',
        'units',
        'estimated_cost_micro_usd',
        'posts_processed',
        'posts_skipped_budget',
        'posts_skipped_no_candidates',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'usage_date' => 'immutable_date',
            'units' => 'integer',
            'estimated_cost_micro_usd' => 'integer',
            'posts_processed' => 'integer',
            'posts_skipped_budget' => 'integer',
            'posts_skipped_no_candidates' => 'integer',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
