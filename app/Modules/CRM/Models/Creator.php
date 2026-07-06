<?php

namespace App\Modules\CRM\Models;

use App\Modules\Monitoring\Models\MonitoredSubject;
use App\Shared\Enums\RelationshipStatus;
use Database\Factories\CreatorFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * ENT-Creator — the real-world influencer identity and system of record for
 * cross-platform merge (docs/30-data-model/00-data-model.md#ent-creator).
 *
 * Write-owner: Module 3 CRM (ownership matrix). Modules 1 and 2 read only
 * and propose new creators via the cross-module contract — they never write
 * this table. Merged accounts are the platformAccounts relation; creator
 * identity is never duplicated into other tables.
 */
class Creator extends Model
{
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
        return $this->belongsToMany(Campaign::class, 'campaign_creator')->withTimestamps();
    }

    /** @return BelongsToMany<SeedingCampaign, $this> */
    public function seedingCampaigns(): BelongsToMany
    {
        return $this->belongsToMany(SeedingCampaign::class, 'seeding_campaign_creator')->withTimestamps();
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
