<?php

namespace App\Modules\Monitoring\Models;

use App\Modules\CRM\Models\Campaign;
use App\Shared\Casts\AsValueObject;
use App\Shared\Enums\MentionType;
use App\Shared\Tenancy\BelongsToTenant;
use App\Shared\ValueObjects\ConfidenceAssessment;
use App\Shared\ValueObjects\Provenance;
use Database\Factories\MentionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ENT-Mention — a detected occurrence of a MonitoredSubject in content with
 * its paid/seeded/organic classification
 * (docs/30-data-model/00-data-model.md#ent-mention, REQ-M1-002).
 *
 * Write-owner: Module 1 Monitoring (ownership matrix). The classification is
 * inferred → mandatory ConfidenceAssessment envelope (DP-003); derived from
 * externally-sourced content → mandatory Provenance (DP-002).
 *
 * ENUM-MentionType rule: PAID/SEEDED only when a record or label proves it;
 * otherwise LIKELY_ORGANIC/UNKNOWN — organic is never asserted as fact and
 * no CONFIRMED_ORGANIC value exists (ADR-0008).
 *
 * Tenant-owned (ADR-0019): NOT NULL tenant_id, scoped and stamped via BelongsToTenant.
 *
 * @property int $id
 * @property int|null $tenant_id
 * @property int $monitored_subject_id
 * @property int|null $content_item_id
 * @property int|null $story_id
 * @property int|null $campaign_id
 * @property MentionType $mention_type
 * @property ConfidenceAssessment $classification
 * @property Provenance $provenance
 */
class Mention extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<MentionFactory> */
    use HasFactory;

    protected $fillable = [
        'monitored_subject_id',
        'content_item_id',
        'story_id',
        'campaign_id',
        'mention_type',
        'classification',
        'provenance',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'mention_type' => MentionType::class,
            'classification' => AsValueObject::class.':'.ConfidenceAssessment::class,
            'provenance' => AsValueObject::class.':'.Provenance::class,
        ];
    }

    /** @return BelongsTo<MonitoredSubject, $this> */
    public function monitoredSubject(): BelongsTo
    {
        return $this->belongsTo(MonitoredSubject::class);
    }

    /** @return BelongsTo<ContentItem, $this> */
    public function contentItem(): BelongsTo
    {
        return $this->belongsTo(ContentItem::class);
    }

    /** @return BelongsTo<Story, $this> */
    public function story(): BelongsTo
    {
        return $this->belongsTo(Story::class);
    }

    /** @return BelongsTo<Campaign, $this> */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }
}
