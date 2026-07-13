<?php

namespace App\Modules\CRM\Models;

use App\Modules\Discovery\Models\GeoAttribution;
use App\Modules\Monitoring\Models\MonitoredSubject;
use App\Shared\Enums\RelationshipStatus;
use App\Shared\Tenancy\BelongsToTenant;
use Database\Factories\CreatorFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * ENT-Creator — the real-world influencer identity and system of record for
 * cross-platform merge (docs/30-data-model/00-data-model.md#ent-creator).
 *
 * Write-owner: Module 3 CRM (ownership matrix). Modules 1 and 2 read only
 * and propose new creators via the cross-module contract — they never write
 * this table. Merged accounts are the platformAccounts relation; creator
 * identity is never duplicated into other tables.
 *
 * Tenant-owned (ADR-0019): NOT NULL tenant_id, scoped and stamped via BelongsToTenant.
 *
 * @property int $id
 * @property int|null $tenant_id
 * @property string $display_name
 * @property string|null $primary_language
 * @property RelationshipStatus|null $relationship_status
 */
class Creator extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<CreatorFactory> */
    use HasFactory;

    protected $fillable = [
        'display_name',
        'primary_language',
        'relationship_status',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'relationship_status' => RelationshipStatus::class,
        ];
    }

    /**
     * The creator's operator-assigned geography (ADR-0018) — read-side
     * relation only; every WRITE goes through the M2-owned CreatorGeography
     * seam (ownership matrix: ENT-GeoAttribution belongs to Discovery).
     *
     * @return HasOne<GeoAttribution, $this>
     */
    public function geoAttribution(): HasOne
    {
        return $this->hasOne(GeoAttribution::class);
    }

    /** @return HasMany<PlatformAccount, $this> */
    public function platformAccounts(): HasMany
    {
        return $this->hasMany(PlatformAccount::class);
    }

    /** @return HasMany<MonitoredSubject, $this> */
    public function monitoredSubjects(): HasMany
    {
        return $this->hasMany(MonitoredSubject::class);
    }

    /** @return HasMany<Contact, $this> */
    public function contacts(): HasMany
    {
        return $this->hasMany(Contact::class);
    }

    /** @return HasMany<BrandPreference, $this> */
    public function brandPreferences(): HasMany
    {
        return $this->hasMany(BrandPreference::class);
    }

    /** @return BelongsToMany<Campaign, $this> */
    public function campaigns(): BelongsToMany
    {
        $relation = $this->belongsToMany(Campaign::class, 'campaign_creator')->withTimestamps();

        // Stamp the owner's tenant on attach()/sync() (ADR-0019). Template
        // instances (withCount/whereHas/eager-load) carry no tenant_id and
        // must not pin a NULL pivot value.
        return $this->tenant_id === null
            ? $relation
            : $relation->withPivotValue('tenant_id', $this->tenant_id);
    }

    /** @return BelongsToMany<SeedingCampaign, $this> */
    public function seedingCampaigns(): BelongsToMany
    {
        $relation = $this->belongsToMany(SeedingCampaign::class, 'seeding_campaign_creator')->withTimestamps();

        // Stamp the owner's tenant on attach()/sync() (ADR-0019); see campaigns().
        return $this->tenant_id === null
            ? $relation
            : $relation->withPivotValue('tenant_id', $this->tenant_id);
    }

    /** @return HasMany<Shipment, $this> */
    public function shipments(): HasMany
    {
        return $this->hasMany(Shipment::class);
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
