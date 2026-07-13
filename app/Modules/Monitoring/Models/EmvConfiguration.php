<?php

namespace App\Modules\Monitoring\Models;

use App\Models\User;
use App\Platform\Enrichment\Support\EmvConfigurationStatus;
use App\Shared\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Database\Factories\EmvConfigurationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One versioned EMV configuration (REQ-M1-011, MET-EMV, GL-EMV): the
 * canonical formula structure "Σ (metric_i × rate_i)" with a configurable,
 * transparent rate card. Every report that shows an EMV figure must
 * disclose the model and rates used (AC-M1-011).
 *
 * Lifecycle: DRAFT → ACTIVE → INACTIVE/ARCHIVED. At most one configuration
 * is ACTIVE (partial unique index); EMV is unavailable while none is.
 * Historical versions are preserved so past results stay reproducible —
 * configuration changes never alter previously calculated values.
 *
 * FLAGGED DEVIATION: not a canonical ENT-* — awaiting a data-model doc
 * amendment (see the create_emv_tables migration).
 *
 * Tenant-owned (ADR-0019): NOT NULL tenant_id, scoped and stamped via BelongsToTenant.
 *
 * @property int $id
 * @property int|null $tenant_id
 * @property string $name
 * @property string $formula_version
 * @property string $rate_card_version
 * @property string $currency
 * @property array<string, mixed> $formula
 * @property array<string, mixed> $rates
 * @property CarbonImmutable $effective_from
 * @property EmvConfigurationStatus $status
 * @property string|null $notes
 * @property array<string, mixed>|null $assumptions
 * @property int|null $created_by
 * @property CarbonImmutable|null $activated_at
 * @property int|null $activated_by
 */
class EmvConfiguration extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<EmvConfigurationFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'formula_version',
        'rate_card_version',
        'currency',
        'formula',
        'rates',
        'effective_from',
        'status',
        'notes',
        'assumptions',
        'created_by',
        'activated_at',
        'activated_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'formula' => 'array',
            'rates' => 'array',
            'assumptions' => 'array',
            'effective_from' => 'immutable_date',
            'status' => EmvConfigurationStatus::class,
            'activated_at' => 'immutable_datetime',
        ];
    }

    /** @return HasMany<EmvResult, $this> */
    public function results(): HasMany
    {
        return $this->hasMany(EmvResult::class);
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
        return $this->status === EmvConfigurationStatus::Active;
    }
}
