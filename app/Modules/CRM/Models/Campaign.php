<?php

namespace App\Modules\CRM\Models;

use App\Modules\Monitoring\Models\Mention;
use App\Modules\Monitoring\Models\MonitoredSubject;
use App\Shared\Enums\CampaignStatus;
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
 */
class Campaign extends Model
{
    /** @use HasFactory<CampaignFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'brand_id',
        'status',
        'start_at',
        'end_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => CampaignStatus::class,
            'start_at' => 'immutable_datetime',
            'end_at' => 'immutable_datetime',
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
        return $this->belongsToMany(Creator::class, 'campaign_creator')->withTimestamps();
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
