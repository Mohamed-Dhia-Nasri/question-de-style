<?php

namespace App\Modules\CRM\Models;

use App\Modules\Monitoring\Models\Mention;
use App\Modules\Monitoring\Models\MonitoredSubject;
use App\Shared\Casts\AsValueObject;
use App\Shared\Enums\CampaignStatus;
use App\Shared\Tenancy\BelongsToTenant;
use App\Shared\ValueObjects\MetricValue;
use Carbon\CarbonImmutable;
use Database\Factories\CampaignFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * ENT-Campaign — a marketing campaign (docs/30-data-model/00-data-model.md#ent-campaign).
 *
 * Write-owner: Module 3 CRM (ownership matrix). Module 1 reads campaigns for
 * mention attribution and reporting only. creatorIds is the campaign_creator
 * participation pivot (M3 data foundation).
 *
 * `spend` is the agency-entered amount spent (MetricValue envelope, tier
 * CONFIRMED, metric 'spend') — the CPE/CPM input (AC-M3-015). FLAGGED
 * DEVIATION (spec D1): no canonical ENT-Campaign field, awaiting a
 * data-model doc amendment.
 *
 * Tenant-owned (ADR-0019): NOT NULL tenant_id, scoped and stamped via BelongsToTenant.
 *
 * @property int $id
 * @property int|null $tenant_id
 * @property string $name
 * @property int $brand_id
 * @property CampaignStatus $status
 * @property CarbonImmutable|null $start_at
 * @property CarbonImmutable|null $end_at
 * @property MetricValue|null $spend
 */
class Campaign extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<CampaignFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'brand_id',
        'status',
        'start_at',
        'end_at',
        'spend',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => CampaignStatus::class,
            'start_at' => 'immutable_datetime',
            'end_at' => 'immutable_datetime',
            'spend' => AsValueObject::class.':'.MetricValue::class,
        ];
    }

    /** @return BelongsTo<Brand, $this> */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    /** @return HasMany<Mention, $this> */
    public function mentions(): HasMany
    {
        return $this->hasMany(Mention::class);
    }

    /** @return HasMany<MonitoredSubject, $this> */
    public function monitoredSubjects(): HasMany
    {
        return $this->hasMany(MonitoredSubject::class);
    }

    /**
     * ENT-Campaign.creatorIds — participating creators.
     *
     * @return BelongsToMany<Creator, $this>
     */
    public function creators(): BelongsToMany
    {
        $relation = $this->belongsToMany(Creator::class, 'campaign_creator')->withTimestamps();

        // Stamp the owner's tenant on attach()/sync() (ADR-0019). Template
        // instances (withCount/whereHas/eager-load) carry no tenant_id and
        // must not pin a NULL pivot value.
        return $this->tenant_id === null
            ? $relation
            : $relation->withPivotValue('tenant_id', $this->tenant_id);
    }

    /** @return HasMany<SeedingCampaign, $this> */
    public function seedingCampaigns(): HasMany
    {
        return $this->hasMany(SeedingCampaign::class);
    }

    /** @return HasMany<CommunicationLog, $this> */
    public function communicationLogs(): HasMany
    {
        return $this->hasMany(CommunicationLog::class);
    }

    /** @return HasMany<DocumentAttachment, $this> */
    public function documentAttachments(): HasMany
    {
        return $this->hasMany(DocumentAttachment::class);
    }

    /** @return HasMany<Task, $this> */
    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }
}
