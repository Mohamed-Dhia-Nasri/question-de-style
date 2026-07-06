<?php

namespace App\Modules\Monitoring\Models;

use App\Modules\CRM\Models\Campaign;
use App\Modules\CRM\Models\Creator;
use App\Shared\Enums\MonitoredSubjectType;
use App\Shared\Enums\Platform;
use Database\Factories\MonitoredSubjectFactory;
use Illuminate\Database\Eloquent\Casts\AsEnumCollection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * ENT-MonitoredSubject — configuration of what Module 1 watches
 * (docs/30-data-model/00-data-model.md#ent-monitoredsubject, REQ-M1-001).
 *
 * Write-owner: Module 1 Monitoring (ownership matrix). v1 is roster-first
 * (ADR-0011): the active subject type is CREATOR referencing a tracked
 * creator; open-web term subjects (`terms`, BRAND/KEYWORD/HASHTAG/HANDLE)
 * are deferred (DEF-006) and render "unavailable".
 *
 * Internal configuration — not externally sourced, so no Provenance envelope.
 *
 * @property int $id
 * @property MonitoredSubjectType $subject_type
 * @property int|null $creator_id
 * @property Collection<int, Platform>|null $platforms
 * @property bool $active
 * @property-read Creator|null $creator
 */
class MonitoredSubject extends Model
{
    /** @use HasFactory<MonitoredSubjectFactory> */
    use HasFactory;

    protected $fillable = [
        'subject_type',
        'label',
        'creator_id',
        'terms',
        'platforms',
        'campaign_id',
        'active',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'subject_type' => MonitoredSubjectType::class,
            'terms' => 'array',
            'platforms' => AsEnumCollection::class.':'.Platform::class,
            'active' => 'boolean',
        ];
    }

    /** @return BelongsTo<Creator, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(Creator::class);
    }

    /** @return BelongsTo<Campaign, $this> */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    /** @return HasMany<Mention, $this> */
    public function mentions(): HasMany
    {
        return $this->hasMany(Mention::class);
    }
}
