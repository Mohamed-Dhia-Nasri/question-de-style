<?php

namespace App\Modules\CRM\Models;

use App\Shared\Casts\AsValueObject;
use App\Shared\Enums\SeedingCampaignStatus;
use App\Shared\Enums\SeedingType;
use App\Shared\Tenancy\BelongsToTenant;
use App\Shared\ValueObjects\MetricValue;
use Database\Factories\SeedingCampaignFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * ENT-SeedingCampaign — a gifting/seeding program
 * (docs/30-data-model/00-data-model.md#ent-seedingcampaign, REQ-M3-006).
 *
 * Write-owner: Module 3 CRM (ownership matrix); M1 reads for reporting.
 * `seeding_type` records which of the four canonical variants applies
 * (module-3 §2.5, AC-M3-010); the organic variant never justifies
 * asserting a Mention PAID/SEEDED by itself (AC-M3-011). `product_id` is
 * the primary product only — the authoritative per-unit product lives on
 * each Shipment. creatorIds is the seeding_campaign_creator pivot.
 *
 * `spend` is the agency-entered amount spent (MetricValue envelope, tier
 * CONFIRMED, metric 'spend') — the CPE/CPM input (AC-M3-015). FLAGGED
 * DEVIATION (spec D1): no canonical ENT-SeedingCampaign field, awaiting a
 * data-model doc amendment.
 *
 * Tenant-owned (ADR-0019): NOT NULL tenant_id, scoped and stamped via BelongsToTenant.
 *
 * @property int $id
 * @property int|null $tenant_id
 * @property int|null $campaign_id
 * @property string $name
 * @property SeedingType $seeding_type
 * @property int $brand_id
 * @property int|null $product_id
 * @property SeedingCampaignStatus $status
 * @property MetricValue|null $spend
 */
class SeedingCampaign extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<SeedingCampaignFactory> */
    use HasFactory;

    protected $fillable = [
        'campaign_id',
        'name',
        'seeding_type',
        'brand_id',
        'product_id',
        'status',
        'spend',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'seeding_type' => SeedingType::class,
            'status' => SeedingCampaignStatus::class,
            'spend' => AsValueObject::class.':'.MetricValue::class,
        ];
    }

    /** @return BelongsTo<Campaign, $this> */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    /** @return BelongsTo<Brand, $this> */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return BelongsToMany<Creator, $this> */
    public function creators(): BelongsToMany
    {
        $relation = $this->belongsToMany(Creator::class, 'seeding_campaign_creator')->withTimestamps();

        // Stamp the owner's tenant on attach()/sync() (ADR-0019). Template
        // instances (withCount/whereHas/eager-load) carry no tenant_id and
        // must not pin a NULL pivot value.
        return $this->tenant_id === null
            ? $relation
            : $relation->withPivotValue('tenant_id', $this->tenant_id);
    }

    /** @return HasMany<Shipment, $this> */
    public function shipments(): HasMany
    {
        return $this->hasMany(Shipment::class);
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

    /** @return HasMany<CommunicationLog, $this> */
    public function communicationLogs(): HasMany
    {
        return $this->hasMany(CommunicationLog::class);
    }
}
