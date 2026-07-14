<?php

namespace App\Modules\Monitoring\Models;

use App\Models\User;
use App\Platform\Enrichment\Support\ReachConfigurationStatus;
use App\Shared\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Database\Factories\ReachConfigurationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One versioned reach-estimation configuration (REQ-M1-006,
 * MET-EstimatedReach, ADR-0022): a transparent formula
 * estimated_reach = round(view_weight*views + follower_weight*followers)
 * with per-platform overrides. Lifecycle DRAFT → ACTIVE → INACTIVE/ARCHIVED;
 * at most one ACTIVE per tenant; historical versions preserved so past
 * estimated reach stays reproducible. Tenant-owned (ADR-0019).
 *
 * @property int $id
 * @property int|null $tenant_id
 * @property string $name
 * @property string $method
 * @property string $formula_version
 * @property array<string, mixed> $params
 * @property CarbonImmutable $effective_from
 * @property ReachConfigurationStatus $status
 * @property string|null $notes
 * @property array<string, mixed>|null $assumptions
 * @property int|null $created_by
 * @property CarbonImmutable|null $activated_at
 * @property int|null $activated_by
 */
class ReachConfiguration extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<ReachConfigurationFactory> */
    use HasFactory;

    protected $fillable = [
        'name', 'method', 'formula_version', 'params', 'effective_from',
        'status', 'notes', 'assumptions', 'created_by', 'activated_at', 'activated_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'params' => 'array',
            'assumptions' => 'array',
            'effective_from' => 'immutable_date',
            'status' => ReachConfigurationStatus::class,
            'activated_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<User, $this> */
    public function activator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'activated_by');
    }

    public function isActive(): bool
    {
        return $this->status === ReachConfigurationStatus::Active;
    }

    /** @return HasMany<ReachResult, $this> */
    public function results(): HasMany
    {
        return $this->hasMany(ReachResult::class);
    }
}
